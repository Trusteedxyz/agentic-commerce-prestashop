<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Valor de la cabecera `X-Trusteed-Signature` con el HMAC de la instalación:
 * `t=<unix>,s=<hex>` con `hex = HMAC-SHA256(secreto, "<unix>.<cuerpo>")`. El campo es `s=`, no `v1=`:
 * el servidor rechaza `v1=` (apps/api/src/services/enforcement/installation-hmac.service.ts).
 */
final class InstallationSignature
{
    public static function header(string $hmacSecret, string $body, ?int $ts = null): string
    {
        $ts ??= time();

        return 't=' . $ts . ',s=' . hash_hmac('sha256', $ts . '.' . $body, $hmacSecret);
    }
}
