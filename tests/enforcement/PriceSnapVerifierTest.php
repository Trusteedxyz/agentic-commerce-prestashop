<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\PriceSnapVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Enforcement/PriceSnapVerifier.php';

/**
 * B4 — HMAC verification of R015 price-snapshot cookies.
 *
 * Cookie format (matches Shopify cart-attribute schema):
 *   base64( json({ "v": "tps.v1", "p": {pid: cents}, "h": hmac_hex }) )
 *
 * HMAC: SHA-256 over canonical message "pid=cents,..." sorted by pid,
 * using merchant-derived key (snapshot R015.params.priceSnapHmacKeyHex).
 *
 * Verifier returns prices map on valid HMAC, empty array otherwise.
 * Empty key means R015 will simply find no snapshot evidence → degrades
 * to PASS (no false positives from forged client cookies).
 */
class PriceSnapVerifierTest extends TestCase
{
    private string $hmacKeyHex;

    protected function setUp(): void
    {
        $this->hmacKeyHex = bin2hex(random_bytes(32));
    }

    public function testReturnsEmptyOnBlankCookie(): void
    {
        $this->assertSame([], PriceSnapVerifier::verify('', $this->hmacKeyHex));
    }

    public function testReturnsEmptyOnMissingKey(): void
    {
        $payload = $this->makeCookie(['100' => 1500]);
        $this->assertSame([], PriceSnapVerifier::verify($payload, ''));
    }

    public function testReturnsPricesOnValidHmac(): void
    {
        $cookie = $this->makeCookie(['100' => 1500, '200' => 2500]);
        $result = PriceSnapVerifier::verify($cookie, $this->hmacKeyHex);
        $this->assertSame(['100' => 1500, '200' => 2500], $result);
    }

    public function testRejectsTamperedPrice(): void
    {
        $cookie  = $this->makeCookie(['100' => 1500]);
        $decoded = json_decode(base64_decode($cookie, true), true);
        $decoded['p']['100'] = 1; // attacker lowers original price
        $tampered = base64_encode((string) json_encode($decoded));
        $this->assertSame([], PriceSnapVerifier::verify($tampered, $this->hmacKeyHex));
    }

    public function testRejectsLegacyUnsignedFormat(): void
    {
        // Legacy: base64(json({pid: cents})) without {v, p, h} envelope.
        $legacy = base64_encode((string) json_encode(['100' => 1500]));
        $this->assertSame([], PriceSnapVerifier::verify($legacy, $this->hmacKeyHex));
    }

    public function testRejectsWrongKey(): void
    {
        $cookie  = $this->makeCookie(['100' => 1500]);
        $wrong   = bin2hex(random_bytes(32));
        $this->assertSame([], PriceSnapVerifier::verify($cookie, $wrong));
    }

    public function testRejectsUnknownVersion(): void
    {
        $payload = ['v' => 'tps.v9', 'p' => ['100' => 1500], 'h' => str_repeat('0', 64)];
        $cookie  = base64_encode((string) json_encode($payload));
        $this->assertSame([], PriceSnapVerifier::verify($cookie, $this->hmacKeyHex));
    }

    public function testCanonicalMessageMatchesSharedSpec(): void
    {
        // Must match `canonicalPriceMessage` in apps/api/src/services/enforcement/price-snap-hmac.ts
        // (sorted key=value pairs joined by commas)
        $msg = PriceSnapVerifier::canonicalMessage(['b' => 2, 'a' => 1, 'c' => 3]);
        $this->assertSame('a=1,b=2,c=3', $msg);
    }

    private function makeCookie(array $prices): string
    {
        ksort($prices);
        $msg = PriceSnapVerifier::canonicalMessage($prices);
        $h   = hash_hmac('sha256', $msg, (string) hex2bin($this->hmacKeyHex));
        return base64_encode((string) json_encode(['v' => 'tps.v1', 'p' => $prices, 'h' => $h]));
    }
}
