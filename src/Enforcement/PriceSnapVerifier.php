<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    // Allow PHPUnit standalone load — production guard is enforced by caller hook.
}

/**
 * B4 — Verify HMAC-signed price-snapshot cookies for R015 evaluation.
 *
 * Wire format (matches Shopify cart attribute `_trusteed_price_snap`):
 *   cookie  = base64( json({ "v": "tps.v1", "p": {pid: cents}, "h": hmac_hex }) )
 *   message = "pid=cents,..." sorted lexicographically by pid, joined by commas
 *   hmac    = HMAC-SHA256(merchantHmacKey, message), hex
 *
 * The merchant HMAC key arrives via R015.params.priceSnapHmacKeyHex inside the
 * signed snapshot (per `apps/api/src/services/enforcement/price-snap-hmac.ts`),
 * so the key never travels in agent-writable storage.
 *
 * SECURITY: empty / malformed / wrong-HMAC cookies return an empty map. This
 * means R015 simply sees no snapshot — degrading to PASS rather than enforcing
 * with attacker-supplied prices. Pre-B4 behavior trusted raw client cookies.
 */
final class PriceSnapVerifier
{
    public const VERSION = 'tps.v1';

    /**
     * @param string $cookieRaw    base64-encoded cookie value
     * @param string $hmacKeyHex   per-merchant HMAC key, hex
     * @return array<string,int>   pid => priceCents, empty on any failure
     */
    public static function verify(string $cookieRaw, string $hmacKeyHex): array
    {
        if ($cookieRaw === '' || $hmacKeyHex === '') {
            return [];
        }

        $decoded = base64_decode($cookieRaw, true);
        if (!is_string($decoded) || $decoded === '') {
            return [];
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return [];
        }

        if (($payload['v'] ?? null) !== self::VERSION) {
            return [];
        }

        $prices = $payload['p'] ?? null;
        $hmac   = $payload['h'] ?? null;
        if (!is_array($prices) || !is_string($hmac) || $hmac === '') {
            return [];
        }

        $normalized = self::normalizePrices($prices);
        if ($normalized === null) {
            return [];
        }

        $key = @hex2bin($hmacKeyHex);
        if ($key === false || $key === '') {
            return [];
        }

        $expected = hash_hmac('sha256', self::canonicalMessage($normalized), $key);
        if (!hash_equals($expected, $hmac)) {
            return [];
        }

        return $normalized;
    }

    /**
     * Canonical HMAC message: sorted "pid=cents" pairs joined by commas.
     * Must stay byte-identical to the TS implementation in
     * apps/api/src/services/enforcement/price-snap-hmac.ts::canonicalPriceMessage.
     *
     * @param array<string,int> $prices
     */
    public static function canonicalMessage(array $prices): string
    {
        ksort($prices, SORT_STRING);
        $parts = [];
        foreach ($prices as $pid => $cents) {
            $parts[] = $pid . '=' . $cents;
        }
        return implode(',', $parts);
    }

    /**
     * @param  array<mixed,mixed>      $prices
     * @return array<string,int>|null  null when any entry is malformed
     */
    private static function normalizePrices(array $prices): ?array
    {
        $out = [];
        foreach ($prices as $pid => $cents) {
            $pidStr = (string) $pid;
            if ($pidStr === '') {
                return null;
            }
            if (!is_int($cents) && !(is_string($cents) && ctype_digit($cents))) {
                return null;
            }
            $value = (int) $cents;
            if ($value < 0) {
                return null;
            }
            $out[$pidStr] = $value;
        }
        return $out;
    }
}
