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
 * CEL capa-1 offline enforcement).
 *
 * Per spec-048 §FR-040, the Webservice gap is mitigated at capa-2 via the
 * Trusteed MCP proxy. This middleware surfaces the gap visually to admins
 * via PS logger + admin notice when a WRITE arrives without a VERIFIED proxy
 * mark, without blocking (capa-2 proxy is the authoritative blocker here).
 *
 * Barrido 2026-10-10: la marca «ya pasó por el proxy» ya no es la cabecera
 * autodeclarada `X-Trusteed-Proxy: true` (la escribe cualquiera): hace falta
 * además `X-Trusteed-Proxy-Signature: t=<ts>,s=<hmac>`, con
 * hmac = HMAC-SHA256(TRUSTEED_CEL_HMAC_SECRET, "<ts>.<MÉTODO>.<path sin query>")
 * y |ahora − ts| ≤ 300 s. Las lecturas (GET/HEAD/OPTIONS) no avisan.
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

    /** Ventana máxima entre el reloj del proxy y el nuestro. */
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
     * admin notice flag. Reads never warn. Does NOT block — capa-2 MCP proxy is
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

        // El método HTTP real, no un parámetro del hook (su contenido no está
        // documentado); por defecto GET = no avisar.
        $method  = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '/api/orders');
        $secret  = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $verdict = self::classify($method, $uri, self::requestHeaders(), $secret, time());

        if ($verdict === self::VERDICT_READ_ONLY || $verdict === self::VERDICT_PROXY_VERIFIED) {
            return;
        }

        // Log webservice gap warning (sin cabeceras, sin query: la ws_key va ahí).
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
     * Veredicto para una petición al Webservice de pedidos. Puro: sin globales.
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

    /** Línea de log del hueco: veredicto y método, nunca cabeceras ni query. */
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
     * L1 — portable request-header reader.
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
                // HTTP_X_TRUSTEED_PROXY → X-Trusteed-Proxy
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
