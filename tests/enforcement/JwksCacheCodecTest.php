<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\JwksCacheCodec;
use Trusteed\Storage\FileCache;

/**
 * JWKS public keys are 32 raw bytes: `json_encode` of non-UTF-8 bytes returns
 * `false` ("Malformed UTF-8"), `FileCache::set()` wrote an empty string and
 * `get()` returned null. Result: the JWKS was downloaded (5 s timeout) on EVERY
 * checkout and never got cached. Magento solves it with base64
 * (`JwksPositiveCache`); this is the same fix.
 */
final class JwksCacheCodecTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/trusteed-jwks-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testA32ByteKeySurvivesTheFileCacheRoundTrip(): void
    {
        $raw = random_bytes(31) . "\xff"; // not valid UTF-8: worst case
        $cache = new FileCache($this->dir);

        $cache->set('jwks', JwksCacheCodec::encode(['kid-1' => $raw]), 60);

        $stored = $cache->get('jwks');
        self::assertIsArray($stored, 'la entrada se guardó');
        self::assertSame(['kid-1' => $raw], JwksCacheCodec::decode($stored));
    }

    public function testAnEmptyKeySetIsNotWorthCaching(): void
    {
        self::assertFalse(JwksCacheCodec::isCacheable([]));
        self::assertTrue(JwksCacheCodec::isCacheable(['kid-1' => str_repeat('a', 32)]));
    }

    public function testDecodeRejectsWhatIsNotAKeySet(): void
    {
        self::assertNull(JwksCacheCodec::decode(null));
        self::assertNull(JwksCacheCodec::decode('x'));
        self::assertNull(JwksCacheCodec::decode([]), 'una lista vacía no es una caché utilizable');
        self::assertNull(JwksCacheCodec::decode(['kid-1' => 'no es base64 de 32 bytes']));
        self::assertNull(JwksCacheCodec::decode(['kid-1' => 123]));
    }

    public function testDecodeDropsEntriesThatAreNot32Bytes(): void
    {
        $good = str_repeat("\x01", 32);
        $decoded = JwksCacheCodec::decode([
            'good' => base64_encode($good),
            'short' => base64_encode('abc'),
        ]);

        self::assertSame(['good' => $good], $decoded);
    }
}
