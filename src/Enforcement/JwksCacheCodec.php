<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Encodes the `kid => raw Ed25519 key (32 bytes)` map for the cache.
 *
 * `FileCache` serializes with `json_encode`, which fails on non-UTF-8 bytes
 * (raw keys almost never are UTF-8): the entry was not stored and the JWKS was
 * downloaded on every checkout. On disk they go as base64. An empty list is not
 * cached: an empty JWKS (a transient error) must not stick around for an hour.
 */
final class JwksCacheCodec
{
    private const KEY_BYTES = 32;

    /**
     * @param array<string,string> $keys kid => 32 raw bytes
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
     * @param mixed $stored What the cache returned.
     * @return array<string,string>|null null = nothing usable (must download).
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
