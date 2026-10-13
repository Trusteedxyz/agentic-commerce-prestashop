<?php

declare(strict_types=1);

/**
 * The TOKEN verifier rejects the JWS `crit` header.
 *
 * RFC 7515 section 4.1.11: `crit` lists extensions the receiver MUST understand;
 * if it does not understand one (or the list is empty or malformed) the JWS is
 * rejected. The agent token signer never emits `crit` and this verifier
 * understands no extension, so a present `crit` (even `[]`) means an invalid
 * token. The module's SNAPSHOT verifiers already do this
 * (SnapshotHardeningFlowTest); this covers the token one.
 *
 * REAL TokenVerifier with a real Ed25519 signature (`crit` sits inside what is
 * signed, so the header is the only reason for rejection), and also through the
 * real order hook. Harness: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use Trusteed\Enforcement\TokenVerifier;

final class TokenVerifierCritFlowTest extends ValidateOrderHookFlowTestCase
{
    /** @return array<string,array{0:mixed}> */
    public static function critValues(): array
    {
        return [
            'lista con una extensión' => [['exp']],
            'lista vacía (RFC 7515: inválida)' => [[]],
            'cadena' => ['exp'],
            'null' => [null],
            'número' => [1],
        ];
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier($this->payload()['agentDidResolver'], $this->merchantId);
    }

    private function intentHash(): string
    {
        return hash('sha256', json_encode([
            'merchantId' => $this->merchantId,
            'lineItems'  => [],
            'currency'   => 1,
        ], JSON_THROW_ON_ERROR));
    }

    public function testControlTheSameTokenWithoutCritVerifies(): void
    {
        $claims = $this->verifier()->verify($this->token(), $this->intentHash());

        self::assertIsArray($claims, 'sin `crit` el token del arnés verifica: el rechazo de abajo es sólo por la cabecera');
        self::assertSame(self::AGENT_DID, $claims['agentId']);
    }

    /** @dataProvider critValues */
    public function testATokenWithCritIsRejectedEvenWithAValidSignature(mixed $crit): void
    {
        $token = $this->token(null, self::AGENT_DID, [], ['crit' => $crit]);

        self::assertNull($this->verifier()->verify($token, $this->intentHash()));
    }

    public function testACritTokenIsAnInvalidSignatureThroughTheHook(): void
    {
        $this->presentToken($this->token(null, self::AGENT_DID, [], ['crit' => ['b64'], 'b64' => true]));

        self::assertNull($this->outcome());

        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies);
        self::assertNull($bodies[0]['agentId'], 'un token con crit nunca viaja como identidad verificada');
        $attrs = $bodies[0]['orderContext']['cartAttributes'] ?? [];
        self::assertSame('true', $attrs['_agent_token_present'] ?? null);
        self::assertSame('true', $attrs['_agent_token_signature_invalid'] ?? null);
        self::assertCount(0, $this->client->bodiesTo('/v1/agent-events/nonce-consume'));
        self::assertSame([], $this->storedDids());
    }

    public function testAnEmptyCritListIsAlsoInvalidThroughTheHook(): void
    {
        $this->presentToken($this->token(null, self::AGENT_DID, [], ['crit' => []]));

        self::assertNull($this->outcome());

        $attrs = $this->client->evaluateBodies()[0]['orderContext']['cartAttributes'] ?? [];
        self::assertSame('true', $attrs['_agent_token_signature_invalid'] ?? null);
    }
}
