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
 * storefront NEVER builds or edits the manifest body — it relays the body the
 * central endpoint generates, from the merchant's own origin (a 302 to another
 * host is not followed by every UCP client). The central URL is:
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
    public const OPTION_CACHE    = 'TRUSTEED_UCP_WELLKNOWN_CACHE';

    public const FRESH_TTL = 300;
    public const STALE_TTL = 86400;

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
     * Relay the centrally generated profile's JSON body, or null.
     *
     * Cached 5 min in Configuration; a stale copy (up to 24 h) covers a central
     * outage. Only https targets whose host does not resolve to a private or
     * reserved address are requested (api_base is admin-editable). `$http` is
     * injectable for tests: callable(string $url): array{code:int, body:string}.
     *
     * @param callable|null $http
     */
    public static function fetchBody(string $target, int $shopId = 0, ?callable $http = null, ?int $now = null): ?string
    {
        $now = $now ?? time();

        $cached = json_decode(self::getConfig(self::OPTION_CACHE, $shopId), true);
        $valid  = is_array($cached)
            && ($cached['target'] ?? null) === $target
            && isset($cached['body'], $cached['at'])
            && is_string($cached['body']);
        if ($valid && $now - (int) $cached['at'] < self::FRESH_TTL) {
            return $cached['body'];
        }

        if (self::isFetchableTarget($target)) {
            $res  = ($http ?? [self::class, 'httpGet'])($target);
            $body = (string) ($res['body'] ?? '');
            if ((int) ($res['code'] ?? 0) === 200 && json_decode($body) !== null) {
                \Configuration::updateValue(
                    self::OPTION_CACHE,
                    (string) json_encode(['target' => $target, 'body' => $body, 'at' => $now]),
                    false,
                    null,
                    $shopId > 0 ? $shopId : null
                );

                return $body;
            }
        }

        return ($valid && $now - (int) $cached['at'] < self::STALE_TTL) ? $cached['body'] : null;
    }

    /** https only, and never a host that resolves to a private/reserved address. */
    public static function isFetchableTarget(string $target): bool
    {
        $parts = parse_url($target);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $ip = filter_var($parts['host'], FILTER_VALIDATE_IP) ? $parts['host'] : gethostbyname($parts['host']);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return array{code:int, body:string} */
    public static function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
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
