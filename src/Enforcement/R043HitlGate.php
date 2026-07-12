<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Spec-048 Sprint E.2 T-E45 — PrestaShop R043 HITL Gate.
 *
 * Detects R043 HITL outcomes from /v1/rules/evaluate responses and freezes
 * the order. PrestaShop is invoked from `override/classes/PaymentModule.php`
 * (see F0C spike — `actionValidateOrderBefore` does not exist in PS 8.2 core,
 * so we rely on the PaymentModule override path).
 *
 * Freeze semantics:
 *   - Order created in OrderState `OS_PREPARATION` (or pending-payment configured)
 *     with `valid=0, is_paid=0` flags so payment is NOT captured. Merchant
 *     resolves via dashboard → status transitions to OS_PAYMENT or OS_CANCELED.
 *
 * Detection contract (matches buildHitlResponse in
 * packages/shared/src/enforcement/rule-evaluator.service.ts):
 *   - response.decision === 'BLOCK'
 *   - response.ucp.state === 'requires_escalation'
 *   - response.ucp.reason_code starts with 'trusteed:R043'
 *
 * Compuerta de carrito-congelado PS para R043 — bloquea captura pago hasta
 * resolución del comerciante.
 *
 * @since 1.6.0 (spec-048 Sprint E.2 T-E45)
 */
final class R043HitlGate
{
    public const R043_REASON_PREFIX = 'trusteed:R043';

    /**
     * Per-spec metadata fields stored on the Order via the cookie or
     * order-level extra fields when supported.
     */
    public const META_HITL_PENDING = '_amcp_hitl_pending';
    public const META_RULE_CODE = '_amcp_hitl_rule_code';
    public const META_REASON = '_amcp_hitl_reason';
    public const META_EVALUATION_ID = '_amcp_hitl_evaluation_id';

    /**
     * @param array<string,mixed>|object $response Decoded JSON from /v1/rules/evaluate.
     */
    public static function isHitlResponse($response): bool
    {
        $arr = self::asArray($response);
        if (!isset($arr['decision']) || $arr['decision'] !== 'BLOCK') {
            return false;
        }
        $ucp = self::asArray($arr['ucp'] ?? []);
        if (($ucp['state'] ?? '') !== 'requires_escalation') {
            return false;
        }
        $code = $ucp['reason_code'] ?? '';
        return is_string($code) && strncmp($code, self::R043_REASON_PREFIX, strlen(self::R043_REASON_PREFIX)) === 0;
    }

    /**
     * Extract canonical rule code without `trusteed:` prefix.
     *
     * @param array<string,mixed>|object $response
     */
    public static function ruleCodeFrom($response): string
    {
        $arr = self::asArray($response);
        $ucp = self::asArray($arr['ucp'] ?? []);
        $code = (string) ($ucp['reason_code'] ?? '');
        return ($code !== '' && strncmp($code, 'trusteed:', 9) === 0) ? substr($code, 9) : '';
    }

    /**
     * Build the payload dict consumed by the PaymentModule override to stamp
     * the new Order with HITL metadata and prevent payment capture.
     *
     * @param array<string,mixed>|object $response
     * @return array{rule_code:string,reason:string,evaluation_id:string,freeze:bool}
     */
    public static function buildFreezePayload($response): array
    {
        $arr = self::asArray($response);
        return [
            'rule_code' => self::ruleCodeFrom($response),
            'reason' => (string) ($arr['reason'] ?? ''),
            'evaluation_id' => (string) ($arr['evaluationId'] ?? ''),
            'freeze' => self::isHitlResponse($response),
        ];
    }

    /**
     * Returns the OrderState constant that signals "pending-merchant-approval".
     * Caller (PaymentModule override) uses it as the initial state of the
     * Order during validateOrder. PS exposes `Configuration::get('PS_OS_PREPARATION')`
     * out-of-the-box.
     */
    public static function getPendingOrderStateConfigKey(): string
    {
        return 'PS_OS_PREPARATION';
    }

    /**
     * Determines whether the freeze payload requires `is_paid=0, valid=0`
     * to be enforced. Returns true when `freeze=true`. Pure helper so the
     * PaymentModule override layer remains thin.
     *
     * @param array<string,mixed> $freezePayload
     */
    public static function requiresFreeze(array $freezePayload): bool
    {
        return !empty($freezePayload['freeze']);
    }

    /**
     * @param mixed $v
     * @return array<string,mixed>
     */
    private static function asArray($v): array
    {
        if (is_object($v)) {
            return get_object_vars($v);
        }
        return is_array($v) ? $v : [];
    }
}
