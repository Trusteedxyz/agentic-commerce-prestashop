<?php

declare(strict_types=1);

/**
 * Rule-code normalisation for the PrestaShop CEL path.
 *
 * The defect this pins: `merchant_trust_rules.ruleCode` holds BOTH the bare
 * short code and the canonical dotted form, and the hook compared the raw
 * value against short literals. On a canonical merchant `$hasTier2` came out
 * false, layer 2 was never called, and the layer-1 switch fell through to its
 * `default`: the offline layer was inert with no error anywhere.
 *
 * `RuleCode` is deliberately free of the PS kernel, so unlike
 * `ValidateOrderHook` (guarded by `_PS_VERSION_`) it can be exercised for
 * real here instead of through a mock mirror.
 *
 * Run from the module root:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
 *     php vendor/bin/phpunit tests/enforcement/RuleCodeTest.php
 */

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\RuleCode;

require_once __DIR__ . '/../../src/Enforcement/RuleCode.php';

class RuleCodeTest extends TestCase
{
    public function testShortCodeStripsTheCanonicalSuffix(): void
    {
        $this->assertSame('R030', RuleCode::shortCode('R030.simple-controls'));
        $this->assertSame('R001', RuleCode::shortCode('R001.verified-agent-required'));
    }

    public function testShortCodeLeavesABareCodeUntouched(): void
    {
        $this->assertSame('R030', RuleCode::shortCode('R030'));
    }

    public function testShortCodeIsTotalAndIdempotent(): void
    {
        foreach (['', 'NOT_A_RULE', 'R015', 'R015.price-change-guard'] as $code) {
            $once = RuleCode::shortCode($code);
            $this->assertSame($once, RuleCode::shortCode($once));
        }
        $this->assertSame('', RuleCode::shortCode(''));
        $this->assertSame('NOT_A_RULE', RuleCode::shortCode('NOT_A_RULE'));
    }

    /** THE defect: server routing must hold in both code formats. */
    public function testServerRuleMembershipIsFormatAgnostic(): void
    {
        $this->assertTrue(RuleCode::isServerRule('R015'));
        $this->assertTrue(RuleCode::isServerRule('R015.price-change-guard'));
        $this->assertTrue(RuleCode::isServerRule('R030.simple-controls'));
    }

    /**
     * R001 and R007 are routed to layer 2 too. R001 with verified claims always
     * comes out ALLOW and R007 is ALLOW by design: nothing local can block.
     * Excluding them only meant a merchant whose sole rule is R001 never
     * reached the server.
     */
    public function testEveryCodeIsAServerRule(): void
    {
        $this->assertSame([], RuleCode::LOCAL_ONLY_SHORT_CODES);
        $this->assertTrue(RuleCode::isServerRule('R001'));
        $this->assertTrue(RuleCode::isServerRule('R001.verified-agent-required'));
        $this->assertTrue(RuleCode::isServerRule('R007.cross-merchant-abuse-signal'));
        $this->assertTrue(RuleCode::isServerRule('R035.max-order-value'));
        $this->assertTrue(RuleCode::isServerRule('R062'));
    }

    /**
     * @dataProvider canonicalAndShortRows
     */
    public function testNeedsServerSeesBothFormats(string $ruleCode): void
    {
        $rules = [['ruleCode' => $ruleCode, 'enabled' => true, 'mode' => 'enforce']];

        $this->assertTrue(RuleCode::needsServer($rules));
    }

    /** @return array<string, array{0: string}> */
    public static function canonicalAndShortRows(): array
    {
        return [
            'short'     => ['R015'],
            'canonical' => ['R015.price-change-guard'],
        ];
    }

    /** A merchant with only R035 must reach the server. */
    public function testOnlyR035StillReachesTheServer(): void
    {
        $this->assertTrue(RuleCode::needsServer([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]));
    }

    public function testNeedsServerIgnoresDisabledAndMalformedRows(): void
    {
        $this->assertFalse(RuleCode::needsServer([
            ['ruleCode' => 'R030', 'enabled' => false],
            ['ruleCode' => 'R015.price-change-guard', 'enabled' => '0'],
            ['ruleCode' => 'R035'],
            'garbage',
        ]));
    }

    public function testHasEnforcingServerRuleIgnoresObserveRows(): void
    {
        $observeOnly = [
            ['ruleCode' => 'R030', 'enabled' => true, 'mode' => 'observe'],
        ];
        $mixed = [
            ['ruleCode' => 'R015.price-change-guard', 'enabled' => true, 'mode' => 'observe'],
            ['ruleCode' => 'R030.simple-controls', 'enabled' => true, 'mode' => 'ENFORCE'],
        ];

        $this->assertTrue(RuleCode::needsServer($observeOnly));
        $this->assertFalse(RuleCode::hasEnforcingServerRule($observeOnly));
        $this->assertTrue(RuleCode::hasEnforcingServerRule($mixed));
    }

    public function testAbsentModeDefaultsToEnforcing(): void
    {
        $this->assertTrue(RuleCode::hasEnforcingServerRule([
            ['ruleCode' => 'R030.simple-controls', 'enabled' => true],
        ]));
    }
}
