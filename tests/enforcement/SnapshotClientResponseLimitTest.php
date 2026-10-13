<?php

declare(strict_types=1);

/**
 * The body read limit, with REAL cURL.
 *
 * Against a local HTTP server (`php -S`): a body within the limit is read in
 * full; one that exceeds it, whether the server announces the size
 * (Content-Length) or streams it in chunks without announcing it, is abandoned
 * with `response_too_large` and the body is not returned.
 */

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\SnapshotClient;

final class SnapshotResponseLimitProbe extends SnapshotClient
{
    /** @return array{code:int,body:string,error:string} */
    public function fetch(string $url): array
    {
        return $this->httpGet($url, [], 10);
    }
}

final class SnapshotClientResponseLimitTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $router = '';
    private int $port = 0;

    protected function setUp(): void
    {
        if (!function_exists('curl_init') || !defined('PHP_BINARY') || PHP_BINARY === '') {
            self::markTestSkipped('cURL o el binario de PHP no están disponibles');
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($probe, 'puerto libre: ' . $errstr);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->router = sys_get_temp_dir() . '/trusteed-limit-router-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->router, <<<'PHP'
<?php
$bytes = (int) ($_GET['bytes'] ?? 0);
if (($_GET['announce'] ?? '0') === '1') {
    header('Content-Length: ' . $bytes);
}
header('Content-Type: application/octet-stream');
$chunk = str_repeat('a', 65536);
$left  = $bytes;
while ($left > 0) {
    $n = min($left, strlen($chunk));
    echo substr($chunk, 0, $n);
    $left -= $n;
    flush();
}
PHP);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->router];
        $this->server = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($this->server);
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $e, $s, 0.2);
            if ($c !== false) {
                fclose($c);
                return;
            }
            usleep(100000);
        }
        self::fail('el servidor de prueba no arrancó');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->router !== '' && is_file($this->router)) {
            unlink($this->router);
        }
    }

    private function fetch(int $bytes, bool $announce): array
    {
        $client = new SnapshotResponseLimitProbe('https://api.example.test', 'm', 'i', 's');

        return $client->fetch(sprintf('http://127.0.0.1:%d/?bytes=%d&announce=%d', $this->port, $bytes, $announce ? 1 : 0));
    }

    public function testABodyExactlyAtTheCapIsReadWhole(): void
    {
        foreach ([true, false] as $announce) {
            $r = $this->fetch(SnapshotClient::MAX_RESPONSE_BYTES, $announce);
            self::assertSame(200, $r['code'], 'announce=' . var_export($announce, true));
            self::assertSame('', $r['error']);
            self::assertSame(SnapshotClient::MAX_RESPONSE_BYTES, strlen($r['body']));
        }
    }

    public function testABodyOverTheCapAnnouncedByContentLengthIsRefused(): void
    {
        $r = $this->fetch(SnapshotClient::MAX_RESPONSE_BYTES + 1, true);

        self::assertSame('response_too_large', $r['error']);
        self::assertSame('', $r['body']);
        self::assertSame(0, $r['code']);
    }

    public function testABodyOverTheCapStreamedWithoutContentLengthIsAbandoned(): void
    {
        $r = $this->fetch(SnapshotClient::MAX_RESPONSE_BYTES * 3, false);

        self::assertSame('response_too_large', $r['error']);
        self::assertSame('', $r['body']);
    }

    public function testASmallBodyStillWorks(): void
    {
        $r = $this->fetch(10, false);

        self::assertSame(200, $r['code']);
        self::assertSame('aaaaaaaaaa', $r['body']);
    }
}
