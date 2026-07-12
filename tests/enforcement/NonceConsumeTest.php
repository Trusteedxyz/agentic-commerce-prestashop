<?php

declare(strict_types=1);

/**
 * NonceConsumeTest — spec-048 P2.8 single-use replay protection (PrestaShop).
 *
 * Covers SnapshotClient::consumeNonce() with the same four cases exercised by
 * the WooCommerce class-enforcement-api-client tests:
 *   1. Happy path (HTTP 200 → ACCEPTED)
 *   2. Replay (HTTP 409 → REPLAY)
 *   3. Network failure / 5xx → INDETERMINATE (caller maps via failure_mode)
 *   4. Network failure with explicit observe mapping (still INDETERMINATE here;
 *      the failure_mode mapping happens in ValidateOrderHook).
 *
 * Run from repo root:
 *   vendor/bin/phpunit \
 *     packages/prestashop-module-agenticmcpstores/tests/enforcement/NonceConsumeTest.php
 */

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\NonceOutcome;
use Trusteed\Enforcement\SnapshotClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PsEnforcementStubs.php';
require_once __DIR__ . '/../../src/Enforcement/SnapshotClient.php';

/**
 * Test double — stubs httpPost() to avoid real curl. Mirrors the helper class
 * used by PsSnapshotClientTest so HMAC signing + payload JSON still execute.
 */
class NonceTestableSnapshotClient extends SnapshotClient
{
    /** @var list<array{code:int,body:string,error:string}> */
    private array $queued = [];

    /** @var list<array{url:string,headers:array,body:string}> */
    public array $lastCalls = [];

    public function queue(int $code, string $body = '', string $error = ''): void
    {
        $this->queued[] = ['code' => $code, 'body' => $body, 'error' => $error];
    }

    protected function httpPost(string $url, array $headers, string $body, int $timeout): array
    {
        $this->lastCalls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if (empty($this->queued)) {
            return ['code' => 0, 'body' => '', 'error' => 'no stub queued'];
        }
        return array_shift($this->queued);
    }
}

class NonceConsumeTest extends TestCase
{
    private NonceTestableSnapshotClient $client;

    private const TEST_JTI = 'abcdefghijklmnop1234'; // 20 chars — passes JTI_RE

    protected function setUp(): void
    {
        $this->client = new NonceTestableSnapshotClient(
            'https://api.trusteed.xyz',
            'merch-ps-nonce-001',
            'install-ps-nonce-001',
            'test-hmac-secret-32bytesxxxxxxxx'
        );
    }

    /** Case 1 — HTTP 200 → ACCEPTED. */
    public function testConsumeNonceAcceptedOn200(): void
    {
        $this->client->queue(200, '{"status":"accepted"}');

        $result = $this->client->consumeNonce(
            'did:web:agent.example',
            self::TEST_JTI,
            time() + 60
        );

        $this->assertSame(NonceOutcome::ACCEPTED, $result['outcome']);
        $this->assertSame(200, $result['httpStatus']);

        // Verify the request was correctly built.
        $this->assertNotEmpty($this->client->lastCalls);
        $call = $this->client->lastCalls[0];
        $this->assertStringContainsString('/v1/agent-events/nonce-consume', $call['url']);
        $this->assertArrayHasKey('X-Trusteed-Signature', $call['headers']);
        $this->assertArrayHasKey('X-Trusteed-Installation-Id', $call['headers']);
        $payload = json_decode($call['body'], true);
        $this->assertSame('merch-ps-nonce-001', $payload['merchantId']);
        $this->assertSame(self::TEST_JTI, $payload['nonce']);
        $this->assertSame('did:web:agent.example', $payload['agentId']);
        $this->assertArrayHasKey('expiresAt', $payload);
    }

    /** Case 2 — HTTP 409 → REPLAY. */
    public function testConsumeNonceReplayOn409(): void
    {
        $this->client->queue(409, '{"error":"replay_detected"}');

        $result = $this->client->consumeNonce(
            'did:web:agent.example',
            self::TEST_JTI,
            time() + 60
        );

        $this->assertSame(NonceOutcome::REPLAY, $result['outcome']);
        $this->assertSame(409, $result['httpStatus']);
        $this->assertSame('replay_detected', $result['reason']);
    }

    /** Case 3 — network error (curl) → INDETERMINATE (caller maps per failure_mode). */
    public function testConsumeNonceIndeterminateOnNetworkError(): void
    {
        $this->client->queue(0, '', 'Connection refused');

        $result = $this->client->consumeNonce(
            'did:web:agent.example',
            self::TEST_JTI,
            time() + 60
        );

        $this->assertSame(NonceOutcome::INDETERMINATE, $result['outcome']);
        $this->assertSame('network_error', $result['reason']);
        $this->assertNull($result['httpStatus']);
    }

    /** Case 4 — HTTP 5xx → INDETERMINATE (ValidateOrderHook maps to BLOCK in strict / ALLOW in balanced). */
    public function testConsumeNonceIndeterminateOn5xx(): void
    {
        $this->client->queue(503, '{"error":"service_unavailable"}');

        $result = $this->client->consumeNonce(
            'did:web:agent.example',
            self::TEST_JTI,
            0 // exp=0 → expiresAt falls back to now+300
        );

        $this->assertSame(NonceOutcome::INDETERMINATE, $result['outcome']);
        $this->assertSame('http_5xx', $result['reason']);
        $this->assertSame(503, $result['httpStatus']);

        // Verify expiresAt fallback when exp=0.
        $payload = json_decode($this->client->lastCalls[0]['body'], true);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['expiresAt']);
    }
}
