<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Allowlist del API base al que el módulo manda un Bearer o el secreto S2S
 * (S042-004, guarda SSRF / exfiltración).
 *
 * Extraída tal cual de `AdminTrusteedController::isAllowedApiBase()` (era
 * `private`) para que la use también la sincronización de PayPal. El
 * comportamiento no ha cambiado: producción sólo HTTPS a hosts conocidos;
 * HTTP únicamente a hosts de desarrollo.
 */
final class ApiBaseAllowlist
{
    /** Hostnames permitidos para el API base en producción. */
    private const ALLOWED_API_HOSTS = [
        'api.trusteed.xyz',
        'agenticmcpstores-production.up.railway.app',
        'staging-api.trusteed.xyz',
    ];

    /** IPs/hosts privados permitidos sólo en entornos de desarrollo (HTTP sin TLS). */
    private const DEV_API_HOSTS = [
        'localhost',
        '127.0.0.1',
        '172.17.0.1',
        '172.18.0.1',
        '172.20.0.1',
        'host.docker.internal',
        '38.242.232.176',
    ];

    /**
     * Destino de SECRETOS (credenciales PayPal, secreto S2S): sólo HTTPS a los
     * hosts de producción/staging de Trusteed. Nunca hosts de desarrollo ni
     * HTTP, aunque `isAllowed()` los admita para el resto del módulo.
     */
    public static function isAllowedForSecrets(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return isset($parts['host']) && in_array($parts['host'], self::ALLOWED_API_HOSTS, true);
    }

    public static function isAllowed(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
        // Producción: sólo HTTPS con hosts conocidos
        if (str_starts_with($url, 'https://')) {
            return in_array($host, self::ALLOWED_API_HOSTS, true);
        }
        // Desarrollo local: HTTP permitido sólo para IPs/hosts privados
        if (str_starts_with($url, 'http://')) {
            return in_array($host, self::DEV_API_HOSTS, true);
        }
        return false;
    }
}
