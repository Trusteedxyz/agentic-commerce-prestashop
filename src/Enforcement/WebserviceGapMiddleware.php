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
 * via PS logger + admin notice when the proxy header is absent, without
 * blocking (capa-2 proxy is the authoritative blocker for this path).
 *
 * @package Trusteed\Enforcement
 */
class WebserviceGapMiddleware
{
    /** Admin notice option key to avoid repeated notices per session */
    private const ADMIN_NOTICE_KEY = 'trusteed_cel_ws_gap_notice';

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
     * When an /api/orders request arrives without the X-Trusteed-Proxy: true
     * header, log a warning and set a persistent admin notice flag.
     * Does NOT block — capa-2 MCP proxy is responsible for enforcement here.
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

        $headers         = self::requestHeaders();
        $proxyHeaderSent = self::headerValue($headers, 'X-Trusteed-Proxy') === 'true';

        if ($proxyHeaderSent) {
            return;
        }

        // Log webservice gap warning
        \PrestaShopLogger::addLog(
            'CEL: Webservice /api/orders received without X-Trusteed-Proxy header. '
            . 'CEL capa-1 enforcement is bypassed on this path. '
            . 'Configure the Trusteed MCP proxy to enforce rules on Webservice orders.',
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
