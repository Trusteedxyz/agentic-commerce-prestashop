<?php

declare(strict_types=1);

/**
 * Consumes the shared conformance fixture for the main flow
 * (platform-flow-conformance.json), the same one the TS reference
 * `platform-flow.ts` is validated against.
 *
 * Each block counts the vectors it EXECUTES: a block that runs nothing would
 * also come out green. `serverRouting` does apply to PrestaShop
 * ({@see RuleCode::needsServer()}), and `unavailableDecision` is filtered by
 * this module's vocabulary (strict|balanced|permissive).
 *
 * No PS globals except `TestPsState`/`\Configuration` from the bootstrap,
 * which the real mode-resolver test uses.
 */

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use TestPsState;
use Trusteed\Enforcement\FailurePolicy;
use Trusteed\Enforcement\MerchantResolver;
use Trusteed\Enforcement\RuleCode;
use Trusteed\Enforcement\TokenVerifier;

final class PlatformFlowConformanceTest extends TestCase
{
    protected function setUp(): void
    {
        TestPsState::reset();
    }

    protected function tearDown(): void
    {
        TestPsState::reset();
    }

    private static function fixture(): array
    {
        $path = __DIR__ . '/../fixtures/platform-flow-conformance.json';
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json, 'the conformance fixture must exist under tests/fixtures');
        return $json;
    }

    public function testAgenticDetectionVectors(): void
    {
        $run = 0;
        foreach (self::fixture()['agenticDetection'] as $v) {
            $s = $v['signals'];
            self::assertSame(
                $v['expected'],
                FailurePolicy::isAgentic($s['agentId'], $s['tokenPresent'], $s['originMarker']),
                $v['id']
            );
            $run++;
        }
        self::assertSame(6, $run, 'vectores agenticDetection ejecutados');
    }

    public function testServerRoutingVectorsForPrestashop(): void
    {
        $run = 0;
        foreach (self::fixture()['serverRouting'] as $v) {
            if ($v['port'] !== 'prestashop') {
                continue;
            }
            self::assertSame($v['expected'], RuleCode::needsServer($v['rules']), $v['id']);
            $run++;
        }
        self::assertSame(11, $run, 'vectores serverRouting de prestashop ejecutados');
    }

    public function testLocalListMatchesTheFixtureVocabularyOfTheReference(): void
    {
        // The TS reference declares `prestashop: []`; the `ps-only-r001` and
        // `ps-only-r007-now-server` vectors pin it by behaviour.
        self::assertSame([], RuleCode::LOCAL_ONLY_SHORT_CODES);
    }

    public function testOfflineValveScopeVectors(): void
    {
        $run = 0;
        foreach (self::fixture()['offlineValveScope'] as $v) {
            $codes = array_map(
                static fn (array $r): string => $r['ruleCode'],
                FailurePolicy::offlineRulesFor($v['rules'], $v['isAgentic'])
            );
            self::assertSame($v['expectedCodes'], $codes, $v['id']);
            $run++;
        }
        self::assertSame(6, $run, 'vectores offlineValveScope ejecutados');
    }

    public function testOfflineRulesForDoesNotMutateItsInput(): void
    {
        $rules = [
            ['ruleCode' => 'R029', 'enabled' => true, 'appliesTo' => 'AGENT'],
            ['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'ALL'],
        ];
        $copy = $rules;
        $out  = FailurePolicy::offlineRulesFor($rules, false);
        self::assertSame($copy, $rules);
        self::assertSame([['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'ALL']], $out, 'reindexado desde 0');
    }

    /** The vectors whose mode is in this vocabulary, against the pure port. */
    public function testUnavailableDecisionVectorsForThisVocabulary(): void
    {
        $fx    = self::fixture();
        $modes = $fx['_vocabulary']['failureModes']['prestashop'];
        self::assertSame(FailurePolicy::FAILURE_MODES, $modes, 'vocabulario del port = fixture');

        $run = 0;
        foreach ($fx['unavailableDecision'] as $v) {
            if (!in_array($v['failureMode'], $modes, true)) {
                continue;
            }
            self::assertSame(
                $v['expected'],
                FailurePolicy::decideUnavailable($v['isAgentic'], $v['failureMode'], $v['offlineBlock']),
                $v['id']
            );
            $run++;
        }
        self::assertSame(7, $run, 'vectores unavailableDecision de este vocabulario ejecutados');
    }

    /** The 21 vectors against the pure port, which compares EXACTLY like the reference. */
    public function testEveryUnavailableDecisionVectorAgainstThePurePort(): void
    {
        $run = 0;
        foreach (self::fixture()['unavailableDecision'] as $v) {
            self::assertSame(
                $v['expected'],
                FailurePolicy::decideUnavailable($v['isAgentic'], $v['failureMode'], $v['offlineBlock']),
                $v['id']
            );
            $run++;
        }
        self::assertSame(21, $run, 'vectores unavailableDecision ejecutados');
    }

    /**
     * The mode goes through the module's REAL resolver
     * (`MerchantResolver::getFallbackMode`, the one ValidateOrderHook uses)
     * before the policy. A value from another vocabulary (`observe`, `enforce`)
     * or an unresolved one (empty, `unknown`, `STRICT`, `Enforce`) closes on an
     * agent and leaves a human alone; the three in the vocabulary are honoured.
     */
    public function testFailureModeResolvedByTheRealResolverClosesAgentsOnly(): void
    {
        $fx      = self::fixture();
        $vocab   = $fx['_vocabulary']['failureModes']['prestashop'];
        $opening = $fx['_vocabulary']['agentOpeningFailureModes']['prestashop'];

        $run = 0;
        foreach ($vocab as $mode) {
            TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = $mode;
            $resolved = MerchantResolver::getFallbackMode();
            self::assertSame($mode, $resolved);
            self::assertSame(
                in_array($mode, $opening, true) ? 'ALLOW' : 'BLOCK',
                FailurePolicy::decideUnavailable(true, $resolved, false),
                'agente con ' . $mode
            );
            self::assertSame('ALLOW', FailurePolicy::decideUnavailable(false, $resolved, false), 'humano con ' . $mode);
            $run++;
        }

        $foreign = [];
        foreach ($fx['_vocabulary']['failureModes'] as $modes) {
            foreach ($modes as $mode) {
                if (!in_array($mode, $vocab, true)) {
                    $foreign[$mode] = true;
                }
            }
        }
        self::assertSame(['enforce', 'observe'], array_keys($foreign));

        $samples = array_merge(array_keys($foreign), $fx['_vocabulary']['unresolvedFailureModeSamples']);
        foreach ($samples as $raw) {
            TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = $raw;
            $resolved = MerchantResolver::getFallbackMode();
            self::assertSame('BLOCK', FailurePolicy::decideUnavailable(true, $resolved, false), 'agente con ' . var_export($raw, true));
            self::assertSame('ALLOW', FailurePolicy::decideUnavailable(false, $resolved, false), 'humano con ' . var_export($raw, true));
            $run++;
        }

        unset(TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE']);
        $absent = MerchantResolver::getFallbackMode();
        self::assertSame('BLOCK', FailurePolicy::decideUnavailable(true, $absent, false), 'agente con modo ausente');
        self::assertSame('ALLOW', FailurePolicy::decideUnavailable(false, $absent, false), 'humano con modo ausente');
        $run++;

        self::assertSame(3 + 2 + 4 + 1, $run, 'modos ejecutados: vocabulario + ajenos + muestras + ausente');
    }

    // ── Degraded failure and DID resolver ───────────────────────────────────

    /** The 22 PrestaShop `degradedEnforcement` vectors against the pure port. */
    public function testDegradedEnforcementVectorsForPrestashop(): void
    {
        $run = 0;
        foreach (self::fixture()['degradedEnforcement']['vectors'] as $v) {
            if ($v['port'] !== 'prestashop') {
                continue;
            }
            self::assertSame(
                $v['expected'],
                FailurePolicy::decideOnDegradedEnforcement(
                    $v['kind'],
                    $v['isAgentic'],
                    $v['tokenPresent'],
                    $v['failureMode'],
                    $v['offlineBlock']
                ),
                $v['id']
            );
            $run++;
        }
        self::assertSame(22, $run, 'vectores degradedEnforcement de prestashop ejecutados');
    }

    /**
     * The same 22, with the mode stored in the configuration and resolved by
     * the REAL resolver (`MerchantResolver::getFallbackMode`) before the policy,
     * as ValidateOrderHook does. `""` comes out `strict` and closes, as the
     * `unresolved-empty` vector requires.
     */
    public function testDegradedEnforcementVectorsThroughTheRealModeResolver(): void
    {
        $run = 0;
        foreach (self::fixture()['degradedEnforcement']['vectors'] as $v) {
            if ($v['port'] !== 'prestashop') {
                continue;
            }
            TestPsState::$config['TRUSTEED_CEL_FALLBACK_MODE'] = $v['failureMode'];
            self::assertSame(
                $v['expected'],
                FailurePolicy::decideOnDegradedEnforcement(
                    $v['kind'],
                    $v['isAgentic'],
                    $v['tokenPresent'],
                    MerchantResolver::getFallbackMode(),
                    $v['offlineBlock']
                ),
                $v['id'] . ' (modo resuelto)'
            );
            $run++;
        }
        self::assertSame(22, $run, 'vectores degradedEnforcement por el resolutor real');
    }

    /** All the vectors (100): the pure port compares exactly like the reference. */
    public function testEveryDegradedEnforcementVectorAgainstThePurePort(): void
    {
        $run = 0;
        foreach (self::fixture()['degradedEnforcement']['vectors'] as $v) {
            self::assertSame(
                $v['expected'],
                FailurePolicy::decideOnDegradedEnforcement(
                    $v['kind'],
                    $v['isAgentic'],
                    $v['tokenPresent'],
                    $v['failureMode'],
                    $v['offlineBlock']
                ),
                $v['id']
            );
            $run++;
        }
        self::assertSame(100, $run, 'vectores degradedEnforcement ejecutados');
        self::assertSame(
            self::fixture()['_vocabulary']['failureModes']['prestashop'],
            FailurePolicy::FAILURE_MODES
        );
    }

    public function testDegradedKindsMatchTheReference(): void
    {
        $kinds = [];
        foreach (self::fixture()['degradedEnforcement']['vectors'] as $v) {
            $kinds[$v['kind']] = true;
        }
        self::assertEqualsCanonicalizing(array_keys($kinds), FailurePolicy::DEGRADED_KINDS);
        self::assertSame('BLOCK', FailurePolicy::decideOnDegradedEnforcement('texto crudo', true, false, 'permissive', false), 'causa desconocida = invalid_signature');
        self::assertSame('ALLOW', FailurePolicy::decideOnDegradedEnforcement('texto crudo', false, false, 'strict', true), 'humano con causa desconocida');
    }

    /** The 19 `snapshotResolverShape` vectors against the verifier's resolver. */
    public function testSnapshotResolverShapeVectors(): void
    {
        $run = 0;
        foreach (self::fixture()['snapshotResolverShape']['vectors'] as $v) {
            $got = TokenVerifier::resolveAgentDidPublicKey($v['resolver'], $v['did']);
            if ($v['expected'] === null) {
                self::assertNull($got, $v['id']);
            } else {
                self::assertEquals($v['expected'], $got, $v['id']);
                self::assertSame(count($v['expected']), count((array) $got), $v['id'] . ': sin campos de más');
            }
            $run++;
        }
        self::assertSame(19, $run, 'vectores snapshotResolverShape ejecutados');
    }

    /** The REAL payload's `agentDidResolver` is a list and it resolves. */
    public function testRealSnapshotPayloadResolverIsAListAndResolves(): void
    {
        $payload  = self::fixture()['snapshotResolverShape']['snapshotPayload'];
        $resolver = $payload['agentDidResolver'];
        self::assertTrue(array_is_list($resolver));
        foreach ($resolver as $entry) {
            self::assertSame(
                $entry['publicKeyJwk']['x'],
                TokenVerifier::resolveAgentDidPublicKey($resolver, $entry['did'])['x'] ?? null,
                $entry['did']
            );
        }
    }
}
