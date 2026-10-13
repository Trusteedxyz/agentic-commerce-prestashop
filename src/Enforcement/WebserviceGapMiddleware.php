<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed CEL Webservice Gap Middleware for PrestaShop.
 *
 * Detects agentic traffic arriving via the PS Webservice REST API
 * (/api/orders) that bypasses the PaymentModule override (and thus the
 * CEL layer 1 offline enforcement).
 *
 * The Webservice gap is mitigated at layer 2 via the Trusteed MCP proxy. This
 * middleware surfaces the gap visually to admins via PS logger + admin notice
 * when a WRITE arrives without a VERIFIED proxy mark, without blocking (the
 * layer 2 proxy is the authoritative blocker here).
 *
 * The "already went through the proxy" mark is not the self-declared header
 * `X-Trusteed-Proxy: true` (anyone can write it). It also requires
 * `X-Trusteed-Proxy-Signature: t=<ts>,s=<hmac>`, where
 * hmac = HMAC-SHA256(TRUSTEED_CEL_HMAC_SECRET, "<ts>.<METHOD>.<path without query>")
 * and |now - ts| <= 300 s. Reads (GET/HEAD/OPTIONS) do not warn.
 *
 * @package Trusteed\Enforcement
 */
class WebserviceGapMiddleware
{
    /** Admin notice option key to avoid repeated notices per session */
    private const ADMIN_NOTICE_KEY = 'trusteed_cel_ws_gap_notice';

    public const VERDICT_READ_ONLY      = 'read_only';
    public const VERDICT_PROXY_VERIFIED = 'proxy_verified';
    public const VERDICT_UNVERIFIED     = 'proxy_unverified';
    public const VERDICT_NO_PROXY       = 'no_proxy';

    /** Maximum skew between the proxy clock and ours. */
    private const MAX_SKEW_SECONDS = 300;

    /**
     * Register the Webservice hook on the module.
     * Called from Trusteed::install().
     */
    public static function register(\Trusteed $module): void
    {
        $module->registerHook('actionWebserviceCallBefore');
    }

    /**
     * Unregister hook on module uninstall.
     */
    public static function unregister(\Trusteed $module): void
    {
        $module->unregisterHook('actionWebserviceCallBefore');
    }

    /**
     * Hook handler: fires before each Webservice API call.
     *
     * When an /api/orders WRITE arrives without a verified proxy signature
     * (see {@see classify()}), log a structured warning and set a persistent
     * admin notice flag. Reads never warn. Does NOT block: the layer 2 MCP proxy is
     * responsible for enforcement here (blocking is a pending product decision).
     *
     * @param array $params Hook parameters (resource, method, etc.).
     */
    public function hookActionWebserviceCallBefore(array $params): void
    {
        $resource = strtolower((string) ($params['resource'] ?? ''));
        if ($resource !== 'orders') {
            return;
        }

        $shopId = MerchantResolver::currentShopId();
        if (!MerchantResolver::isEnabled($shopId)) {
            return;
        }

        // The real HTTP method, not a hook parameter (its content is not
        // documented); defaults to GET, which does not warn.
        $method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '/api/orders');
        $secret  = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $verdict = self::classify($method, $uri, self::requestHeaders(), $secret, time());

        if ($verdict === self::VERDICT_READ_ONLY || $verdict === self::VERDICT_PROXY_VERIFIED) {
            return;
        }

        // Log webservice gap warning (no headers, no query: the ws_key travels there).
        \PrestaShopLogger::addLog(
            self::logLine($method, $verdict),
            \PrestaShopLogger::LOG_SEVERITY_WARNING,
            null,
            'Order',
            null,
            true
        );

        // Set a flag that AdminCELController will render as a persistent notice
        \Configuration::updateValue(self::ADMIN_NOTICE_KEY, '1');
    }

    /**
     * Verdict for a request to the orders Webservice. Pure: no globals.
     *
     * @param array<string,string> $headers
     */
    public static function classify(string $method, string $uri, array $headers, string $secret, int $now): string
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return self::VERDICT_READ_ONLY;
        }
        if (self::headerValue($headers, 'X-Trusteed-Proxy') !== 'true') {
            return self::VERDICT_NO_PROXY;
        }

        return self::proxySignatureIsValid($method, $uri, self::headerValue($headers, 'X-Trusteed-Proxy-Signature'), $secret, $now)
            ? self::VERDICT_PROXY_VERIFIED
            : self::VERDICT_UNVERIFIED;
    }

    /** Log line for the gap: verdict and method, never headers or query. */
    public static function logLine(string $method, string $verdict): string
    {
        return '[trusteed.webservice_gap] ' . json_encode(
            ['method' => strtoupper($method), 'verdict' => $verdict, 'blocked' => false, 'resource' => 'orders'],
            JSON_UNESCAPED_SLASHES
        );
    }

    private static function proxySignatureIsValid(string $method, string $uri, string $signature, string $secret, int $now): bool
    {
        if ($secret === '' || preg_match('/^t=(\d{1,12}),s=([0-9a-f]{64})$/', $signature, $m) !== 1) {
            return false;
        }
        $ts = (int) $m[1];
        if (abs($now - $ts) > self::MAX_SKEW_SECONDS) {
            return false;
        }
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? $uri);
        $expected = hash_hmac('sha256', $ts . '.' . strtoupper($method) . '.' . $path, $secret);

        return hash_equals($expected, $m[2]);
    }

    /**
     * Portable request-header reader.
     *
     * `getallheaders()` is only defined under the Apache SAPI; on nginx/php-fpm
     * (and CLI) it is absent, so the prior `getallheaders() ?: []` silently
     * returned [] there and the proxy header was never seen. We prefer the
     * native function when present and otherwise reconstruct headers from the
     * `$_SERVER` `HTTP_*` superglobal (the portable polyfill).
     *
     * @return array<string,string>
     */
    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $native = getallheaders();
            if (is_array($native) && $native !== []) {
                return $native;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (strncmp($key, 'HTTP_', 5) === 0) {
                // HTTP_X_TRUSTEED_PROXY becomes X-Trusteed-Proxy
                $name = str_replace(
                    ' ',
                    '-',
                    ucwords(strtolower(str_replace('_', ' ', substr($key, 5))))
                );
                $headers[$name] = (string) $value;
            }
        }
        return $headers;
    }

    /**
     * Case-insensitive header lookup (HTTP header names are case-insensitive
     * per RFC 7230; SAPIs differ on casing).
     *
     * @param array<string,string> $headers
     */
    private static function headerValue(array $headers, string $name): string
    {
        $needle = strtolower($name);
        foreach ($headers as $key => $value) {
            if (is_string($key) && strtolower($key) === $needle) {
                return (string) $value;
            }
        }
        return '';
    }

    /**
     * Acknowledge and clear the Webservice gap admin notice.
     * Called from the admin settings page when admin clicks "Acknowledge".
     */
    public static function acknowledgeGapNotice(): void
    {
        \Configuration::updateValue(self::ADMIN_NOTICE_KEY, '0');
    }

    /**
     * Returns true when an unacknowledged gap notice is pending.
     */
    public static function hasPendingGapNotice(): bool
    {
        return \Configuration::get(self::ADMIN_NOTICE_KEY) === '1';
    }
}
