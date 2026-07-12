<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Spec-048 P0b — PrestaShop checkout-failure emitter.
 *
 * PrestaShop does not expose a single canonical payment_failed hook. We
 * hook two complementary signals:
 *
 *   - `actionPaymentCCAdd`     — fires when a payment record is added;
 *     payload includes status / errors when capture fails for credit-card
 *     flows.
 *   - `displayPaymentReturn`   — fires when buyer returns from off-site
 *     PSP; we inspect `params['objOrder']->getCurrentState()` against
 *     payment-error states.
 *
 * Both paths POST a HMAC-signed payload to `/api/v1/checkout-failures`
 * fire-and-forget so the buyer flow is never blocked.
 *
 * @package Trusteed\Enforcement
 */
class PaymentFailureEmitter
{
    private const ENDPOINT_PATH = '/api/v1/checkout-failures';

    public static function register(\Trusteed $module): void
    {
        $module->registerHook('actionPaymentCCAdd');
        $module->registerHook('displayPaymentReturn');
    }

    public static function unregister(\Trusteed $module): void
    {
        $module->unregisterHook('actionPaymentCCAdd');
        $module->unregisterHook('displayPaymentReturn');
    }

    /**
     * Hook entry: actionPaymentCCAdd. `$params` contains the OrderPayment
     * object and the parent Order. When the payment amount is zero or the
     * transaction id is missing AND the order state is payment-error,
     * we treat it as a payment failure.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionPaymentCCAdd(array $params): void
    {
        try {
            $payment = $params['paymentCC'] ?? $params['payment'] ?? null;
            $order   = $params['order'] ?? null;

            if (!($order instanceof \Order)) {
                return;
            }

            $stateId = (int) $order->getCurrentState();
            if (!self::isPaymentErrorState($stateId)) {
                return;
            }

            $reason = 'payment_failed';
            // PS OrderPayment may expose a `transaction_id` or an `amount` of 0
            // when capture fails. We treat both as payment_failed (no finer
            // mapping available cross-module).
            if ($payment !== null && is_object($payment)) {
                $txId = isset($payment->transaction_id) ? (string) $payment->transaction_id : '';
                if ($txId === '') {
                    $reason = 'gateway_error';
                }
            }

            self::emit($order, $reason);
        } catch (\Throwable $e) {
            self::safeLog('actionPaymentCCAdd emit exception: ' . $e->getMessage());
        }
    }

    /**
     * Hook entry: displayPaymentReturn. Module return URL — when state is
     * payment-error we emit. Always returns empty string (display hooks
     * must not throw and must return a string).
     *
     * @param array<string,mixed> $params
     */
    public function hookDisplayPaymentReturn(array $params): string
    {
        try {
            $order = $params['objOrder'] ?? $params['order'] ?? null;
            if ($order instanceof \Order) {
                $stateId = (int) $order->getCurrentState();
                if (self::isPaymentErrorState($stateId)) {
                    self::emit($order, 'payment_declined');
                }
            }
        } catch (\Throwable $e) {
            self::safeLog('displayPaymentReturn emit exception: ' . $e->getMessage());
        }
        return '';
    }

    private static function isPaymentErrorState(int $stateId): bool
    {
        // PS_OS_ERROR is the canonical payment_error state. PS_OS_CANCELED
        // covers buyer/agent cancellation of a payment flow.
        $error    = (int) \Configuration::get('PS_OS_ERROR');
        $canceled = (int) \Configuration::get('PS_OS_CANCELED');
        return $stateId === $error || $stateId === $canceled;
    }

    private static function emit(\Order $order, string $reason): void
    {
        $merchantId     = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');
        $hmacSecret     = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $apiBase        = (string) \Configuration::get('TRUSTEED_API_BASE');

        if ($merchantId === '' || $installationId === '' || $hmacSecret === '' || $apiBase === '') {
            return; // not configured
        }

        $agentDid = self::resolveAgentDidFromOrder($order, $merchantId);

        $body = [
            'installationId' => $installationId,
            'merchantId'     => $merchantId,
            'platform'       => 'prestashop',
            'reason'         => $reason,
        ];
        if ($agentDid !== null) {
            $body['agentDid'] = $agentDid;
        }
        // Spec-048 T14 — idempotency key. PS can fire actionPaymentCCAdd
        // multiple times on retried captures; emit `external_ref=order.id`
        // so the backend partial unique index drops duplicates.
        $orderId = (int) ($order->id ?? 0);
        if ($orderId > 0) {
            $body['externalRef'] = (string) $orderId;
        }

        $signature = self::hmacSign($body, $hmacSecret);
        $body['signature'] = $signature;

        $url     = rtrim($apiBase, '/') . self::ENDPOINT_PATH;
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Fire-and-forget cURL POST with a tight timeout. PS environments
        // commonly disable wp_remote_post / Symfony HTTP; cURL is portable.
        if (!function_exists('curl_init') || $payload === false) {
            return;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Trusteed-PS/1.0 spec-048-p0b',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 800);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        @curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            self::safeLog('emit curl error: ' . $err);
        }
    }

    /**
     * Try to recover agentDid from CartAgentStorage (R023 lookup table) if
     * available — populated when an agentic cart is created.
     */
    private static function resolveAgentDidFromOrder(\Order $order, string $merchantId): ?string
    {
        $cartId = (int) ($order->id_cart ?? 0);
        if ($cartId === 0) return null;
        if (class_exists('Trusteed\\Storage\\CartAgentStorage')) {
            try {
                // C2 fix: CartAgentStorage exposes get()/set()/delete() — there is
                // no resolveAgentDid(). The prior call raised a fatal that the
                // try/catch swallowed, so agentDid was ALWAYS null (silent
                // fail-open). get() returns the stored agentDid (?string).
                $did = \Trusteed\Storage\CartAgentStorage::get($cartId);
                if (is_string($did) && $did !== '') {
                    return $did;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return null;
    }

    private static function hmacSign(array $body, string $secret): string
    {
        $sorted    = self::recursiveKsort($body);
        $canonical = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', (string) $canonical, $secret);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function recursiveKsort($value)
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $k => $v) {
                $value[$k] = self::recursiveKsort($v);
            }
        }
        return $value;
    }

    private static function safeLog(string $message): void
    {
        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog('[spec-048 P0b] ' . $message, 1);
        }
    }
}
