<?php

declare(strict_types=1);

/**
 * B5 (2026-10-01) — el identificador de un solo uso del token de agente es `nonce`.
 *
 * El contrato canónico (`AgentTokenPayloadSchema`, packages/shared) es `nonce`; el
 * servidor indexa su anti-replay por él y `generate-test-agent-token.ts` emite
 * `nonce` SIN `jti`. Antes de B5 este módulo exigía además un `jti` que ningún
 * emisor canónico produce, así que un token canónico era INVÁLIDO aquí.
 *
 * Ahora: `nonce` (16..64 base64url) es el identificador que se consume en
 * `/v1/agent-events/nonce-consume`; `jti` sólo vale como alias heredado cuando
 * `nonce` falta; con ambos distintos gana `nonce`. Un token sin ninguno, o con
 * uno mal formado, es inválido (`_agent_token_signature_invalid`) y NO se gasta
 * ningún identificador en el servidor.
 *
 * Todo pasa por `ValidateOrderHook::evaluateCart()` con el SnapshotClient real
 * (sólo el borde de red es de prueba). Arnés: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

final class ValidateOrderHookNonceContractTest extends ValidateOrderHookFlowTestCase
{
    /** @return list<string> identificadores que el módulo mandó a nonce-consume */
    private function consumedIds(): array
    {
        return array_map(static fn (array $b): string => (string) ($b['nonce'] ?? ''), $this->client->bodiesTo('/v1/agent-events/nonce-consume'));
    }

    /** @return array<string,mixed> */
    private function evaluated(): array
    {
        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies, 'la capa 2 se llama una vez');

        return $bodies[0];
    }

    public function testCanonicalTokenWithNonceAndNoJtiIsValidAndConsumesItsNonce(): void
    {
        $nonce = bin2hex(random_bytes(16));
        $this->presentToken($this->token(null, self::AGENT_DID, ['nonce' => $nonce], [], ['jti']));

        self::assertNull($this->outcome());

        $body = $this->evaluated();
        self::assertSame(self::AGENT_DID, $body['agentId'], 'atribuido');
        self::assertArrayNotHasKey('_agent_token_signature_invalid', $body['orderContext']['cartAttributes'] ?? []);
        self::assertSame([$nonce], $this->consumedIds(), 'al servidor viaja el NONCE del payload firmado');
        self::assertCount(1, $this->storedDids(), 'R023: nonce ACEPTADO');
    }

    public function testReplayOfACanonicalNonceIsAnAnonymousAgent(): void
    {
        $this->client->nonceResponse = ['code' => 409, 'body' => '', 'error' => ''];
        $this->presentToken($this->token(null, self::AGENT_DID, [], [], ['jti']));

        self::assertNull($this->outcome());

        $body = $this->evaluated();
        self::assertNull($body['agentId']);
        self::assertSame('true', $body['orderContext']['cartAttributes']['_agent_token_replay'] ?? null);
        self::assertCount(1, $this->consumedIds(), 'el nonce se presentó al servidor');
        self::assertSame([], $this->storedDids());
    }

    public function testTheSameCanonicalTokenPresentedTwiceIsRejectedTheSecondTime(): void
    {
        $token = $this->token(null, self::AGENT_DID, [], [], ['jti']);
        $this->presentToken($token);
        self::assertNull($this->outcome());
        self::assertSame(self::AGENT_DID, $this->evaluated()['agentId']);

        $this->client->posts = [];
        $this->presentToken($token);
        self::assertNull($this->outcome());
        $second = $this->client->evaluateBodies()[0];
        self::assertNull($second['agentId'], 'la deduplicación local también cuelga del nonce');
        self::assertSame('true', $second['orderContext']['cartAttributes']['_agent_token_signature_invalid'] ?? null);
        self::assertSame([], $this->consumedIds(), 'un token que no pasa no gasta nada en el servidor');
    }

    public function testLegacyJtiWithoutNonceIsValidThroughTheAlias(): void
    {
        $jti = 'legacyAliasJti000001';
        $this->presentToken($this->token(null, self::AGENT_DID, ['jti' => $jti], [], ['nonce']));

        self::assertNull($this->outcome());

        self::assertSame(self::AGENT_DID, $this->evaluated()['agentId']);
        self::assertSame([$jti], $this->consumedIds());
    }

    public function testNonceWinsWhenBothClaimsArePresentAndDifferent(): void
    {
        $this->presentToken($this->token(null, self::AGENT_DID, ['nonce' => 'canonicalNonce000001', 'jti' => 'legacyAliasJti000001']));

        self::assertNull($this->outcome());

        self::assertSame(['canonicalNonce000001'], $this->consumedIds());
    }

    public function testATokenWithNeitherClaimIsInvalidAndSpendsNothing(): void
    {
        $this->presentToken($this->token(null, self::AGENT_DID, [], [], ['nonce', 'jti']));

        self::assertNull($this->outcome());

        $body = $this->evaluated();
        self::assertNull($body['agentId']);
        self::assertSame('true', $body['orderContext']['cartAttributes']['_agent_token_signature_invalid'] ?? null);
        self::assertSame([], $this->consumedIds());
    }

    /** @dataProvider malformedIdentifiers */
    public function testAMalformedNonceIsInvalidEvenWithAValidJtiAndSpendsNothing(string $nonce): void
    {
        $this->presentToken($this->token(null, self::AGENT_DID, ['nonce' => $nonce, 'jti' => 'legacyAliasJti000001']));

        self::assertNull($this->outcome());

        $body = $this->evaluated();
        self::assertNull($body['agentId']);
        self::assertSame('true', $body['orderContext']['cartAttributes']['_agent_token_signature_invalid'] ?? null);
        self::assertSame([], $this->consumedIds());
    }

    /** @return array<string,array{string}> */
    public static function malformedIdentifiers(): array
    {
        return [
            '15 caracteres' => [str_repeat('n', 15)],
            '65 caracteres' => [str_repeat('n', 65)],
            'alfabeto fuera de base64url' => ['has.dot.in.it.illegal0001'],
        ];
    }

    public function testIdentifierBoundariesAre16And64(): void
    {
        foreach ([16, 64] as $len) {
            $this->client->posts = [];
            $nonce = str_repeat('a', $len) ;
            $this->presentToken($this->token(null, self::AGENT_DID, ['nonce' => $nonce], [], ['jti']));
            self::assertNull($this->outcome(), "{$len}");
            self::assertSame([$nonce], $this->consumedIds(), "{$len} caracteres: aceptado");
        }
    }

    public function testStrictModeBlocksAnInvalidIdentifierAsUnverifiedAgent(): void
    {
        $this->mode('strict');
        $this->presentToken($this->token(null, self::AGENT_DID, [], [], ['nonce', 'jti']));

        $msg = $this->outcome();

        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:R001', $msg);
        self::assertSame([], $this->consumedIds());
    }
}
