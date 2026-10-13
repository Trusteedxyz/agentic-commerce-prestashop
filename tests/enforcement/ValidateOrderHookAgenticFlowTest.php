<?php

declare(strict_types=1);

/**
 * ValidateOrderHook::evaluateCart() actually executed: routing, legitimate and
 * forged token against the list resolver, R023, layer 2 down with a verified
 * snapshot, offline valve and internal exception.
 *
 * Harness, stubs and test network client: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

final class ValidateOrderHookAgenticFlowTest extends ValidateOrderHookFlowTestCase
{
    // ── Derived routing ────────────────────────────────────────────────────

    public function testHumanWithOnlyR035ReachesTheServerWithTheOrganicPayloadUnchanged(): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);

        $this->hook->evaluateCart(new \Cart());

        self::assertCount(1, $this->client->posts, 'antes de A9 una R035 sola no llegaba al servidor');
        $raw  = $this->client->posts[0]['body'];
        $body = json_decode($raw, true);
        self::assertSame(
            ['merchantId', 'agentId', 'orderContext', 'platform', 'installationId', 'timestamp'],
            array_keys($body),
            'orgánico: las mismas seis claves de siempre, sin endpointClass'
        );
        self::assertNull($body['agentId']);
        // Byte for byte: same array, same order, same flags as before.
        self::assertSame(
            json_encode([
                'merchantId'     => $body['merchantId'],
                'agentId'        => null,
                'orderContext'   => $body['orderContext'],
                'platform'       => 'PRESTASHOP',
                'installationId' => $body['installationId'],
                'timestamp'      => $body['timestamp'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $raw
        );
        self::assertSame(
            ['cartTotalCents' => 10000, 'currency' => 'EUR', 'itemCount' => 0],
            $body['orderContext']
        );
    }

    public function testOnlyR001ReachesTheServer(): void
    {
        // The real snapshot's rules: only R001 in enforce.
        $this->hook->evaluateCart(new \Cart());

        self::assertCount(1, $this->client->evaluateBodies());
    }

    public function testAnObserveOnlyRuleStillReachesTheServerForTheShadowCount(): void
    {
        // A rule in `observe` does not block, but the server counts it in
        // shadow mode, so it has to reach it. If routing used
        // `hasEnforcingServerRule` instead of `needsServer`, it would not.
        $this->withRules([['ruleCode' => 'R001.verified-agent-required', 'enabled' => true, 'mode' => 'observe']]);

        $this->hook->evaluateCart(new \Cart());

        self::assertCount(1, $this->client->posts);
        self::assertCount(1, $this->client->evaluateBodies());
    }

    /**
     * The platform ceilings R052/R053 are injected ONLY by the layer 2 evaluator
     * and need no merchant row; the kill-switch and the R005 safeguard live there
     * too. A snapshot with no enabled rule cannot skip the query.
     */
    public function testNoEnabledRuleStillCallsTheServerSoPlatformCeilingsApply(): void
    {
        foreach ([[], [['ruleCode' => 'R030.simple-controls', 'enabled' => false]]] as $rules) {
            $this->client->posts = [];
            $this->withRules($rules);

            $this->hook->evaluateCart(new \Cart());

            self::assertCount(1, $this->client->evaluateBodies(), 'la capa 2 se consulta siempre');
        }
    }

    public function testAServerBlockWithNoMerchantRulesIsHonored(): void
    {
        $this->withRules([]);
        $this->client->evaluateResponse = [
            'code' => 200,
            'body' => '{"decision":"BLOCK","ruleCode":"R052.platform-max-quantity-per-line","reason":"x"}',
            'error' => '',
        ];

        self::assertNotNull($this->outcome(), 'el techo de plataforma bloquea aunque el comerciante no tenga reglas');
    }

    public function testAnOutageWithNoMerchantRulesDoesNotBlockInAnyMode(): void
    {
        // With no merchant rules there is nothing to enforce: layer 2 going down
        // cannot close the checkout (it was only queried for the ceilings).
        $this->withRules([]);
        $this->serverDown();
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            self::assertNull($this->outcome(), $mode);
        }
    }

    // ── Token: a legitimate one verifies, a forged one does not ────────────

    public function testLegitimateTokenVerifiesAgainstTheListResolverTheServerEmits(): void
    {
        $this->presentToken($this->token());

        self::assertNull($this->outcome());

        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies);
        self::assertSame(self::AGENT_DID, $bodies[0]['agentId'], 'antes el resolver sólo leía el mapa: ningún token legítimo verificaba');
        self::assertSame('agentic_endpoint', $bodies[0]['endpointClass'] ?? null);
        self::assertArrayNotHasKey('_agent_token_signature_invalid', $bodies[0]['orderContext']['cartAttributes'] ?? []);
        $nonces = $this->client->bodiesTo('/v1/agent-events/nonce-consume');
        self::assertCount(1, $nonces, 'un token verificado consume su jti');
        self::assertSame(self::AGENT_DID, $nonces[0]['agentId']);
    }

    public function testLegitimateTokenInStrictPassesWhenEverythingAnswers(): void
    {
        $this->mode('strict');
        $this->presentToken($this->token());

        self::assertNull($this->outcome(), 'antes, en strict, todo agente legítimo salía R001');
    }

    public function testForgedTokenForAListedDidIsInvalid(): void
    {
        $this->presentToken($this->forgedToken());

        self::assertNull($this->outcome());

        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies);
        self::assertNull($bodies[0]['agentId'], 'un DID no verificado nunca viaja como identidad');
        self::assertSame('agentic_endpoint', $bodies[0]['endpointClass'] ?? null);
        $attrs = $bodies[0]['orderContext']['cartAttributes'] ?? [];
        self::assertSame('true', $attrs['_agent_token_present'] ?? null);
        self::assertSame('true', $attrs['_agent_token_signature_invalid'] ?? null);
        self::assertCount(0, $this->client->bodiesTo('/v1/agent-events/nonce-consume'));
        self::assertSame([], $this->storedDids());
    }

    public function testForgedTokenForAFixtureDidIsInvalidInStrict(): void
    {
        $this->mode('strict');
        $this->presentToken($this->forgedToken(self::realPayload()['agentDidResolver'][0]['did']));

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:R001', $msg);
    }

    public function testAResolverKeyDeclaringAnotherCurveIsNotUsed(): void
    {
        // Same key, but the resolver entry says it is another curve.
        $payload = $this->payload();
        $last    = count($payload['agentDidResolver']) - 1;
        $payload['agentDidResolver'][$last]['publicKeyJwk']['crv'] = 'X25519';
        $this->client->snapshotResponse = ['code' => 200, 'body' => $this->signSnapshot($payload), 'error' => ''];
        $this->presentToken($this->token());

        self::assertNull($this->outcome());

        self::assertNull($this->client->evaluateBodies()[0]['agentId']);
        self::assertSame('true', $this->client->evaluateBodies()[0]['orderContext']['cartAttributes']['_agent_token_signature_invalid'] ?? null);
    }

    public function testGarbageTokenInStrictStillBlocksAsR001(): void
    {
        $this->mode('strict');
        $this->presentToken('not.a.token');

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:R001', $msg);
    }

    // ── R023: only the verified DID with an accepted nonce ─────────────────

    public function testVerifiedAcceptedAgentIsStoredForR023(): void
    {
        $this->presentToken($this->token());

        self::assertNull($this->outcome());

        $stored = $this->storedDids();
        self::assertCount(1, $stored, 'antes leía $claims["iss"], que verify() nunca devuelve');
        self::assertStringContainsString(self::AGENT_DID, $stored[0]);
        self::assertStringContainsString('VALUES (42,', $stored[0]);
    }

    public function testReplayedTokenIsNotStoredForR023(): void
    {
        $this->client->nonceResponse = ['code' => 409, 'body' => '', 'error' => ''];
        $this->presentToken($this->token());

        self::assertNull($this->outcome());

        self::assertSame([], $this->storedDids());
        self::assertSame('true', $this->client->evaluateBodies()[0]['orderContext']['cartAttributes']['_agent_token_replay'] ?? null);
    }

    public function testIndeterminateNonceIsNotStoredForR023(): void
    {
        $this->client->nonceResponse = ['code' => 503, 'body' => '', 'error' => ''];
        $this->presentToken($this->token());

        self::assertNull($this->outcome());

        self::assertSame([], $this->storedDids());
    }

    // ── Layer 2 down with a VERIFIED snapshot (evaluator_unavailable) ──────

    public function testI1AgentInStrictIsBlockedWhenLayer2IsDownEvenWithTheCachedSnapshot(): void
    {
        $this->mode('strict');
        $this->serverDown();
        $this->presentToken($this->token());

        $msg = $this->outcome();

        self::assertSame(1, $this->logsContaining('[trusteed.snapshot.cache_fallback]'), 'la rama con caché es la que corrió (el pull la escribe)');
        self::assertNotNull($msg, 'I1-PS: antes pasaba');
        self::assertStringContainsString('trusteed:CEL-TIMEOUT', $msg);
    }

    public function testAgentInBalancedOrPermissivePassesLayer2DownWithTheCachedSnapshot(): void
    {
        $this->serverDown();
        foreach (['balanced', 'permissive'] as $mode) {
            $this->mode($mode);
            $this->presentToken($this->token());
            self::assertNull($this->outcome(), $mode);
        }
        // The warning is emitted once per failure window, not per checkout.
        self::assertSame(1, $this->logsContaining('"reason":"layer2_unavailable_cached"'));
    }

    public function testHumanPassesLayer2DownWithTheCachedSnapshotInEveryMode(): void
    {
        $this->serverDown();
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            self::assertNull($this->outcome(), $mode);
        }
    }

    public function testHumanInStrictIsNotBlockedWhenTheServerIsDownWithoutCache(): void
    {
        $this->mode('strict');
        $this->serverDown();
        $this->client->noLocalCache = true;

        self::assertNull($this->outcome());
        self::assertSame(1, $this->logsContaining('"reason":"layer2_unavailable_no_cache"'), 'el permiso por avería deja rastro');
    }

    public function testVerifiedAgentInStrictIsBlockedWhenTheServerIsDownWithoutCache(): void
    {
        $this->mode('strict');
        $this->serverDown();
        $this->client->noLocalCache = true;
        $this->presentToken($this->token());

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:CEL-TIMEOUT', $msg);
    }

    public function testAgentInBalancedPassesTheOutageWithoutCache(): void
    {
        $this->serverDown();
        $this->client->noLocalCache = true;
        $this->presentToken('not.a.token');

        self::assertNull($this->outcome());
        self::assertSame(1, $this->logsContaining('[trusteed.snapshot.fallback_open]'));
    }

    public function testObserveOnlyRulesDoNotBlockAStrictAgentWhenLayer2IsDown(): void
    {
        $this->mode('strict');
        $this->withRules([['ruleCode' => 'R001.verified-agent-required', 'enabled' => true, 'mode' => 'observe', 'appliesTo' => 'AGENT']]);
        $this->serverDown();
        $this->presentToken($this->token());

        self::assertNull($this->outcome());
    }

    // ── Offline valve with cache: a human only gets ALL rules ──────────────

    public function testHumanOfflineValveSkipsAgentOnlyRules(): void
    {
        $this->mode('strict');
        $this->serverDown();
        // Synthetic: a ceiling that only applies to agents cannot stop a person.
        $this->withRules([['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'AGENT', 'params' => ['maxAmountCents' => 5000]]]);

        self::assertNull($this->outcome());
        self::assertSame(0, $this->logsContaining('[trusteed.offline_safety_valve]'));
    }

    public function testMerchantCapForAllStillBlocksAHumanOffline(): void
    {
        $this->serverDown();
        $this->withRules([['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'ALL', 'params' => ['maxAmountCents' => 5000]]]);

        $msg = $this->outcome();
        self::assertNotNull($msg);
        $this->assertFixedBuyerPhrase($msg, ['R030', '5000', '50.00', 'maxAmount']);
        self::assertSame(1, $this->logsContaining('[trusteed.offline_safety_valve] {"ruleCode":"R030"'), 'el detalle queda en el log, no en el mensaje');
    }

    public function testAgentOfflineValveStillAppliesAgentRules(): void
    {
        $this->serverDown();
        $this->presentToken('not.a.token');
        $this->withRules([['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'AGENT', 'params' => ['maxAmountCents' => 5000]]]);

        $msg = $this->outcome();
        self::assertNotNull($msg);
        $this->assertFixedBuyerPhrase($msg, ['R030', '5000', '50.00']);
    }

    // ── The buyer/agent does not receive the server's reason ──────────────

    public function testAServerBlockShowsAFixedPhraseNotTheServerReasonOrRuleCode(): void
    {
        $this->withRules([['ruleCode' => 'R035.max-order-value', 'enabled' => true]]);
        $this->client->evaluateResponse = [
            'code' => 200,
            'body' => (string) json_encode([
                'decision' => 'BLOCK',
                'ruleCode' => 'R066.watchlist-step-up',
                'reason'   => 'cart total 123456 exceeds the cap 99999 of this merchant',
            ]),
            'error' => '',
        ];

        $msg = $this->outcome();

        self::assertNotNull($msg);
        $this->assertFixedBuyerPhrase($msg, ['R066', 'watchlist', '123456', '99999', 'exceeds']);
        self::assertSame(1, $this->logsContaining('R066.watchlist-step-up'), 'el motivo real queda en el log del comerciante');
    }

    public function testAnR043HitlBlockShowsAFixedPhrase(): void
    {
        $this->withRules([['ruleCode' => 'R043.agent-checkout-approval-required', 'enabled' => true]]);
        $this->client->evaluateResponse = [
            'code' => 200,
            'body' => (string) json_encode([
                'decision' => 'BLOCK',
                'ruleCode' => 'R043',
                'reason'   => 'orders above 777 need approval',
                'evaluationId' => 'eval-1',
                'ucp' => ['state' => 'requires_escalation', 'reason_code' => 'trusteed:R043'],
            ]),
            'error' => '',
        ];

        $msg = $this->outcome();

        self::assertNotNull($msg);
        self::assertStringContainsString('pending review and requires merchant approval', $msg);
        foreach (['R043', '777', 'orders above', 'eval-1'] as $needle) {
            self::assertStringNotContainsString($needle, $msg);
        }
    }

    /** @param list<string> $forbidden */
    private function assertFixedBuyerPhrase(string $msg, array $forbidden): void
    {
        self::assertStringContainsString('did not pass the store\'s agent verification rules', $msg);
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $msg, 'el mensaje al comprador no lleva «' . $needle . '»');
        }
    }

    // ── Internal exception ─────────────────────────────────────────────────

    public function testAnInternalErrorNeverBlocksAHuman(): void
    {
        $this->internalErrorExpected = true;
        $this->mode('strict');
        $this->client->snapshotThrows = new \RuntimeException('boom');

        $this->hook->evaluateCart(new \Cart());

        self::assertSame(1, $this->logsContaining('[trusteed.cel.evaluator_error_human_allowed]'));
    }

    public function testAnInternalErrorHoldsAnAgentInEveryModeWithTheInternalErrorCode(): void
    {
        // It no longer bubbles up to the override to let the merchant's mode decide
        // (`balanced`/`permissive` used to open the door to the agent): it is held
        // in every mode.
        $this->internalErrorExpected = true;
        $this->client->snapshotThrows = new \RuntimeException('boom');
        $this->presentToken('not.a.token');
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            $msg = $this->outcome();
            self::assertNotNull($msg, $mode);
            self::assertStringContainsString('trusteed:CEL-INTERNAL-ERROR', $msg, $mode);
        }
    }
}
