<?php

declare(strict_types=1);

/**
 * Spec 042 R1 (codex remediation P1#1) — RFC 8785 JCS canonicalizer.
 *
 * Produces byte-identical output to:
 *   - Node `canonicalizeJSON` from `@agenticmcpstores/shared/a2a/agent-card-signer.ts`
 *   - Python `jcs.canonicalize` from `packages/odoo-addon-agenticmcpstores/utils/jcs.py`
 *
 * Cross-language CI gate: `apps/api/src/__tests__/fixtures/jcs-golden-vectors.json`
 * runs the same 10 vectors against all 3 implementations.
 *
 * Rules (RFC 8785):
 *   - Object keys sorted by Unicode code point (lex)
 *   - No extra whitespace
 *   - Strings: only \", \\, control chars <0x20 escaped (slash, unicode as-is UTF-8)
 *   - Numbers per ECMA-262 (no trailing zeros; floats use minimal representation)
 *
 * NOTE: PHP's `json_encode` differs from spec in several ways — we cannot use it
 * directly. This implementation is hand-rolled.
 */

namespace Trusteed\Hmac;

final class JcsCanonicalize
{
    /**
     * Canonicalize $value to RFC 8785 canonical JSON string.
     *
     * @throws \RuntimeException on unsupported value type or non-finite number
     */
    public static function canonicalize(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \RuntimeException('JCS: non-finite numbers not allowed');
            }
            return self::formatNumber($value);
        }
        if (is_string($value)) {
            return self::escapeString($value);
        }
        if (is_array($value)) {
            // Distinguish list (sequential 0..n-1) from assoc.
            $isList = array_is_list($value);
            if ($isList) {
                $parts = array_map(fn($v) => self::canonicalize($v), $value);
                return '[' . implode(',', $parts) . ']';
            }
            // Assoc — sort keys lex (Unicode code point).
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            $pairs = [];
            foreach ($keys as $k) {
                $pairs[] = self::escapeString((string) $k) . ':' . self::canonicalize($value[$k]);
            }
            return '{' . implode(',', $pairs) . '}';
        }
        if (is_object($value)) {
            // Treat objects (e.g. \stdClass) like assoc arrays.
            return self::canonicalize((array) $value);
        }
        throw new \RuntimeException('JCS: unsupported type: ' . gettype($value));
    }

    /**
     * Escape a string per RFC 8259 minimal rules — match Node JSON.stringify.
     */
    private static function escapeString(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $byte = ord($s[$i]);

            if ($byte < 0x20) {
                // Control chars — special-case the named escapes (RFC 8259).
                $out .= match ($byte) {
                    0x08 => '\\b',
                    0x09 => '\\t',
                    0x0a => '\\n',
                    0x0c => '\\f',
                    0x0d => '\\r',
                    default => sprintf('\\u%04x', $byte),
                };
                $i++;
                continue;
            }
            if ($byte === 0x22) {
                $out .= '\\"';
                $i++;
                continue;
            }
            if ($byte === 0x5c) {
                $out .= '\\\\';
                $i++;
                continue;
            }
            // ASCII printable + UTF-8 multibyte — emit as-is.
            $out .= $s[$i];
            $i++;
        }
        $out .= '"';
        return $out;
    }

    /**
     * Format a float per ECMA-262 (matches JavaScript Number.prototype.toString).
     * For our payloads (totalPaid, amount, hoursToFulfill) we expect non-exponential
     * finite values; document this constraint in the runbook.
     */
    private static function formatNumber(float $value): string
    {
        // Integer-valued floats: drop ".0" (Node: 99.0 → "99").
        if ($value === floor($value) && !is_infinite($value)) {
            return (string) (int) $value;
        }
        // Default PHP float formatting matches ECMA for typical values.
        // For deterministic behavior across PHP versions use serialize_precision.
        $orig = ini_get('serialize_precision');
        try {
            // -1 (default since PHP 7.1) uses the shortest round-trippable representation.
            ini_set('serialize_precision', '-1');
            return (string) $value;
        } finally {
            ini_set('serialize_precision', $orig);
        }
    }
}
