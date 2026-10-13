<?php

declare(strict_types=1);

/**
 * A REUSED token carries no identity.
 *
 * With the nonce in REPLAY the hook kept sending the token's DID as `agentId` to
 * `/v1/rules/evaluate` (and its `agentTrustScore`): the server saw an identified
 * agent whose identity had just been declared untrustworthy, and an allowlist
 * could return ALLOW. Now it is an ANONYMOUS agent (`agentId` null,
 * `endpointClass: agentic_endpoint`, no trust score) with `_agent_token_replay`.
 *
 * Everything goes through `ValidateOrderHook::evaluateCart()` with the real
 * SnapshotClient (only the network edge is a test double).
 * Harness: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

final class ValidateOrderHookReplayIdentityTest extends ValidateOrderHookFlowTestCase
{
    private const SCORE_CLAIM = ['agentTrustScore' => 88.0];

    /** @return array<string,mixed> */
    private function evaluated(): array
    {
        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies, 'la capa 2 se llama una vez');

        return $bodies[0];
    }

    public function testAcceptedNonceStillSendsTheVerifiedIdentity(): void
    {
        $this->presentToken($this->token(null, self::AGENT_DID, self::SCORE_CLAIM));

        self::assertNull($this->outcome());

        $body = $this->evaluated();
        self::assertSame(self::AGENT_DID, $body['agentId']);
        self::assertSame(88, $body['orderContext']['agentTrustScore'], 'la puntuación viaja como entero');
        self::assertSame('agentic_endpoint', $body['endpointClass']);
    }

    public function testReplayedTokenIsAnAnonymousAgentInBalancedAndPermissive(): void
    {
        $this->client->nonceResponse = ['code' => 409, 'body' => '', 'error' => ''];
        foreach (['balanced', 'permissive'] as $mode) {
            $this->mode($mode);
            $this->client->posts = [];
            $this->presentToken($this->token(null, self::AGENT_DID, self::SCORE_CLAIM));

            self::assertNull($this->outcome(), $mode);

            $body = $this->evaluated();
            self::assertNull($body['agentId'], $mode . ': el DID de un token reutilizado no viaja');
            self::assertArrayNotHasKey('agentTrustScore', $body['orderContext'], $mode . ': ni su trust score (sin puntuación la clave no viaja: el servidor rechaza null)');
            self::assertSame('agentic_endpoint', $body['endpointClass'], $mode . ': sigue siendo un agente');
            $attrs = $body['orderContext']['cartAttributes'];
            self::assertSame('true', $attrs['_agent_token_replay'] ?? null, $mode);
            self::assertSame('true', $attrs['_agent_token_signature_invalid'] ?? null, $mode);
            self::assertStringNotContainsString(self::AGENT_DID, (string) json_encode($body), $mode . ': el DID no aparece en ningún campo');
        }
        self::assertSame([], $this->storedDids(), 'R023 sólo con nonce ACEPTADO');
    }

    public function testReplayedTokenInStrictStillBlocksAsR002WithoutAskingTheServer(): void
    {
        $this->mode('strict');
        $this->client->nonceResponse = ['code' => 409, 'body' => '', 'error' => ''];
        $this->presentToken($this->token(null, self::AGENT_DID, self::SCORE_CLAIM));

        $msg = $this->outcome();

        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:R002', $msg);
        self::assertSame([], $this->client->evaluateBodies());
    }

    public function testAnIndeterminateNonceKeepsTheProvenIdentityForTheServerToJudge(): void
    {
        // The token IS cryptographically valid and only the reuse check is
        // missing. The identity travels with `_agent_token_nonce_unavailable`
        // (the server allowlist no longer exempts on that signal) so R005 still
        // sees a revoked agent.
        $this->client->nonceResponse = ['code' => 503, 'body' => '', 'error' => ''];
        $this->presentToken($this->token());

        self::assertNull($this->outcome());

        $body = $this->evaluated();
        self::assertSame(self::AGENT_DID, $body['agentId']);
        self::assertSame('true', $body['orderContext']['cartAttributes']['_agent_token_nonce_unavailable'] ?? null);
        self::assertArrayNotHasKey('_agent_token_replay', $body['orderContext']['cartAttributes']);
        self::assertSame([], $this->storedDids(), 'R023 sólo con nonce ACEPTADO');
    }
}
