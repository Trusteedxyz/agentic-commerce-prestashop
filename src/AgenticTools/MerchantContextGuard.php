<?php

declare(strict_types=1);

/**
 * MerchantContextGuard — prevents cross-shop IDOR (spec-046 H8).
 *
 * The payment tools receive `merchantId` in the agent-supplied payload. Without
 * a cross-check, an agent operating in shop A could pass shop B's merchantId and
 * dispatch a payment scoped to a tenant it does not control. This guard derives
 * the authoritative merchantId from the PrestaShop shop context (the same SSOT
 * the rest of the module uses — `TRUSTEED_CEL_MERCHANT_ID`, multishop-aware via
 * MerchantResolver) and rejects any input merchantId that does not match.
 *
 * Honesty note: the guard validates against the merchantId provisioned for the
 * ACTIVE shop. PS multishop scoping of an employee to multiple shops is resolved
 * at token-issuance time (AdminTrusteedController Gap 5 → allowed_shops[]); at
 * tool-execution time the relevant authority is the current shop's merchantId,
 * which is what the agent's call will settle against.
 *
 * The validation logic is split into a PURE method (`assertMatches`) that takes
 * the resolved authoritative id explicitly so it is unit-testable without a PS
 * kernel, plus a thin PS-runtime resolver (`authoritativeMerchantId`).
 */

namespace Trusteed\AgenticTools;

use Trusteed\Enforcement\MerchantResolver;

final class MerchantContextGuard
{
    /**
     * Resolves the merchantId configured for the active PS shop (multishop-aware).
     * Returns '' when none is provisioned.
     */
    public static function authoritativeMerchantId(): string
    {
        $shopId = 0;
        if (class_exists('\\Context')) {
            $ctx = \Context::getContext();
            $shopId = (int) ($ctx->shop->id ?? 0);
        }

        if (class_exists('Trusteed\\Enforcement\\MerchantResolver')) {
            return MerchantResolver::getMerchantId($shopId);
        }

        // Fallback: global configuration value.
        if (class_exists('\\Configuration')) {
            return (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        }

        return '';
    }

    /**
     * PURE — asserts the agent-supplied merchantId matches the authoritative one.
     *
     * Rules (fail-closed):
     *   - If no merchant is provisioned for this shop → merchant_not_provisioned.
     *   - If the input merchantId differs from the authoritative one → unauthorized
     *     (cross-shop IDOR attempt).
     *
     * Comparison is constant-time-ish via hash_equals to avoid leaking the
     * authoritative id through timing.
     *
     * @throws McpToolException
     */
    public static function assertMatches(string $inputMerchantId, string $authoritativeMerchantId): void
    {
        if ($authoritativeMerchantId === '') {
            throw new McpToolException(
                McpToolException::CODE_MERCHANT_NOT_PROVISIONED,
                'No Trusteed merchant is provisioned for the active shop'
            );
        }

        if ($inputMerchantId === '' || !hash_equals($authoritativeMerchantId, $inputMerchantId)) {
            throw new McpToolException(
                McpToolException::CODE_UNAUTHORIZED,
                'merchantId does not match the active shop; cross-shop access denied'
            );
        }
    }

    /**
     * Convenience: resolve the authoritative id from PS context and assert.
     *
     * @throws McpToolException
     */
    public static function enforce(string $inputMerchantId): void
    {
        self::assertMatches($inputMerchantId, self::authoritativeMerchantId());
    }
}
