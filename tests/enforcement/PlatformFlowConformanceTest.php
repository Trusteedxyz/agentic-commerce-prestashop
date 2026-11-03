<?php

declare(strict_types=1);

/**
 * Consume el fixture compartido del flujo principal
 * (packages/shared/src/enforcement/__fixtures__/platform-flow-conformance.json),
 * el mismo que valida la referencia TS `platform-flow.ts`.
 *
 * Cada bloque cuenta los vectores que EJECUTA: un bloque que no corre nada
 * también sale verde. `serverRouting` sí aplica a PrestaShop
 * ({@see RuleCode::needsServer()}), y `unavailableDecision` se filtra por el
 * vocabulario de este módulo (strict|balanced|permissive).
 *
 * Sin globals de PS salvo `TestPsState`/`\Configuration` del bootstrap, que
 * usa el test del resolutor real de modo.
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
        $path = __DIR__ . '/../../../shared/src/enforcement/__fixtures__/platform-flow-conformance.json';
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json, 'el fixture tiene que ser alcanzable desde el monorepo (monta la raíz)');
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
        // La referencia TS declara `prestashop: []`; los vectores
        // `ps-only-r001` y `ps-only-r007-now-server` lo fijan por conducta.
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

    /** Los vectores cuyo modo es de este vocabulario, contra el port puro. */
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

    /** Los 21 vectores contra el port puro, que compara EXACTAMENTE como la referencia. */
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
     * CARRY de la Task 1: el modo pasa por el resolutor REAL del módulo
     * (`MerchantResolver::getFallbackMode`, el que usa ValidateOrderHook) antes
     * de la política. Un valor de otro vocabulario (`observe`, `enforce`) o no
     * resuelto (vacío, `unknown`, `STRICT`, `Enforce`) cierra a un agente y no
     * toca a un humano; los tres del vocabulario se respetan.
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

    // ── Task 22: avería degradada y resolver de DID ─────────────────────────

    /** Los 22 vectores `degradedEnforcement` de PrestaShop contra el port puro. */
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
     * Los mismos 22, con el modo guardado en la configuración y resuelto por el
     * resolutor REAL (`MerchantResolver::getFallbackMode`) antes de la política,
     * como hace ValidateOrderHook. `""` sale `strict` y cierra, como pide el
     * vector `unresolved-empty`.
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

    /** Todos los vectores (100): el port puro compara exactamente como la referencia. */
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

    /** Los 19 vectores `snapshotResolverShape` contra el resolver del verificador. */
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

    /** El `agentDidResolver` del payload REAL es una lista y se resuelve. */
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
