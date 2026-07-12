<?php

declare(strict_types=1);

/**
 * SnapshotFallbackTest — Sprint C T13 (spec-048).
 *
 * Covers the fail-open mitigation documented in
 * `docs/analysis/rules_claude.md` §4.1 + §6.2 for the PrestaShop module:
 *
 *   Action 1 — FallbackMode default = Strict (no more silent fail-open).
 *   Action 2 — Local 24h snapshot cache reused when Layer-2 evaluate fails.
 *   Action 3 — Structured `[trusteed.snapshot.cache_fallback]` log line.
 *
 * Tests (mirror the audit specification):
 *   1. Network OK + fresh snapshot → no cache fallback path executed.
 *   2. Layer-2 5xx + cache TTL valid → eval local with cache, log emitted.
 *   3. Layer-2 timeout + cache stale (>24h) + FallbackMode=Strict → checkout
 *      rejected.
 *   4. Layer-2 timeout + cache stale + FallbackMode=Permissive → PASS with
 *      warn-log.
 *   5. No cache + Layer-2 down + Strict → checkout rejected.
 *   6. (Bonus) OBSERVE-mode rules never block, even on Strict + Layer-2 down
 *      + no cache.
 *
 * Run from repo root:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
 *     packages/magento-module-agenticmcpstores/vendor/bin/phpunit \
 *     packages/prestashop-module-agenticmcpstores/tests/enforcement/SnapshotFallbackTest.php
 */

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\SnapshotClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PsEnforcementStubs.php';
require_once __DIR__ . '/../../src/Enforcement/SnapshotClient.php';

/**
 * Test-double exposing httpPost() stubbing — mirrors PsSnapshotClientTest.
 */
class FallbackTestableSnapshotClient extends SnapshotClient
{
    /** @var list<array{code:int,body:string,error:string}> */
    private array $queuedHttpPostResponses = [];

    public function queueHttpPostResponse(int $code, string $body, string $error = ''): void
    {
        $this->queuedHttpPostResponses[] = ['code' => $code, 'body' => $body, 'error' => $error];
    }

    protected function httpPost(string $url, array $headers, string $body, int $timeout): array
    {
        if (empty($this->queuedHttpPostResponses)) {
            return ['code' => 0, 'body' => '', 'error' => 'no stub queued'];
        }

        return array_shift($this->queuedHttpPostResponses);
    }
}

/**
 * Orchestrator mirroring the cache-fallback decision flow in
 * PaymentModule::runCelEvaluation() and ValidateOrderHook::evaluateCart()
 * without the PrestaShop kernel.
 *
 * Decisions returned:
 *   - 'allow'             — Layer-2 ALLOW or Layer-2 fail + valid local cache.
 *   - 'allow_cached'      — Layer-2 fail + valid local cache (logs cache_fallback).
 *   - 'allow_fail_open'   — Layer-2 fail + no/stale cache + FallbackMode != strict.
 *   - 'block_fail_strict' — Layer-2 fail + no/stale cache + Strict + ≥1 ENFORCE rule.
 *   - 'block_layer2'      — Layer-2 returned BLOCK explicitly.
 */
final class CacheFallbackOrchestrator
{
    /** @var list<string> Structured log lines captured during evaluate(). */
    public array $log = [];

    public function __construct(
        private SnapshotClient $client,
        private string $merchantId,
        /** @var list<array{ruleCode:string,enabled:bool,mode?:string}> */
        private array $tier2Rules,
        private string $fallbackMode,
    ) {}

    /** @return array{decision:string} */
    public function evaluate(): array
    {
        // Simulate Layer-2 call.
        $layer2 = $this->client->callRulesEvaluate(
            'did:web:test.agent',
            5000,
            'EUR',
            2,
            70.0,
            []
        );

        if ($layer2 !== null) {
            if (($layer2['decision'] ?? 'ALLOW') === 'BLOCK') {
                return ['decision' => 'block_layer2'];
            }
            return ['decision' => 'allow'];
        }

        // Layer-2 unavailable — try the 24h local snapshot cache.
        $cached = $this->client->getLocalSnapshotCache();
        if ($cached !== null) {
            $this->log[] = sprintf(
                '[trusteed.snapshot.cache_fallback] {"merchantId":"%s","cacheAgeSeconds":%d,"reason":"layer2_unavailable"}',
                $this->merchantId,
                $cached['ageSeconds']
            );
            return ['decision' => 'allow_cached'];
        }

        // Cache stale or absent — apply FallbackMode.
        $hasEnforce = false;
        foreach ($this->tier2Rules as $rule) {
            if (!($rule['enabled'] ?? false)) {
                continue;
            }
            $mode = strtolower((string) ($rule['mode'] ?? 'enforce'));
            if ($mode !== 'observe') {
                $hasEnforce = true;
                break;
            }
        }

        if ($this->fallbackMode === 'strict' && $hasEnforce) {
            return ['decision' => 'block_fail_strict'];
        }

        // Permissive / Balanced / OBSERVE-only Strict → fail-open with warn-log.
        $this->log[] = sprintf(
            '[trusteed.snapshot.fallback_open] {"merchantId":"%s","fallbackMode":"%s","reason":"layer2_unavailable_no_cache"}',
            $this->merchantId,
            $this->fallbackMode
        );
        return ['decision' => 'allow_fail_open'];
    }
}

class SnapshotFallbackTest extends TestCase
{
    private FallbackTestableSnapshotClient $client;

    protected function setUp(): void
    {
        // Reset SnapshotClient static $memCache between tests so each test
        // sees a clean cache. We have to use reflection because the property
        // is private static.
        $ref  = new \ReflectionClass(SnapshotClient::class);
        $prop = $ref->getProperty('memCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->client = new FallbackTestableSnapshotClient(
            'https://api.trusteed.xyz',
            'merch-fallback-001',
            'install-fallback-001',
            'test-hmac-secret-32bytesxxxxxxxx'
        );
    }

    // ── Case 1: Network OK → no cache fallback ───────────────────────────────

    public function testNetworkOkDoesNotTriggerCacheFallback(): void
    {
        $this->client->queueHttpPostResponse(200, json_encode([
            'decision'     => 'ALLOW',
            'ruleCode'     => '',
            'reason'       => '',
            'evaluationId' => 'eval-ok',
        ]));

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]],
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame('allow', $result['decision']);
        $this->assertEmpty(
            $orch->log,
            'Network OK path must not emit any fallback structured log'
        );
    }

    // ── Case 2: Layer-2 5xx + cache TTL valid → eval local + log ─────────────

    public function testLayer2_5xxWithFreshCacheUsesCacheAndLogs(): void
    {
        // Seed a fresh (1h old) local snapshot cache.
        $oneHourAgo = time() - 3600;
        $this->client->seedLocalSnapshotCache('fake.jws.snapshot', $oneHourAgo);

        // Layer-2 returns 5xx → callRulesEvaluate() returns null.
        $this->client->queueHttpPostResponse(503, '{"error":"unavailable"}');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]],
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'allow_cached',
            $result['decision'],
            'Fresh cache must take precedence over FallbackMode policy'
        );
        $this->assertCount(1, $orch->log, 'Cache fallback must emit exactly one log line');
        $this->assertStringContainsString(
            '[trusteed.snapshot.cache_fallback]',
            $orch->log[0]
        );
        $this->assertStringContainsString(
            '"merchantId":"merch-fallback-001"',
            $orch->log[0]
        );
        $this->assertStringContainsString(
            '"reason":"layer2_unavailable"',
            $orch->log[0]
        );
        // cacheAgeSeconds should be ~3600 (allow a small skew).
        $this->assertMatchesRegularExpression(
            '/"cacheAgeSeconds":3[56789]\d{2}/',
            $orch->log[0]
        );
    }

    // ── Case 3: Layer-2 timeout + cache stale (>24h) + Strict → reject ───────

    public function testLayer2TimeoutCacheStaleStrictBlocks(): void
    {
        // Seed a cache entry 25h old → stale (>24h TTL).
        $twentyFiveHoursAgo = time() - (25 * 3600);
        $this->client->seedLocalSnapshotCache('fake.jws.snapshot', $twentyFiveHoursAgo);

        // Layer-2 curl error (timeout) → null.
        $this->client->queueHttpPostResponse(0, '', 'Connection timed out');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]], // ENFORCE mode (default)
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'block_fail_strict',
            $result['decision'],
            'Stale cache + Strict + ENFORCE rule must block (fail-closed)'
        );
        $this->assertEmpty(
            $orch->log,
            'block_fail_strict path must not emit fallback_open log'
        );
    }

    // ── Case 4: Layer-2 timeout + stale + Permissive → PASS + warn-log ───────

    public function testLayer2TimeoutCacheStalePermissiveFailsOpenWithLog(): void
    {
        // Stale (>24h) cache.
        $this->client->seedLocalSnapshotCache(
            'fake.jws.snapshot',
            time() - (25 * 3600)
        );
        $this->client->queueHttpPostResponse(0, '', 'Connection timed out');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]],
            'permissive'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'allow_fail_open',
            $result['decision'],
            'Permissive override honored: fail-open with warn-log'
        );
        $this->assertCount(1, $orch->log);
        $this->assertStringContainsString(
            '[trusteed.snapshot.fallback_open]',
            $orch->log[0]
        );
        $this->assertStringContainsString(
            '"fallbackMode":"permissive"',
            $orch->log[0]
        );
    }

    // ── Case 5: No prior cache + Layer-2 down + Strict → reject ──────────────

    public function testNoCacheLayer2DownStrictBlocks(): void
    {
        $this->client->queueHttpPostResponse(0, '', 'Connection refused');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]],
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'block_fail_strict',
            $result['decision'],
            'No cache + Layer-2 down + Strict must block'
        );
        $this->assertNull(
            $this->client->getLocalSnapshotCache(),
            'Sanity: no local snapshot cache exists for this test'
        );
    }

    // ── Case 6: OBSERVE-only Tier-2 rules never block (fail-open by design) ──

    public function testObserveModeRulesNeverBlockOnStrictFallback(): void
    {
        $this->client->queueHttpPostResponse(0, '', 'Connection refused');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [
                ['ruleCode' => 'R009', 'enabled' => true, 'mode' => 'observe'],
                ['ruleCode' => 'R010', 'enabled' => true, 'mode' => 'observe'],
            ],
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'allow_fail_open',
            $result['decision'],
            'OBSERVE-mode rules must never block even with Strict fallback'
        );
    }

    // ── SnapshotClient unit checks (no orchestrator) ─────────────────────────

    public function testGetLocalSnapshotCacheReturnsNullWhenStale(): void
    {
        $this->client->seedLocalSnapshotCache(
            'fake.jws.snapshot',
            time() - (25 * 3600)
        );

        $this->assertNull(
            $this->client->getLocalSnapshotCache(),
            'Stale entry (>24h) must be treated as cache miss'
        );
    }

    public function testGetLocalSnapshotCacheReturnsFreshEntry(): void
    {
        $this->client->seedLocalSnapshotCache(
            'fake.jws.snapshot',
            time() - 600 // 10 min old
        );

        $cached = $this->client->getLocalSnapshotCache();

        $this->assertNotNull($cached);
        $this->assertSame('fake.jws.snapshot', $cached['jws']);
        $this->assertGreaterThanOrEqual(600, $cached['ageSeconds']);
        $this->assertLessThan(700, $cached['ageSeconds']);
    }

    // ── P2: internally-expired snapshot (validUntil past) within 24h window ───

    /**
     * Build a JWS-shaped string carrying a payload with the given validUntil.
     * Signature segment is a dummy — getLocalSnapshotCache()'s staleness check
     * reads validUntil from the (already-verified-at-write-time) payload and
     * does not re-verify the signature.
     */
    private function makeSnapshotJws(int $validUntilTs): string
    {
        $header  = SnapshotClient::base64UrlEncode(
            json_encode(['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'JWS'], JSON_THROW_ON_ERROR)
        );
        $payload = SnapshotClient::base64UrlEncode(
            json_encode([
                'merchantId' => 'merch-fallback-001',
                'killSwitch' => true,
                'rules'      => [['ruleCode' => 'R001', 'tier' => 1, 'enabled' => true]],
                'validUntil' => date('c', $validUntilTs),
            ], JSON_THROW_ON_ERROR)
        );

        return $header . '.' . $payload . '.' . SnapshotClient::base64UrlEncode('dummy-signature');
    }

    /**
     * stale-fail-closed (spec-048 P2): a snapshot captured recently (fresh
     * file-age, < 24h) but whose OWN validUntil is already in the past must be
     * treated as a cache miss. Otherwise an expired kill-switch / tier-1
     * ruleset would be honoured offline for up to 24h.
     */
    public function testGetLocalSnapshotCacheReturnsNullWhenValidUntilExpired(): void
    {
        // Captured 10 min ago (well within the 24h file-age window) but the
        // snapshot's own validUntil expired 5 min ago.
        $jws = $this->makeSnapshotJws(time() - 300);
        $this->client->seedLocalSnapshotCache($jws, time() - 600);

        $this->assertNull(
            $this->client->getLocalSnapshotCache(),
            'Internally-expired snapshot must be treated as a cache miss even within 24h file-age'
        );
    }

    /**
     * End-to-end: Layer-2 down + cache fresh by file-age but internally expired
     * + Strict + tier-1/ENFORCE rule → checkout rejected (fail-closed). Without
     * the P2 fix this would have returned 'allow_cached'.
     */
    public function testExpiredCachedSnapshotStrictBlocks(): void
    {
        $jws = $this->makeSnapshotJws(time() - 120); // validUntil in the past
        $this->client->seedLocalSnapshotCache($jws, time() - 600); // fresh file-age

        $this->client->queueHttpPostResponse(0, '', 'Connection timed out');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]], // ENFORCE (default)
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'block_fail_strict',
            $result['decision'],
            'Internally-expired cached snapshot + Strict + ENFORCE must fail-closed'
        );
    }

    /**
     * Control: a cached snapshot whose validUntil is still in the future is
     * unchanged — it is reused offline as 'allow_cached'. Guards the P2 fix
     * against over-reaching into the still-valid path.
     */
    public function testValidCachedSnapshotStillUsedOffline(): void
    {
        $jws = $this->makeSnapshotJws(time() + 600); // still valid
        $this->client->seedLocalSnapshotCache($jws, time() - 600); // fresh file-age

        $this->client->queueHttpPostResponse(0, '', 'Connection timed out');

        $orch = new CacheFallbackOrchestrator(
            $this->client,
            'merch-fallback-001',
            [['ruleCode' => 'R009', 'enabled' => true]],
            'strict'
        );

        $result = $orch->evaluate();

        $this->assertSame(
            'allow_cached',
            $result['decision'],
            'Still-valid cached snapshot must remain usable offline'
        );
    }
}
