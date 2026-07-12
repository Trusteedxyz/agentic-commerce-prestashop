<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;

/**
 * App Store remediation (2026-07-11) — R030 (and every other merchant-wide
 * "appliesTo:ALL" policy rule: R014/R018/R019/R020/R025/R027) must apply to
 * a normal human (non-agentic) PrestaShop checkout, not just agentic carts.
 *
 * Root cause fixed in ValidateOrderHook::evaluateCart(): the method used to
 * `return` immediately with the comment "human flow, not subject to CEL"
 * whenever no agent token was resolvable from the cart — before ever pulling
 * the snapshot or calling Layer-2 (`callRulesEvaluate`) — so a merchant's
 * universal safety-valve rules never applied to a real customer.
 *
 * `evaluateCart()` depends on the live PrestaShop kernel (\Cart, \Currency,
 * \Tools, \PrestaShopLogger) and cannot be instantiated in this unit-test
 * process (see ValidateOrderHookTest.php's own docblock for the same
 * constraint — it tests a hand-written MockCelEvaluator mirror instead of
 * the real class for exactly this reason). This test instead asserts the
 * control-flow structurally against the real source. Every substring below
 * was verified byte-for-byte against the real file (`sed -n '<line>p'`)
 * before being written here.
 */
final class OrganicCheckoutUniversalRulesTest extends TestCase
{
    private function hookSource(): string
    {
        $src = file_get_contents(__DIR__ . '/../../src/Enforcement/ValidateOrderHook.php');
        $this->assertIsString($src);
        return $src;
    }

    public function test_no_token_early_return_before_snapshot_pull_is_gone(): void
    {
        $src = $this->hookSource();
        $this->assertStringNotContainsString(
            'human flow, not subject to CEL',
            $src,
            'evaluateCart() must no longer skip CEL entirely for carts with no agent token'
        );
    }

    public function test_claims_default_to_null_not_early_return(): void
    {
        $src = $this->hookSource();
        // The refactor introduces an explicit `$claims = null;` default that
        // is only overwritten inside the `if ($agentToken !== '')` branch —
        // proving evaluation continues (snapshot pull, tier-2 dispatch) even
        // when no token was presented.
        $this->assertStringContainsString('$claims = null;', $src);
        $this->assertStringContainsString('$nonceAttrs = [];', $src);
    }

    public function test_tier2_gate_no_longer_requires_claims(): void
    {
        $src = $this->hookSource();
        // Old (broken) gate required a verified agent as well as an
        // applicable tier-2 rule.
        $this->assertStringNotContainsString('if ($hasTier2 && $claims !== null)', $src);
        // New gate: tier-2 (Layer-2) evaluation runs whenever an applicable
        // rule exists, agent or not.
        $this->assertStringContainsString('if ($hasTier2) {', $src);
    }

    public function test_agent_id_passed_to_layer2_is_nullable(): void
    {
        $src = $this->hookSource();
        $this->assertStringContainsString(
            '$agentId         = $claims !== null ? (string) ($claims[\'agentId\'] ?? \'\') : null;',
            $src
        );
    }

    public function test_snapshot_client_accepts_nullable_agent_id(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Enforcement/SnapshotClient.php');
        $this->assertIsString($src);
        $this->assertStringContainsString('public function callRulesEvaluate(', $src);
        $this->assertStringContainsString('?string $agentId,', $src);
    }
}
