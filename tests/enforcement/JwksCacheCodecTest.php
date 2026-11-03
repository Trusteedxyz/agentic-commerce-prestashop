<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\JwksCacheCodec;
use Trusteed\Storage\FileCache;

/**
 * Las claves públicas del JWKS son 32 bytes crudos: `json_encode` de bytes que
 * no son UTF-8 devuelve `false` («Malformed UTF-8»), `FileCache::set()` escribía
 * una cadena vacía y `get()` devolvía null. Resultado: el JWKS se descargaba (5 s
 * de timeout) en CADA checkout y nunca llegaba a cachearse (barrido 2026-10-10,
 * 2017dfb82 / bd72fbf46 / 21986925d). Magento lo resuelve con base64
 * (`JwksPositiveCache`); este es el mismo arreglo.
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
        $raw = random_bytes(31) . "\xff"; // no es UTF-8 válido: peor caso
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
