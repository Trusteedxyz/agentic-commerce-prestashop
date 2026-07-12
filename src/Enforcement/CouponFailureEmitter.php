<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Spec-048 G1 — PrestaShop coupon-attempt-failed emitter.
 *
 * PrestaShop does not expose a dedicated "coupon rejected" hook. We hook
 * `actionValidateOrder` and inspect `Cart::getCartRules()` against the
 * configured CartRule objects to detect which rules were submitted but
 * rejected (typically discovered via `CartRule::checkValidity()`).
 *
 * For real-time detection (recommended), the module also overrides
 * `Controllers/Front/CartController::processSubmitDiscount()` to emit
 * immediately when a discount code is submitted and fails validation.
 * The hook path is the fallback / forensic record (catches PSP-induced
 * rejections post-validateOrder).
 *
 * Both paths POST a HMAC-signed payload to `/api/v1/coupon-attempts-failed`
 * fire-and-forget so the buyer flow is never blocked. Mirrors
 * PaymentFailureEmitter.php (same HMAC scheme, same Configuration keys).
 *
 * @package Trusteed\Enforcement
 */
class CouponFailureEmitter
{
    private const ENDPOINT_PATH = '/api/v1/coupon-attempts-failed';

    public static function register(\Trusteed $module): void
    {
        $module->registerHook('actionValidateOrder');
    }

    public static function unregister(\Trusteed $module): void
    {
        $module->unregisterHook('actionValidateOrder');
    }

    /**
     * Hook entry: actionValidateOrder. Inspect cart rules that were attached
     * but failed CartRule::checkValidity(). PrestaShop attaches all
     * submitted rules but flags rejected ones via validity check.
     *
     * @param array<string,mixed> $params
     */
    public function hookActionValidateOrder(array $params): void
    {
        try {
            $cart = $params['cart'] ?? null;
            if (!($cart instanceof \Cart)) {
                return;
            }
            $rules = $cart->getCartRules();
            if (!is_array($rules) || count($rules) === 0) {
                return;
            }
            foreach ($rules as $row) {
                $cartRule = isset($row['id_cart_rule'])
                    ? new \CartRule((int) $row['id_cart_rule'])
                    : null;
                if ($cartRule === null || !\Validate::isLoadedObject($cartRule)) {
                    continue;
                }
                // CartRule::checkValidity returns true if valid, error string
                // otherwise. We only emit on rejection.
                $check = $cartRule->checkValidity(\Context::getContext(), false, false);
                if ($check === true) {
                    continue;
                }
                $code   = isset($cartRule->code) ? (string) $cartRule->code : '';
                $reason = self::mapReason(is_string($check) ? strtolower($check) : '');
                if ($code === '') continue;
                self::emit($cart, strtolower($code), $reason);
            }
        } catch (\Throwable $e) {
            self::safeLog('hookActionValidateOrder exception: ' . $e->getMessage());
        }
    }

    private static function mapReason(string $errMsg): string
    {
        if (strpos($errMsg, 'expir') !== false) return 'expired';
        if (strpos($errMsg, 'quantity') !== false || strpos($errMsg, 'limit') !== false) {
            return 'limit_reached';
        }
        if (strpos($errMsg, 'minimum') !== false || strpos($errMsg, 'min ') !== false) {
            return 'min_not_met';
        }
        return 'not_found';
    }

    private static function emit(\Cart $cart, string $couponCode, string $reason): void
    {
        $merchantId     = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');
        $hmacSecret     = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $apiBase        = (string) \Configuration::get('TRUSTEED_API_BASE');

        if ($merchantId === '' || $installationId === '' || $hmacSecret === '' || $apiBase === '') {
            return; // not configured
        }

        $agentDid = self::resolveAgentDidFromCart($cart);

        $body = [
            'installationId' => $installationId,
            'merchantId'     => $merchantId,
            'platform'       => 'prestashop',
            'couponCode'     => $couponCode,
            'reason'         => $reason,
        ];
        if ($agentDid !== null) {
            $body['agentDid'] = $agentDid;
        }

        $signature = self::hmacSign($body, $hmacSecret);
        $body['signature'] = $signature;

        $url     = rtrim($apiBase, '/') . self::ENDPOINT_PATH;
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            'User-Agent: Trusteed-PS/1.0 spec-048-g1',
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

    private static function resolveAgentDidFromCart(\Cart $cart): ?string
    {
        $cartId = (int) ($cart->id ?? 0);
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
            \PrestaShopLogger::addLog('[spec-048 G1] ' . $message, 1);
        }
    }
}
