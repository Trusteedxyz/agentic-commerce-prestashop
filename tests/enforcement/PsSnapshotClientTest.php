<?php

declare(strict_types=1);

/**
 * PsSnapshotClientTest — PHPUnit 10 tests for callRulesEvaluate() (spec-048 Capa 2)
 * and computeTtl() (spec-048 P2 / PL-F7 stale-fail-closed fix).
 *
 * SnapshotClient::httpPost() is protected so we can override it in a test
 * subclass without touching production curl calls.
 *
 * Tests:
 *   T078-7  callRulesEvaluate() returns null when httpPost returns curl error
 *   T078-8  callRulesEvaluate() returns null on non-200 HTTP status
 *   T078-9  callRulesEvaluate() returns structured array on ALLOW decision
 *   T078-10 callRulesEvaluate() returns BLOCK payload with ruleCode and reason
 *   T078-11 callRulesEvaluate() returns null when response body has no 'decision' key
 *   PL-F7   computeTtl() no longer clamps an expired/near-expired validUntil
 *           up to a 60s floor (mirrors the WooCommerce fix in
 *           packages/wp-plugin-agenticmcpstores/includes/enforcement/snapshot-client.php)
 *
 * Run from repo root:
 *   vendor/bin/phpunit \
 *     packages/prestashop-module-agenticmcpstores/tests/enforcement/PsSnapshotClientTest.php
 */

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\SnapshotClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PsEnforcementStubs.php';
require_once __DIR__ . '/../../src/Enforcement/SnapshotClient.php';

/**
 * Test-double that replaces httpPost() with pre-configured responses.
 * All other SnapshotClient behaviour (HMAC signing, JSON encoding, guards) runs as-is.
 */
class TestableSnapshotClient extends SnapshotClient
{
    /** @var list<array{code:int,body:string,error:string}> */
    private array $stubbedResponses = [];

    /** Queue a response to be returned by the next httpPost() call. */
    public function queueHttpPostResponse(int $code, string $body, string $error = ''): void
    {
        $this->stubbedResponses[] = ['code' => $code, 'body' => $body, 'error' => $error];
    }

    protected function httpPost(string $url, array $headers, string $body, int $timeout): array
    {
        if (empty($this->stubbedResponses)) {
            return ['code' => 0, 'body' => '', 'error' => 'no stub queued'];
        }

        return array_shift($this->stubbedResponses);
    }
}

class PsSnapshotClientTest extends TestCase
{
    private TestableSnapshotClient $client;

    protected function setUp(): void
    {
        $this->client = new TestableSnapshotClient(
            'https://api.trusteed.xyz',
            'merch-ps-001',
            'install-ps-001',
            'test-hmac-secret-32bytesxxxxxxxx'
        );
    }

    // ── T078-7: curl error → null ─────────────────────────────────────────────

    public function testCallRulesEvaluateReturnsNullOnCurlError(): void
    {
        $this->client->queueHttpPostResponse(0, '', 'Connection refused');

        $result = $this->client->callRulesEvaluate('did:web:agent.example', 5000, 'EUR', 2, 70.0);

        $this->assertNull($result, 'callRulesEvaluate() must return null when httpPost returns curl error');
    }

    // ── T078-8: non-200 HTTP status → null ───────────────────────────────────

    public function testCallRulesEvaluateReturnsNullOnNon200(): void
    {
        $this->client->queueHttpPostResponse(503, '{"error":"unavailable"}');

        $result = $this->client->callRulesEvaluate('did:web:agent.example', 5000, 'EUR', 2, 70.0);

        $this->assertNull($result, 'callRulesEvaluate() must return null when HTTP status is not 200');
    }

    // ── T078-9: ALLOW decision ────────────────────────────────────────────────

    public function testCallRulesEvaluateReturnsAllowDecision(): void
    {
        $responseBody = json_encode([
            'decision'     => 'ALLOW',
            'ruleCode'     => '',
            'reason'       => '',
            'evaluationId' => 'eval-allow-ps-001',
        ], JSON_THROW_ON_ERROR);

        $this->client->queueHttpPostResponse(200, $responseBody);

        $result = $this->client->callRulesEvaluate('did:web:agent.example', 5000, 'EUR', 2, 70.0);

        $this->assertIsArray($result, 'callRulesEvaluate() must return an array on 200 ALLOW');
        $this->assertSame('ALLOW', $result['decision']);
        $this->assertSame('eval-allow-ps-001', $result['evaluationId']);
    }

    // ── T078-10: BLOCK decision with ruleCode ────────────────────────────────

    public function testCallRulesEvaluateReturnsBlockDecision(): void
    {
        $responseBody = json_encode([
            'decision'     => 'BLOCK',
            'ruleCode'     => 'trusteed:R009',
            'reason'       => 'Dispute rate exceeds threshold',
            'evaluationId' => 'eval-block-ps-002',
        ], JSON_THROW_ON_ERROR);

        $this->client->queueHttpPostResponse(200, $responseBody);

        $result = $this->client->callRulesEvaluate('did:web:agent.example', 200000, 'EUR', 8, 55.0);

        $this->assertIsArray($result);
        $this->assertSame('BLOCK', $result['decision']);
        $this->assertSame('trusteed:R009', $result['ruleCode']);
        $this->assertSame('Dispute rate exceeds threshold', $result['reason']);
        $this->assertSame('eval-block-ps-002', $result['evaluationId']);
    }

    // ── T078-11: response missing 'decision' key → null ──────────────────────

    public function testCallRulesEvaluateReturnsNullWhenDecisionKeyMissing(): void
    {
        $this->client->queueHttpPostResponse(200, '{"status":"ok"}');

        $result = $this->client->callRulesEvaluate('did:web:agent.example', 1000, 'USD', 1, null);

        $this->assertNull($result, 'callRulesEvaluate() must return null when decision key is absent');
    }

    // ── PL-F7: computeTtl() no longer floors an expired/near-expired ────────
    // ── validUntil up to 60s (mirrors the WooCommerce stale-fail-closed fix) ─

    /**
     * A snapshot whose validUntil is already in the past must yield a
     * non-positive TTL so pull() routes it into the stale-fail-closed
     * fallback path (see pull()'s `if ($ttl <= 0) { ... }` guard). The old
     * `max(60, min(300, $until - time()))` clamp would have forced this back
     * up to 60, letting an expired kill-switch / tier-1 ruleset snapshot
     * keep being served from cache for up to 60s past expiry.
     */
    public function testComputeTtlReturnsNonPositiveForExpiredValidUntil(): void
    {
        $payload = ['validUntil' => date('c', time() - 120)];

        $ttl = $this->invokeComputeTtl($payload);

        $this->assertLessThanOrEqual(0, $ttl, 'computeTtl() must not clamp an expired validUntil to a positive TTL');
    }

    /**
     * A snapshot expiring a few seconds from now must get a short TTL that
     * reflects its real remaining lifetime, NOT the old 60s floor.
     */
    public function testComputeTtlDoesNotFloorNearExpiryToSixtySeconds(): void
    {
        $payload = ['validUntil' => date('c', time() + 5)];

        $ttl = $this->invokeComputeTtl($payload);

        $this->assertGreaterThan(0, $ttl, 'a still-valid (future) validUntil must yield a positive TTL');
        $this->assertLessThan(60, $ttl, 'computeTtl() must not floor a near-expiry TTL up to 60s');
    }

    /**
     * Control: the 300s ceiling on a still-valid, far-future snapshot is
     * unchanged by this fix — only the floor was removed.
     */
    public function testComputeTtlStillClampsCeilingAtThreeHundredSeconds(): void
    {
        $payload = ['validUntil' => date('c', time() + 1000)];

        $ttl = $this->invokeComputeTtl($payload);

        $this->assertSame(300, $ttl, 'computeTtl() must still clamp the ceiling at 300s');
    }

    /**
     * Control: a snapshot missing validUntil entirely still defaults to 300s.
     */
    public function testComputeTtlDefaultsToThreeHundredWhenValidUntilMissing(): void
    {
        $ttl = $this->invokeComputeTtl([]);

        $this->assertSame(300, $ttl, 'computeTtl() must default to 300s when validUntil is absent');
    }

    /**
     * Invoke the private SnapshotClient::computeTtl() via reflection.
     *
     * @param array<string, mixed> $payload
     */
    private function invokeComputeTtl(array $payload): int
    {
        $method = new \ReflectionMethod(SnapshotClient::class, 'computeTtl');
        $method->setAccessible(true);

        /** @var int $result */
        $result = $method->invoke($this->client, $payload);

        return $result;
    }
}
