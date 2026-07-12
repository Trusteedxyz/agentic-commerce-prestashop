<?php

declare(strict_types=1);

/**
 * Spec 042 T042-082..088 — OrderEventsEmitter.
 *
 * Captura cambios de estado de pedido y emisión de credit slips desde
 * PrestaShop 8.x/9.x y los envía firmados al backend Trusteed como
 * `PlatformOrderEvent` con tipo `FULFILLED` o `REFUNDED`.
 *
 * Hooks soportados:
 *   - actionOrderStatusUpdate       → FULFILLED (states 4=shipped, 5=delivered),
 *                                     REFUNDED  (state 7), o payment_error (8)
 *   - actionObjectOrderSlipAddAfter → REFUNDED proxy (credit slip = refund)
 *     [R7 codex P2#2]: AddAfter en vez de AddBefore para que $slip->id sea > 0
 *     y para no emitir REFUNDED en creaciones fallidas.
 *
 * PrestaShop NO tiene modelo nativo de disputa/chargeback. Los refunds se
 * usan como proxy con `dataQuality='proxy'` y peso reducido en el score.
 *
 * Diseño:
 *   - Fire-and-forget: errores no rompen el flujo de admin.
 *   - jti determinístico UUID v5 (R5a codex P1#4): mismo (platform, merchantId,
 *     orderId, eventType, occurredAt-floor-1min) → mismo jti → retries
 *     deduplican en el backend de forma natural.
 *   - Payload firmado HMAC-SHA256 con `TRUSTEED_CEL_HMAC_SECRET`.
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
     * R5a (codex P1#4): MUST match across PrestaShop, Odoo, and backend so that
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
                return; // estado no mapeado → no-op
            }

            $order = new \Order($orderId);
            if (!\Validate::isLoadedObject($order)) {
                return;
            }

            $agentDid = $this->resolveCartAgentDid((int) $order->id_cart);
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
                    'dataQuality' => 'measured',
                    'agentDid' => $agentDid,
                ], fn($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            $this->logSafe('actionOrderStatusUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * R7 (codex P2#2): fires AFTER the OrderSlip is persisted so $slip->id > 0,
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

            // Gate adicional: slip must be persisted (id > 0).
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
                    // PrestaShop no tiene dispute nativo → refunds = proxy.
                    'dataQuality' => 'proxy',
                    'subtype' => 'order_slip',
                    'agentDid' => $agentDid,
                ], fn($v) => $v !== null),
            );
        } catch (\Throwable $e) {
            $this->logSafe('actionObjectOrderSlipAddAfter failed: ' . $e->getMessage());
        }
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
            // payment_error no genera fulfillment/refund event; sólo telemetría
            // genérica en otra ruta. Devuelvo null para no emitir aquí.
            default => null,
        };
    }

    /**
     * R15 (codex P3#1): walks the history (descending: newest first), finds the
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
        // Gap 2: prefer canonical key, fall back to legacy for backward compat.
        $bootstrap = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($bootstrap === '') {
            $bootstrap = (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }
        $hmacSecret = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $merchantId = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');

        if ($apiUrl === '' || $bootstrap === '' || $hmacSecret === '' || $merchantId === '') {
            // Gap 7 fix: surface misconfiguration explicitly so audits can detect
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
        // R5a (codex P1#4): deterministic jti so retries dedup naturally backend-side.
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
     * R1 (codex P1#1): use RFC 8785 JCS for cross-language byte-equal canonicalization.
     */
    private function payloadHashCanonical(array $payload): string
    {
        $canonical = JcsCanonicalize::canonicalize($payload);
        return 'sha256:' . hash('sha256', $canonical);
    }

    /**
     * @param array<string,mixed> $body
     *
     * R1 (codex P1#1): sign over RFC 8785 JCS bytes (body minus signature).
     */
    private function signHmac(array $body, string $secret): string
    {
        unset($body['signature']);
        $canonical = JcsCanonicalize::canonicalize($body);
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * R5a (codex P1#4): deterministic jti via UUID v5 (RFC 4122 §4.3) so the
     * same logical event (same minute) always produces the same identifier.
     * Retries from PrestaShop, queue replays, and parallel emitters all
     * converge on the same jti → backend idempotency works naturally.
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
