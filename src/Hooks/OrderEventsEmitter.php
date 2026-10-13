<?php

declare(strict_types=1);

/**
 * OrderEventsEmitter.
 *
 * Captures order state changes and credit slip creation in PrestaShop 8.x/9.x
 * and sends them, signed, to the Trusteed backend as `PlatformOrderEvent`
 * with type `FULFILLED` or `REFUNDED`.
 *
 * Supported hooks:
 *   - actionOrderStatusUpdate       -> FULFILLED (states 4=shipped, 5=delivered),
 *                                      REFUNDED  (state 7), or payment_error (8)
 *   - actionObjectOrderSlipAddAfter -> REFUNDED proxy (credit slip = refund).
 *     AddAfter rather than AddBefore so that $slip->id is > 0 and failed
 *     creations do not emit REFUNDED.
 *
 * PrestaShop has no native dispute/chargeback model. Refunds are used as a
 * proxy with `dataQuality='proxy'` and reduced weight in the score.
 *
 * Design:
 *   - Fire-and-forget: errors do not break the admin flow.
 *   - Deterministic UUID v5 jti: the same (platform, merchantId, orderId,
 *     eventType, occurredAt floored to 1 minute) gives the same jti, so
 *     retries deduplicate naturally in the backend.
 *   - Payload signed with HMAC-SHA256 using `TRUSTEED_CEL_HMAC_SECRET`.
 */

namespace Trusteed\Hooks;

use Trusteed\AgenticTools\BackendApiClient;
use Trusteed\Hmac\JcsCanonicalize;

final class OrderEventsEmitter
{
    public const STATE_SHIPPED = 4;
    public const STATE_DELIVERED = 5;
    public const STATE_REFUNDED = 7;
    public const STATE_PAYMENT_ERROR = 8;

    /**
     * Custom DNS-style namespace UUID for Trusteed deterministic jti generation.
     *
     * MUST match across PrestaShop, Odoo, and backend so that
     * the same logical event produces the same UUID v5 regardless of emitter.
     */
    private const TRUSTEED_NAMESPACE_UUID = '6ba7b815-9dad-11d1-80b4-00c04fd430c8';

    /** Module instance (for hook registration). */
    public static function register(\Module $module): void
    {
        $module->registerHook('actionOrderStatusUpdate');
        $module->registerHook('actionObjectOrderSlipAddAfter');
    }

    public static function unregister(\Module $module): void
    {
        $module->unregisterHook('actionOrderStatusUpdate');
        $module->unregisterHook('actionObjectOrderSlipAddAfter');
    }

    /**
     * @param array{newOrderStatus: \OrderState|null, id_order: int|string} $params
     */
    public function hookActionOrderStatusUpdate(array $params): void
    {
        try {
            $newState = $params['newOrderStatus'] ?? null;
            $orderId = isset($params['id_order']) ? (int) $params['id_order'] : 0;

            if (!$newState instanceof \OrderState || $orderId <= 0) {
                return;
            }

            $stateId = (int) $newState->id;
            $eventType = $this->mapStateToEventType($stateId);

            if ($eventType === null) {
                return; // unmapped state: no-op
            }

            $order = new \Order($orderId);
            if (!\Validate::isLoadedObject($order)) {
                return;
            }

            $agentDid = $this->resolveCartAgentDid((int) $order->id_cart);
            // Shipping latency is measured with the STORE's own clocks. If the
            // event carried no shipping time, the server would create the order
            // row at that same instant and measure about 0 h, which would look
            // like 100% on-time by construction. Without a reliable shipping
            // time the data quality is `unknown`, never `measured`.
            $timing = $eventType === 'FULFILLED'
                ? $this->computeFulfillmentTiming(
                    $order->date_add,
                    $this->resolveShippedAt($order, $stateId),
                    date_default_timezone_get()
                )
                : ['dataQuality' => 'measured'];
            $this->emitEvent(
                eventType: $eventType,
                orderId: (string) $order->id,
                payload: array_filter([
                    'externalOrderId' => (string) $order->id,
                    'reference' => (string) $order->reference,
                    'previousStateId' => $this->resolvePreviousStateId($order, $stateId),
                    'newStateId' => $stateId,
                    'totalPaid' => (float) $order->total_paid,
                    'currency' => $this->resolveCurrencyIso($order),
                    'dateAdd' => $order->date_add,
                    'dateUpd' => $order->date_upd,
                    'agentDid' => $agentDid,
                ], fn($v) => $v !== null) + $timing,
            );
        } catch (\Throwable $e) {
            $this->logSafe('actionOrderStatusUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * Fires AFTER the OrderSlip is persisted so $slip->id > 0,
     * and failed slip creations do NOT emit a spurious REFUNDED event.
     *
     * @param array{object: \OrderSlip|null} $params
     */
    public function hookActionObjectOrderSlipAddAfter(array $params): void
    {
        try {
            $slip = $params['object'] ?? null;
            if (!$slip instanceof \OrderSlip) {
                return;
            }

            // Extra gate: slip must be persisted (id > 0).
            if ((int) $slip->id <= 0) {
                return;
            }

            $orderId = (int) $slip->id_order;
            if ($orderId <= 0) {
                return;
            }

            $order = new \Order($orderId);
            if (!\Validate::isLoadedObject($order)) {
                return;
            }

            $agentDid = $this->resolveCartAgentDid((int) $order->id_cart);
            $this->emitEvent(
                eventType: 'REFUNDED',
                orderId: (string) $order->id,
                payload: array_filter([
                    'externalOrderId' => (string) $order->id,
                    'reference' => (string) $order->reference,
                    'slipId' => (int) $slip->id,
                    'amount' => (float) $slip->amount,
                    'shippingCost' => (float) $slip->shipping_cost_amount,
                    'currency' => $this->resolveCurrencyIso($order),
                    'dateAdd' => $slip->date_add ?? $order->date_upd,
                    // PrestaShop has no native dispute, so refunds are a proxy.
                    'dataQuality' => 'proxy',
                    'subtype' => 'order_slip',
                    'agentDid' => $agentDid,
                ], fn($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            $this->logSafe('actionObjectOrderSlipAddAfter failed: ' . $e->getMessage());
        }
    }

    /**
     * Shipping time in the store's time zone (`Y-m-d H:i:s`), or null.
     *
     * - "Shipped" state: the hook fires on the change to that state, so the
     *   shipping time is now.
     * - "Delivered" state: the time of the "shipped" step in the history. If
     *   the order jumped straight to delivered there is no shipping time: the
     *   delivery time is not one, and using it would inflate the latency.
     */
    private function resolveShippedAt(\Order $order, int $stateId): ?string
    {
        if ($stateId === self::STATE_SHIPPED) {
            return date('Y-m-d H:i:s');
        }
        try {
            $history = $order->getHistory((int) \Context::getContext()->language->id);
        } catch (\Throwable) {
            return null;
        }
        return is_array($history) ? $this->pickShippedAtFromHistory($history) : null;
    }

    /**
     * @param array<int, mixed> $history entries from `Order::getHistory()`
     */
    private function pickShippedAtFromHistory(array $history): ?string
    {
        $shippedAt = null;
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['id_order_state'], $entry['date_add'])) {
                continue;
            }
            if ((int) $entry['id_order_state'] !== self::STATE_SHIPPED) {
                continue;
            }
            $date = (string) $entry['date_add'];
            // The FIRST time it shipped: the history can repeat the state.
            if ($shippedAt === null || strcmp($date, $shippedAt) < 0) {
                $shippedAt = $date;
            }
        }
        return $shippedAt;
    }

    /**
     * Shipping latency using the store's two clocks.
     *
     * Both dates arrive as `Y-m-d H:i:s` without a zone (as PrestaShop stores
     * them) and are interpreted in the store's time zone. Any input that
     * prevents measuring (missing or unreadable date, the MySQL zero date, or
     * a shipment earlier than the order) returns `unknown` and nothing else:
     * "not measured" is better than an invented number.
     *
     * @return array{dataQuality: string, hoursToFulfill?: float, shippedAt?: string}
     */
    private function computeFulfillmentTiming(?string $orderDateAdd, ?string $shippedAtLocal, string $timezone): array
    {
        $unknown = ['dataQuality' => 'unknown'];
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return $unknown;
        }
        $created = $this->parseShopDate($orderDateAdd, $tz);
        $shipped = $this->parseShopDate($shippedAtLocal, $tz);
        if ($created === null || $shipped === null) {
            return $unknown;
        }
        $seconds = $shipped->getTimestamp() - $created->getTimestamp();
        if ($seconds < 0) {
            return $unknown;
        }
        return [
            'dataQuality' => 'measured',
            'hoursToFulfill' => round($seconds / 3600, 2),
            'shippedAt' => $shipped->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseShopDate(?string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        return $parsed === false ? null : $parsed;
    }

    /** Resolve agentDid from CartAgentStorage if available (fail-open). */
    private function resolveCartAgentDid(int $cartId): ?string
    {
        if ($cartId <= 0 || !class_exists('Trusteed\\Storage\\CartAgentStorage')) {
            return null;
        }
        try {
            return \Trusteed\Storage\CartAgentStorage::get($cartId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapStateToEventType(int $stateId): ?string
    {
        return match ($stateId) {
            self::STATE_SHIPPED, self::STATE_DELIVERED => 'FULFILLED',
            self::STATE_REFUNDED => 'REFUNDED',
            // payment_error produces no fulfillment/refund event (only generic
            // telemetry elsewhere), so return null to emit nothing here.
            default => null,
        };
    }

    /**
     * Walks the history (descending: newest first), finds the
     * first entry matching $newStateId, then returns the next entry whose state
     * differs from $newStateId. This avoids returning $newStateId itself when
     * history contains duplicates and avoids ignoring $newStateId entirely.
     */
    private function resolvePreviousStateId(\Order $order, int $newStateId): ?int
    {
        $history = $order->getHistory((int) \Context::getContext()->language->id);
        if (!is_array($history) || count($history) === 0) {
            return null;
        }

        $foundNew = false;
        foreach ($history as $entry) {
            if (!is_array($entry) || !isset($entry['id_order_state'])) {
                continue;
            }
            $entryState = (int) $entry['id_order_state'];

            if (!$foundNew && $entryState === $newStateId) {
                $foundNew = true;
                continue;
            }
            if ($foundNew && $entryState !== $newStateId) {
                return $entryState;
            }
        }

        return null;
    }

    private function resolveCurrencyIso(\Order $order): string
    {
        try {
            $currency = new \Currency((int) $order->id_currency);
            return \Validate::isLoadedObject($currency)
                ? (string) $currency->iso_code
                : 'EUR';
        } catch (\Throwable) {
            return 'EUR';
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function emitEvent(string $eventType, string $orderId, array $payload): void
    {
        $apiUrl = (string) \Configuration::get('TRUSTEED_API_BASE');
        // Prefer canonical key, fall back to legacy for backward compat.
        $bootstrap = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($bootstrap === '') {
            $bootstrap = (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }
        $hmacSecret = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $merchantId = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');

        if ($apiUrl === '' || $bootstrap === '' || $hmacSecret === '' || $merchantId === '') {
            // Surface misconfiguration explicitly so audits can detect
            // "module installed but webhooks inert" without parsing absence of logs.
            // Logged once per emit attempt (PrestaShop ringbuffers by message).
            $missing = [];
            if ($apiUrl === '')      { $missing[] = 'TRUSTEED_API_BASE'; }
            if ($bootstrap === '')   { $missing[] = 'TRUSTEED_EMBED_S2S_SECRET'; }
            if ($hmacSecret === '')  { $missing[] = 'TRUSTEED_CEL_HMAC_SECRET'; }
            if ($merchantId === '')  { $missing[] = 'TRUSTEED_CEL_MERCHANT_ID'; }
            \PrestaShopLogger::addLog(
                sprintf(
                    'OrderEventsEmitter: skipping %s for order %s — missing config: %s',
                    $eventType,
                    $orderId,
                    implode(', ', $missing)
                ),
                2 // severity: warning
            );
            return;
        }

        $occurredAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        // Deterministic jti so retries dedup naturally backend-side.
        $jti = $this->generateDeterministicJti('PRESTASHOP', $merchantId, $orderId, $eventType, $occurredAt);
        $body = [
            'jti' => $jti,
            'merchantId' => $merchantId,
            'installationId' => $installationId,
            'platform' => 'PRESTASHOP',
            'eventType' => $eventType,
            'orderId' => $orderId,
            'occurredAt' => $occurredAt,
            'payloadHash' => $this->payloadHashCanonical($payload),
            'payload' => $payload,
        ];

        $client = new BackendApiClient($apiUrl, $bootstrap);
        try {
            $client->call(
                endpoint: '/api/v1/ucp/webhooks/prestashop/order-events',
                payload: $body + [
                    'signature' => $this->signHmac($body, $hmacSecret),
                ],
                idempotencyKey: $jti,
            );
        } catch (\Throwable $e) {
            $this->logSafe('order event emit failed (' . $eventType . '): ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $payload
     *
     * Uses RFC 8785 JCS for cross-language byte-equal canonicalization.
     */
    private function payloadHashCanonical(array $payload): string
    {
        $canonical = JcsCanonicalize::canonicalize($payload);
        return 'sha256:' . hash('sha256', $canonical);
    }

    /**
     * @param array<string,mixed> $body
     *
     * Signs over RFC 8785 JCS bytes (body minus signature).
     */
    private function signHmac(array $body, string $secret): string
    {
        unset($body['signature']);
        $canonical = JcsCanonicalize::canonicalize($body);
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Deterministic jti via UUID v5 (RFC 4122 §4.3) so the
     * same logical event (same minute) always produces the same identifier.
     * Retries from PrestaShop, queue replays, and parallel emitters all
     * converge on the same jti, so backend idempotency works naturally.
     */
    private function generateDeterministicJti(
        string $platform,
        string $merchantId,
        string $orderId,
        string $eventType,
        string $occurredAt
    ): string {
        $occurredFloor = $this->floorToMinute($occurredAt);
        $name = sprintf('%s|%s|%s|%s|%s', $platform, $merchantId, $orderId, $eventType, $occurredFloor);
        return $this->uuidV5(self::TRUSTEED_NAMESPACE_UUID, $name);
    }

    /**
     * UUID v5 (SHA-1, RFC 4122 §4.3).
     */
    private function uuidV5(string $namespace, string $name): string
    {
        $nhex = str_replace(['-', '{', '}'], '', $namespace);
        $nbytes = '';
        for ($i = 0; $i < strlen($nhex); $i += 2) {
            $nbytes .= chr(hexdec(substr($nhex, $i, 2)));
        }
        $hash = sha1($nbytes . $name);
        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,  // version 5
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,  // variant 10xx
            substr($hash, 20, 12)
        );
    }

    /**
     * Floor an ISO-8601 timestamp to the start of its minute (UTC, Z-suffixed).
     */
    private function floorToMinute(string $iso): string
    {
        $dt = new \DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0)
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function logSafe(string $message): void
    {
        if (class_exists('\\PrestaShopLogger')) {
            \PrestaShopLogger::addLog('[trusteed/OrderEventsEmitter] ' . $message, 2, null, 'Module', 0, true);
        }
    }
}
