<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed CEL Merchant Resolver for PrestaShop.
 *
 * Resolves CEL configuration values in a multishop-aware manner.
 * Each shop in a multishop group can have its own merchantId, installationId,
 * and HMAC secret via Configuration::get with a shop context.
 *
 * Pattern inherits from spec-042 multishop ACL implementation.
 *
 * @package Trusteed\Enforcement
 */
class MerchantResolver
{
    /**
     * Get the Trusteed merchant ID for the given shop (or global if $shopId=0).
     */
    public static function getMerchantId(int $shopId = 0): string
    {
        return self::getConfig('TRUSTEED_CEL_MERCHANT_ID', $shopId);
    }

    /**
     * Get the Trusteed CEL installation ID for the given shop.
     */
    public static function getInstallationId(int $shopId = 0): string
    {
        return self::getConfig('TRUSTEED_CEL_INSTALLATION_ID', $shopId);
    }

    /**
     * Get the Trusteed CEL HMAC secret for the given shop.
     * Raw value — not sanitized for binary secrets.
     */
    public static function getHmacSecret(int $shopId = 0): string
    {
        return self::getConfig('TRUSTEED_CEL_HMAC_SECRET', $shopId);
    }

    /**
     * Get the Trusteed fallback mode (strict|balanced|permissive).
     *
     * Sprint C T13: default flipped from 'balanced' to **'strict'**. When the
     * Layer-2 rules-evaluate endpoint is unavailable AND no fresh local
     * snapshot cache is present, the module must fail-closed for ENFORCE-mode
     * rules instead of silently fail-open (mass fail-open documented in
     * `docs/analysis/rules_claude.md` §4.1 / §6.2).
     *
     * Merchant overrides remain honored: explicit 'balanced' / 'permissive'
     * stays as configured (warn-logged at evaluation time).
     */
    public static function getFallbackMode(int $shopId = 0): string
    {
        $mode = self::getConfig('TRUSTEED_CEL_FALLBACK_MODE', $shopId);

        if (!in_array($mode, ['strict', 'balanced', 'permissive'], true)) {
            return 'strict';
        }

        return $mode;
    }

    /**
     * Check whether CEL enforcement is enabled for the given shop.
     */
    public static function isEnabled(int $shopId = 0): bool
    {
        $v = self::getConfig('TRUSTEED_CEL_ENABLED', $shopId);

        return $v === '1' || $v === 'true';
    }

    /**
     * Retrieve a Configuration value with optional per-shop scope.
     *
     * When $shopId > 0, passes null group context and the shop ID so that
     * PS Configuration::get resolves the shop-level override first, then
     * falls back to global.
     */
    private static function getConfig(string $key, int $shopId): string
    {
        if ($shopId > 0) {
            return (string) \Configuration::get($key, null, null, $shopId);
        }

        return (string) \Configuration::get($key);
    }

    /**
     * Return the current context shop ID (0 when not in shop context).
     */
    public static function currentShopId(): int
    {
        if (isset(\Context::getContext()->shop) && \Context::getContext()->shop instanceof \Shop) {
            return (int) \Context::getContext()->shop->id;
        }

        return 0;
    }

    /**
     * Build a SnapshotClient for the current or given shop.
     * Returns null when required configuration is missing or CEL is disabled.
     */
    public static function buildSnapshotClient(int $shopId = 0): ?SnapshotClient
    {
        if (!self::isEnabled($shopId)) {
            return null;
        }

        $merchantId     = self::getMerchantId($shopId);
        $installationId = self::getInstallationId($shopId);
        $hmacSecret     = self::getHmacSecret($shopId);
        $apiBase        = (string) \Configuration::get('TRUSTEED_API_BASE');

        if ($apiBase === '') {
            $apiBase = 'https://api.trusteed.xyz';
        }

        if ($merchantId === '' || $installationId === '' || $hmacSecret === '') {
            return null;
        }

        return new SnapshotClient($apiBase, $merchantId, $installationId, $hmacSecret);
    }
}
