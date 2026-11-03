<?php
/**
 * Spec-048 4.9 — reporta a Trusteed qué señales de carrito sabe proyectar
 * ESTA instalación de PrestaShop.
 *
 * POR QUÉ EXISTE. El servidor sabe qué señal lee cada regla
 * (`RULE_SIGNALS_READ`), pero no sabía qué aporta cada instalación. Sin ese
 * cruce, una regla cuya señal no llega devuelve `NO_SIGNAL` en cada checkout:
 * pasa en silencio, y el comerciante ve una regla en ENFORCE que no bloquea
 * nada. Con el reporte, el panel puede avisarle al activarla.
 *
 * CUÁNDO SE MANDA. Al cambiar la versión del módulo y, además, cada 7 días
 * (barrido 2026-10-10, 8b6de3808): el servidor da por muerta una tienda cuyo
 * `lastSeenAt` tiene 30 días y este reporte es lo que lo renueva; con uno solo
 * por release, una tienda tranquila caducaba. La comprobación es una lectura de
 * `Configuration`; se dispara desde un hook de back-office y desde un hook de
 * front, este último DESPUÉS de enviar la respuesta. Un intento fallido espera
 * una hora antes de reintentar.
 *
 * FIRMA. Mismo esquema que `PaymentFailureEmitter`: HMAC-SHA256 sobre el JSON
 * canónico del cuerpo sin `signature`. El servidor verifica contra el cuerpo
 * tal como llega, así que el orden del array de señales da igual.
 *
 * La lista de señales sale de `ValidateOrderHook::signalsProvided()`, que un
 * gate en `packages/shared` mantiene pegada a lo que el constructor de
 * contexto escribe de verdad.
 */

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CapabilitiesReporter
{
    private const ENDPOINT_PATH = '/api/v1/enforcement/capabilities';

    /** Clave donde se recuerda la última versión reportada. */
    private const CONFIG_REPORTED_VERSION = 'TRUSTEED_CEL_CAPS_REPORTED_VERSION';

    /** Cuándo (epoch) se aceptó el último reporte. */
    private const CONFIG_REPORTED_AT = 'TRUSTEED_CEL_CAPS_REPORTED_AT';

    /** Cuándo (epoch) fue el último intento, con o sin éxito (backoff). */
    private const CONFIG_ATTEMPT_AT = 'TRUSTEED_CEL_CAPS_ATTEMPT_AT';

    /** Renovación del latido aunque nada haya cambiado. */
    private const RENEW_AFTER_SECONDS = 7 * 86400;

    /** Espera tras un intento, para no pagar el timeout en cada carga si el servidor no responde. */
    private const RETRY_AFTER_SECONDS = 3600;

    /**
     * ¿Toca reportar? Puro: sin Configuration ni reloj.
     */
    public static function isDue(string $version, string $reportedVersion, int $reportedAt, int $attemptAt, int $now): bool
    {
        if ($attemptAt > 0 && $now - $attemptAt < self::RETRY_AFTER_SECONDS) {
            return false;
        }
        if ($reportedVersion !== $version) {
            return true;
        }

        return $reportedAt <= 0 || $now - $reportedAt >= self::RENEW_AFTER_SECONDS;
    }

    private static function due(string $moduleVersion): bool
    {
        return self::isDue(
            $moduleVersion,
            (string) \Configuration::get(self::CONFIG_REPORTED_VERSION),
            (int) \Configuration::get(self::CONFIG_REPORTED_AT),
            (int) \Configuration::get(self::CONFIG_ATTEMPT_AT),
            time()
        );
    }

    /**
     * Manda el reporte si la versión cambió o si pasaron 7 días desde el último.
     *
     * El servidor también deduplica (responde `unchanged: true` sin escribir el
     * contenido, pero sí renueva el latido), y comprobarlo aquí evita una
     * petición HTTP por carga de página.
     */
    public static function maybeReport(string $moduleVersion): void
    {
        try {
            if (!self::due($moduleVersion)) {
                return;
            }
            // Antes de la petición: si el servidor tarda o cae, no se reintenta en cada carga.
            \Configuration::updateValue(self::CONFIG_ATTEMPT_AT, (string) time());
            if (self::report($moduleVersion)) {
                \Configuration::updateValue(self::CONFIG_REPORTED_VERSION, $moduleVersion);
                \Configuration::updateValue(self::CONFIG_REPORTED_AT, (string) time());
            }
        } catch (\Throwable $e) {
            // Que el diagnóstico no llegue no puede romper el back-office.
        }
    }

    /**
     * Como {@see maybeReport()} pero para hooks de front: la petición HTTP (hasta
     * 3 s) se hace al terminar la solicitud, tras enviar la respuesta al visitante.
     */
    public static function maybeReportAfterResponse(string $moduleVersion): void
    {
        try {
            if (!self::due($moduleVersion)) {
                return;
            }
            register_shutdown_function(static function () use ($moduleVersion): void {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                self::maybeReport($moduleVersion);
            });
        } catch (\Throwable $e) {
            // Idem.
        }
    }

    /**
     * @return bool true si el servidor aceptó el reporte (202).
     */
    public static function report(string $moduleVersion): bool
    {
        $merchantId     = (string) \Configuration::get('TRUSTEED_CEL_MERCHANT_ID');
        $installationId = (string) \Configuration::get('TRUSTEED_CEL_INSTALLATION_ID');
        $hmacSecret     = (string) \Configuration::get('TRUSTEED_CEL_HMAC_SECRET');
        $apiBase        = (string) \Configuration::get('TRUSTEED_API_BASE');

        if ($merchantId === '' || $installationId === '' || $hmacSecret === '' || $apiBase === '') {
            return false;
        }

        $signals = [];
        foreach (ValidateOrderHook::signalsProvided() as $attr) {
            // El vocabulario del servidor prefija con `cartAttr.`; el módulo
            // trabaja con la clave cruda del atributo de carrito.
            $signals[] = 'cartAttr.' . $attr;
        }

        $body = [
            'installationId'  => $installationId,
            'merchantId'      => $merchantId,
            'platform'        => 'PRESTASHOP',
            'pluginVersion'   => $moduleVersion,
            'signalsProvided' => $signals,
        ];
        $body['signature'] = self::hmacSign($body, $hmacSecret);

        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!function_exists('curl_init') || $payload === false) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, rtrim($apiBase, '/') . self::ENDPOINT_PATH);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Trusteed-PS/2.0 spec-048-4.9',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 3000);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1000);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        @curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status === 202;
    }

    private static function hmacSign(array $body, string $secret): string
    {
        $sorted    = self::recursiveKsort($body);
        $canonical = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $canonical, $secret);
    }

    /**
     * Ordena claves recursivamente. Las listas (como `signalsProvided`)
     * conservan su orden a propósito: el servidor firma contra el cuerpo
     * recibido, no contra el que él normaliza.
     *
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
}
