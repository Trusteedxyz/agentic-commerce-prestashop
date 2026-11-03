<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Codifica el mapa `kid => clave Ed25519 cruda (32 bytes)` para la caché.
 *
 * `FileCache` serializa con `json_encode`, que falla con bytes que no son
 * UTF-8 (las claves crudas casi nunca lo son): la entrada no se guardaba y el
 * JWKS se descargaba en cada checkout. En disco van en base64, como en
 * Magento (`JwksPositiveCache`). Una lista vacía no se cachea: un JWKS vacío
 * (error transitorio) no debe quedarse una hora.
 */
final class JwksCacheCodec
{
    private const KEY_BYTES = 32;

    /**
     * @param array<string,string> $keys kid => 32 bytes crudos
     * @return array<string,string> kid => base64
     */
    public static function encode(array $keys): array
    {
        $out = [];
        foreach ($keys as $kid => $raw) {
            $out[(string) $kid] = base64_encode((string) $raw);
        }

        return $out;
    }

    /**
     * @param mixed $stored Lo que devolvió la caché.
     * @return array<string,string>|null null = nada utilizable (hay que descargar).
     */
    public static function decode(mixed $stored): ?array
    {
        if (!is_array($stored)) {
            return null;
        }
        $keys = [];
        foreach ($stored as $kid => $b64) {
            if (!is_string($b64)) {
                continue;
            }
            $raw = base64_decode($b64, true);
            if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
                continue;
            }
            $keys[(string) $kid] = $raw;
        }

        return $keys === [] ? null : $keys;
    }

    /** @param array<string,string> $keys */
    public static function isCacheable(array $keys): bool
    {
        return $keys !== [];
    }
}
