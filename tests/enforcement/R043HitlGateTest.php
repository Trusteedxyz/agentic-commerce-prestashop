<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\R043HitlGate;
use PHPUnit\Framework\TestCase;

/**
 * Spec-048 Sprint E.2 T-E45 — R043 HITL Gate (PrestaShop).
 */
final class R043HitlGateTest extends TestCase
{
    public function test_detects_canonical_hitl_response(): void
    {
        $resp = [
            'decision' => 'BLOCK',
            'ucp' => [
                'state' => 'requires_escalation',
                'reason_code' => 'trusteed:R043.agent-checkout-approval-required',
            ],
        ];
        self::assertTrue(R043HitlGate::isHitlResponse($resp));
    }

    public function test_handles_object_input(): void
    {
        $resp = (object) [
            'decision' => 'BLOCK',
            'ucp' => (object) [
                'state' => 'requires_escalation',
                'reason_code' => 'trusteed:R043.x',
            ],
        ];
        self::assertTrue(R043HitlGate::isHitlResponse($resp));
    }

    public function test_rejects_block_without_escalation(): void
    {
        $resp = ['decision' => 'BLOCK', 'ucp' => ['state' => 'failed', 'reason_code' => 'trusteed:R001']];
        self::assertFalse(R043HitlGate::isHitlResponse($resp));
    }

    public function test_rejects_allow(): void
    {
        $resp = ['decision' => 'ALLOW', 'ucp' => ['state' => 'requires_escalation', 'reason_code' => 'trusteed:R043']];
        self::assertFalse(R043HitlGate::isHitlResponse($resp));
    }

    public function test_rejects_other_rule_codes(): void
    {
        $resp = ['decision' => 'BLOCK', 'ucp' => ['state' => 'requires_escalation', 'reason_code' => 'trusteed:R031']];
        self::assertFalse(R043HitlGate::isHitlResponse($resp));
    }

    public function test_rule_code_from_strips_prefix(): void
    {
        $resp = ['ucp' => ['reason_code' => 'trusteed:R043.agent-checkout-approval-required']];
        self::assertSame('R043.agent-checkout-approval-required', R043HitlGate::ruleCodeFrom($resp));
    }

    public function test_build_freeze_payload_marks_freeze_true(): void
    {
        $resp = [
            'decision' => 'BLOCK',
            'reason' => 'agent checkout requires merchant approval',
            'evaluationId' => 'eval-abc-123',
            'ucp' => [
                'state' => 'requires_escalation',
                'reason_code' => 'trusteed:R043.agent-checkout-approval-required',
            ],
        ];
        $payload = R043HitlGate::buildFreezePayload($resp);
        self::assertTrue($payload['freeze']);
        self::assertSame('R043.agent-checkout-approval-required', $payload['rule_code']);
        self::assertSame('agent checkout requires merchant approval', $payload['reason']);
        self::assertSame('eval-abc-123', $payload['evaluation_id']);
        self::assertTrue(R043HitlGate::requiresFreeze($payload));
    }

    public function test_build_freeze_payload_not_freeze_for_allow(): void
    {
        $resp = ['decision' => 'ALLOW'];
        $payload = R043HitlGate::buildFreezePayload($resp);
        self::assertFalse($payload['freeze']);
        self::assertFalse(R043HitlGate::requiresFreeze($payload));
    }

    public function test_pending_state_config_key(): void
    {
        self::assertSame('PS_OS_PREPARATION', R043HitlGate::getPendingOrderStateConfigKey());
    }
}
