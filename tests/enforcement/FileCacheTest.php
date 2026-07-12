<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use Trusteed\Storage\FileCache;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Storage/FileCache.php';

/**
 * B5 — Persistent file-backed cache (replaces APCu silent fallbacks).
 *
 * Requirements:
 *  - get() returns null on miss / expiration / corrupt file
 *  - set() persists across process boundaries (file-based)
 *  - addIfAbsent() is atomic — only the first writer wins, used for
 *    nonce dedup and key first-seen
 *  - LOCK_EX is held during write to prevent torn reads
 */
class FileCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/trusteed-fc-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = glob($this->dir . '/*');
            foreach ($files ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $c = new FileCache($this->dir);
        $this->assertNull($c->get('missing'));
    }

    public function testSetThenGetRoundTrip(): void
    {
        $c = new FileCache($this->dir);
        $c->set('k1', ['hello' => 'world'], 60);
        $this->assertSame(['hello' => 'world'], $c->get('k1'));
    }

    public function testGetReturnsNullPastTtl(): void
    {
        $c = new FileCache($this->dir);
        $c->set('expiring', 'v', 1);
        // Backdate the file mtime so TTL check sees it as stale.
        $path = $this->dir . '/' . hash('sha256', 'expiring') . '.json';
        $raw  = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        $data['e'] = time() - 5;
        file_put_contents($path, (string) json_encode($data));
        $this->assertNull($c->get('expiring'));
    }

    public function testAddIfAbsentReturnsTrueOnce(): void
    {
        $c = new FileCache($this->dir);
        $this->assertTrue($c->addIfAbsent('nonce-1', 1, 60));
        $this->assertFalse($c->addIfAbsent('nonce-1', 1, 60));
        $this->assertFalse($c->addIfAbsent('nonce-1', 1, 60));
    }

    public function testAddIfAbsentLetsExpiredKeysRecycle(): void
    {
        $c = new FileCache($this->dir);
        $this->assertTrue($c->addIfAbsent('rec', 1, 60));
        // Backdate stored entry past expiry then retry.
        $path = $this->dir . '/' . hash('sha256', 'rec') . '.json';
        $raw  = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        $data['e'] = time() - 1;
        file_put_contents($path, (string) json_encode($data));
        $this->assertTrue($c->addIfAbsent('rec', 1, 60));
    }

    public function testCorruptFileReturnsNullNotFatal(): void
    {
        $c = new FileCache($this->dir);
        $c->set('k', 'v', 60);
        $path = $this->dir . '/' . hash('sha256', 'k') . '.json';
        file_put_contents($path, 'not-json');
        $this->assertNull($c->get('k'));
    }

    public function testGetIntDefaultsZeroOnMiss(): void
    {
        $c = new FileCache($this->dir);
        $this->assertSame(0, $c->getInt('miss'));
        $c->set('hit', 42, 60);
        $this->assertSame(42, $c->getInt('hit'));
    }
}
