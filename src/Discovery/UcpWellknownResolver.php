<?php

declare(strict_types=1);

namespace Trusteed\Discovery;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed — UCP `.well-known` discovery resolver (ADR-077, PrestaShop).
 *
 * Builds the canonical CENTRAL per-store UCP discovery URL from configuration
 * the module already stores and exposes the opt-in gate. Per ADR-077 the
 * storefront NEVER duplicates the manifest body — the front controller merely
 * 302-redirects to:
 *
 *   {TRUSTEED_API_BASE}/.well-known/ucp?store={TRUSTEED_CEL_MERCHANT_ID}
 *
 * This class holds NO presentation/HTTP concerns so it can be unit-tested
 * without a live PrestaShop dispatcher. The front controller delegates here.
 *
 * @package Trusteed\Discovery
 */
class UcpWellknownResolver
{
    public const OPTION_ENABLED  = 'TRUSTEED_UCP_WELLKNOWN_ENABLED';
    public const OPTION_API_BASE = 'TRUSTEED_API_BASE';
    public const OPTION_MERCHANT = 'TRUSTEED_CEL_MERCHANT_ID';

    private const DEFAULT_API_BASE = 'https://api.trusteed.xyz';

    /**
     * Whether the merchant opted in to the discovery hop. Default OFF: any value
     * other than the literal '1' / 'true' keeps the route disabled (no redirect).
     */
    public static function isEnabled(int $shopId = 0): bool
    {
        $v = self::getConfig(self::OPTION_ENABLED, $shopId);

        return $v === '1' || $v === 'true';
    }

    /**
     * Build the central per-store UCP URL, or '' when not resolvable.
     *
     * Returns '' (fail-closed → 404, never a store-less redirect) when the
     * merchant id is not configured.
     */
    public static function targetUrl(int $shopId = 0): string
    {
        $apiBase    = trim(self::getConfig(self::OPTION_API_BASE, $shopId));
        $merchantId = trim(self::getConfig(self::OPTION_MERCHANT, $shopId));

        if ($apiBase === '') {
            $apiBase = self::DEFAULT_API_BASE;
        }

        if ($merchantId === '') {
            return '';
        }

        $base = rtrim($apiBase, '/') . '/.well-known/ucp';

        return $base . '?store=' . rawurlencode($merchantId);
    }

    /**
     * Retrieve a Configuration value with optional per-shop scope (multishop
     * aware, mirrors Enforcement\MerchantResolver).
     */
    private static function getConfig(string $key, int $shopId): string
    {
        if ($shopId > 0) {
            return (string) \Configuration::get($key, null, null, $shopId);
        }

        return (string) \Configuration::get($key);
    }
}
