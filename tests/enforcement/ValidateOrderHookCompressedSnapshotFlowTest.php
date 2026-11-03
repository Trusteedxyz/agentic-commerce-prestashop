<?php

declare(strict_types=1);

/**
 * Plan 04 Task 24 — snapshot comprimido (`zip: "DEF"`, payload > 50 KB).
 *
 * Consume la sección `compressedSnapshot` del fixture compartido: el JWS lo
 * produjeron el builder y el firmador REALES del servidor (test de apps/api),
 * con claves derivadas de etiquetas públicas. Aquí:
 *
 *   - los 12 vectores pasan por `SnapshotClient::verifyResult()` REAL, con el
 *     JWKS del fixture servido por el borde de red de prueba (conteo asertado);
 *   - los 11 cuya firma verifica pasan además por el decodificador, motivo a
 *     motivo (conteo asertado);
 *   - el flujo de `ValidateOrderHook` con el snapshot comprimido servido por el
 *     cliente real: el agente con token NO queda retenido por el tamaño, su
 *     token verifica contra el resolver de 300 DIDs, la persona pasa, la
 *     válvula offline lee las reglas del JWS comprimido de la caché de 24 h, y
 *     la bomba se maneja sin reservar más que el tope.
 *
 * Arnés, stubs y cliente de red de prueba: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use Trusteed\Enforcement\SnapshotClient;
use Trusteed\Enforcement\SnapshotPayloadDecoder;

final class ValidateOrderHookCompressedSnapshotFlowTest extends ValidateOrderHookFlowTestCase
{
    private static ?array $section = null;

    private static function section(): array
    {
        if (self::$section === null) {
            $path = __DIR__ . '/../../../shared/src/enforcement/__fixtures__/platform-flow-conformance.json';
            $json = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($json, 'el fixture tiene que ser alcanzable desde el monorepo (monta la raíz)');
            self::$section = $json['compressedSnapshot'];
        }
        return self::$section;
    }

    private static function vector(string $id): array
    {
        foreach (self::section()['vectors'] as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        self::fail('vector ausente: ' . $id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $keys = self::section()['jwks'];
        $this->client->jwksResponses = [['code' => 200, 'body' => json_encode($keys, JSON_THROW_ON_ERROR), 'error' => '']];
        $this->serveVector('valid.deflated-by-real-signer');
    }

    private function serveVector(string $id): void
    {
        $this->client->snapshotResponse = ['code' => 200, 'body' => self::vector($id)['jws'], 'error' => ''];
    }

    /** Clave del agente de conformidad: semilla = SHA-256(etiqueta pública). */
    private function conformanceAgentSecret(): string
    {
        $seed = hash('sha256', self::section()['agent']['seedLabel'], true);
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    }

    private function conformanceToken(): string
    {
        return $this->token($this->conformanceAgentSecret(), self::section()['agent']['did']);
    }

    private function outcomeFor(float $total): ?string
    {
        $cart        = new \Cart();
        $cart->total = $total;
        try {
            $this->hook->evaluateCart($cart);
            return null;
        } catch (\PrestaShopException $e) {
            return $e->getMessage();
        }
    }

    // ── Conformidad: los 12 vectores ───────────────────────────────────────

    public function testTheFixtureCapIsThePortCap(): void
    {
        self::assertSame(SnapshotPayloadDecoder::MAX_INFLATED_BYTES, self::section()['maxInflatedBytes']);
        self::assertSame('DEF', SnapshotPayloadDecoder::ZIP_DEFLATE);
    }

    public function testEveryVectorThroughTheRealClient(): void
    {
        $expectedStatus = [
            'ok'          => 'ok',
            'unavailable' => 'unavailable',
            'tampered'    => 'tampered',
        ];
        $run = 0;
        foreach (self::section()['vectors'] as $v) {
            (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->setValue(null, []);
            [$status, $payload] = $this->client->verifyResult($v['jws']);
            self::assertSame($expectedStatus[$v['expected']['status']], $status, $v['id']);
            if ($status === 'ok') {
                self::assertSame($v['expected']['ruleSetVersion'], $payload['ruleSetVersion'], $v['id']);
                self::assertSame($v['expected']['merchantId'], $payload['merchantId'], $v['id']);
                self::assertCount($v['expected']['agentDidResolverCount'], $payload['agentDidResolver'], $v['id']);
            } else {
                self::assertNull($payload, $v['id']);
            }
            $run++;
        }
        self::assertSame(12, $run);
    }

    public function testEverySignatureValidVectorThroughTheDecoderWithItsReason(): void
    {
        $run = 0;
        foreach (self::section()['vectors'] as $v) {
            if ($v['expected']['status'] === 'tampered') {
                continue;
            }
            [$h, $p] = explode('.', $v['jws']);
            $header           = json_decode(SnapshotClient::base64UrlDecode($h), true);
            [$reason, $payload] = SnapshotPayloadDecoder::decode($header, SnapshotClient::base64UrlDecode($p));
            if ($v['expected']['status'] === 'ok') {
                self::assertSame(SnapshotPayloadDecoder::OK, $reason, $v['id']);
                self::assertSame($v['expected']['ruleSetVersion'], $payload['ruleSetVersion'], $v['id']);
            } else {
                self::assertSame($v['expected']['reason'], $reason, $v['id']);
                self::assertNull($payload, $v['id']);
            }
            $run++;
        }
        self::assertSame(11, $run);
    }

    public function testTheBombIsRejectedWithoutReservingMoreThanTheCap(): void
    {
        [$h, $p] = explode('.', self::vector('bomb.over-cap')['jws']);
        $header  = json_decode(SnapshotClient::base64UrlDecode($h), true);
        $before  = memory_get_usage();
        memory_reset_peak_usage();
        [$reason] = SnapshotPayloadDecoder::decode($header, SnapshotClient::base64UrlDecode($p));
        $grown = memory_get_peak_usage() - $before;

        self::assertSame(SnapshotPayloadDecoder::INFLATE_OVER_CAP, $reason);
        // 8 MiB declarados; el pico no pasa del tope (2 MiB) más un paso y la
        // cadena final: sin tope serían ≥ 8 MiB.
        self::assertLessThan(6 * 1024 * 1024, $grown, 'la bomba no se infla entera');
    }

    // ── Flujo real de ValidateOrderHook con el snapshot comprimido ─────────

    public function testALegitimateAgentIsNotHeldBecauseOfTheSizeAndItsTokenVerifies(): void
    {
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            $this->presentToken($this->conformanceToken());
            self::assertNull($this->outcome(), 'antes: snapshot comprimido = manipulado ⇒ retenido en ' . $mode);
        }
        $bodies = $this->client->evaluateBodies();
        self::assertCount(3, $bodies);
        foreach ($bodies as $body) {
            self::assertSame(self::section()['agent']['did'], $body['agentId'] ?? null, 'el DID verificado viaja a la capa 2');
        }
        self::assertCount(3, $this->client->bodiesTo('/v1/agent-events/nonce-consume'), 'se consume el jti: el token verificó');
        self::assertSame(0, $this->logsContaining('invalid_signature'));
    }

    public function testAForgedTokenStillFailsAgainstTheCompressedResolver(): void
    {
        $this->mode('strict');
        $this->presentToken($this->token(null, self::section()['agent']['did']));

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('R001', $msg);
    }

    public function testAHumanPassesWithTheCompressedSnapshot(): void
    {
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            self::assertNull($this->outcome(), $mode);
        }
    }

    public function testTheOfflineValveReadsTheRulesOfTheCompressedCachedJws(): void
    {
        // Primera petición: el snapshot comprimido verifica y va a la caché de 24 h.
        self::assertNull($this->outcomeFor(100.0));
        // Capa 2 caída: la válvula lee R030 (tope 5000,00) del JWS COMPRIMIDO en caché.
        $this->serverDown();
        self::assertNull($this->outcomeFor(100.0), 'por debajo del tope pasa');
        $msg = $this->outcomeFor(6000.0);
        self::assertNotNull($msg, 'sin inflar, la válvula no veía reglas y el tope del comerciante no aplicaba');
        self::assertStringContainsString('trusteed:CEL-BLOCKED', $msg, 'veredicto del tope R030 (el código va al log, no al comprador)');
    }

    public function testTheBombHoldsAnAgentWithATokenAsUnavailableAndLetsAHumanThrough(): void
    {
        $this->serveVector('bomb.over-cap');
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            $this->presentToken($this->conformanceToken());
            $msg = $this->outcome();
            self::assertNotNull($msg, $mode);
            self::assertStringContainsString('checks_unavailable', $msg, $mode);
            self::assertStringNotContainsString('SNAPSHOT-INVALID', $msg, 'firma de la plataforma: no es manipulación');
        }
        unset(\Tools::$mockValues['_trusteed_agent_token']);
        self::assertNull($this->outcome(), 'una persona nunca queda retenida por esto');
        self::assertCount(0, $this->client->evaluateBodies());
    }

    public function testAnUnknownZipIsUnavailableNotTampered(): void
    {
        $this->serveVector('zip.unknown');
        $this->mode('permissive');
        $this->presentToken($this->conformanceToken());

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('checks_unavailable', $msg);
        self::assertSame(1, $this->logsContaining('"kind":"missing_snapshot"'));
    }

    public function testAnExpiredCompressedSnapshotInTheDayCacheIsNotReused(): void
    {
        $payload                = self::realPayload();
        $payload['validUntil']  = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        $header                 = SnapshotClient::base64UrlEncode(json_encode(['alg' => 'EdDSA', 'kid' => 'k', 'zip' => 'DEF'], JSON_THROW_ON_ERROR));
        $body                   = SnapshotClient::base64UrlEncode((string) gzdeflate(json_encode($payload, JSON_THROW_ON_ERROR)));
        $this->client->seedLocalSnapshotCache($header . '.' . $body . '.sig', time() - 10);

        self::assertNull($this->client->getLocalSnapshotCache(), 'su validUntil está DENTRO del payload comprimido');

        $payload['validUntil'] = gmdate('Y-m-d\TH:i:s\Z', time() + 600);
        $body                  = SnapshotClient::base64UrlEncode((string) gzdeflate(json_encode($payload, JSON_THROW_ON_ERROR)));
        $this->client->seedLocalSnapshotCache($header . '.' . $body . '.sig', time() - 10);
        self::assertNotNull($this->client->getLocalSnapshotCache());
    }

    public function testTheBombWithABadSignatureIsTampered(): void
    {
        $this->serveVector('bomb.bad-signature');
        $this->presentToken($this->conformanceToken());

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:CEL-SNAPSHOT-INVALID', $msg, 'la firma se mira antes de inflar');
    }
}
