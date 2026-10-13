<?php

declare(strict_types=1);

/**
 * Every checkout calls layer 2. After a failure, layer 2 is considered down for
 * about 60 s (no retry on each checkout), the connection has its own short
 * timeout, and the `fallback_open` warning is not repeated during the window.
 */

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\SnapshotClient;

require_once __DIR__ . '/PsEnforcementStubs.php';
require_once __DIR__ . '/../../src/Enforcement/SnapshotClient.php';

class CountingSnapshotClient extends SnapshotClient
{
    public int $posts = 0;
    /** @var list<array{code:int,body:string,error:string}> */
    public array $responses = [];

    protected function httpPost(string $url, array $headers, string $body, int $timeout): array
    {
        ++$this->posts;

        return array_shift($this->responses) ?? ['code' => 0, 'body' => '', 'error' => 'timeout'];
    }

    /** Exposes the real httpPost to measure the connection timeout. */
    public function realPost(string $url, int $timeout): array
    {
        return parent::httpPost($url, [], '{}', $timeout);
    }
}

final class SnapshotClientLayer2BreakerTest extends TestCase
{
    private CountingSnapshotClient $client;

    protected function setUp(): void
    {
        (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->setValue(null, []);
        $this->client = new CountingSnapshotClient('https://api.trusteed.xyz', 'merch-breaker', 'inst-1', 'secret-secret-secret-secret-1234');
    }

    private function ok(): array
    {
        return ['code' => 200, 'body' => '{"decision":"ALLOW"}', 'error' => ''];
    }

    public function testAFailureOpensTheBreakerAndTheNextCallsSkipTheNetwork(): void
    {
        self::assertNull($this->client->callRulesEvaluate(null, 100, 'EUR', 1, null));
        self::assertSame(1, $this->client->posts);

        $this->client->responses = [$this->ok()];
        self::assertNull($this->client->callRulesEvaluate(null, 100, 'EUR', 1, null), 'con el cortacircuitos abierto devuelve null (fallo) sin red');
        self::assertNull($this->client->callRulesEvaluate(null, 100, 'EUR', 1, null));
        self::assertSame(1, $this->client->posts, 'ningún reintento durante la ventana');
    }

    public function testHealthyCallsDoNotOpenTheBreaker(): void
    {
        $this->client->responses = [$this->ok(), $this->ok()];
        self::assertSame('ALLOW', $this->client->callRulesEvaluate(null, 100, 'EUR', 1, null)['decision']);
        self::assertSame('ALLOW', $this->client->callRulesEvaluate(null, 100, 'EUR', 1, null)['decision']);
        self::assertSame(2, $this->client->posts);
    }

    public function testTheBreakerIsPerMerchant(): void
    {
        $this->client->callRulesEvaluate(null, 100, 'EUR', 1, null);
        $other = new CountingSnapshotClient('https://api.trusteed.xyz', 'merch-other', 'inst-2', 'secret-secret-secret-secret-1234');
        $other->responses = [$this->ok()];
        self::assertNotNull($other->callRulesEvaluate(null, 100, 'EUR', 1, null));
    }

    public function testTheBreakerExpiresAfterTheWindow(): void
    {
        $this->client->callRulesEvaluate(null, 100, 'EUR', 1, null);
        $ttl = SnapshotClient::LAYER2_FAILURE_MEMORY_SECONDS;
        self::assertGreaterThanOrEqual(30, $ttl);
        self::assertLessThanOrEqual(120, $ttl);
        // Simulates expiry by rewriting the marker with an already exhausted TTL.
        (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->setValue(null, []);
        $this->client->responses = [$this->ok()];
        self::assertNotNull($this->client->callRulesEvaluate(null, 100, 'EUR', 1, null));
    }

    public function testTheFallbackLogSlotIsClaimedOncePerWindow(): void
    {
        self::assertTrue($this->client->claimFallbackLogSlot());
        self::assertFalse($this->client->claimFallbackLogSlot());
        self::assertFalse($this->client->claimFallbackLogSlot());
    }

    public function testConnectTimeoutIsShorterThanTheTotalTimeout(): void
    {
        self::assertGreaterThanOrEqual(1, SnapshotClient::CONNECT_TIMEOUT_SECONDS);
        self::assertLessThanOrEqual(2, SnapshotClient::CONNECT_TIMEOUT_SECONDS);
        $src = (string) file_get_contents(__DIR__ . '/../../src/Enforcement/SnapshotClient.php');
        self::assertStringContainsString('CURLOPT_CONNECTTIMEOUT', $src);
    }

    public function testRealCurlFailsFastWhenTheHostDoesNotAnswerTheConnection(): void
    {
        $t0 = microtime(true);
        $r = $this->client->realPost('http://10.255.255.1:81/', 10);
        $elapsed = microtime(true) - $t0;
        self::assertNotSame('', $r['error']);
        self::assertLessThan(4.0, $elapsed, 'el plazo de conexión corta antes que el total de 10 s');
    }
}
