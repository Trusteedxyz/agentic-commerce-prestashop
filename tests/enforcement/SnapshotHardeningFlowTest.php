<?php

declare(strict_types=1);

/**
 * Checks on the snapshot before trusting it.
 *
 * - Negative cache (60 s, fake clock) of the forced JWKS retry: a snapshot that
 *   does not verify does not trigger a second download on every checkout.
 * - `validUntil` (served and in the 5 min copy).
 * - Body size limit before verifying.
 * - The `crit` header (RFC 7515) is rejected.
 *
 * REAL SnapshotClient; only the network edge and the negative-cache clock are
 * test doubles. Harness: ValidateOrderHookFlowHarness.php. The real cURL read
 * is covered in SnapshotClientResponseLimitTest.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use Trusteed\Enforcement\SnapshotClient;

final class SnapshotHardeningFlowTest extends ValidateOrderHookFlowTestCase
{
    private const T0 = 1800000000;

    /** @return array{0:string,1:?string} [status, jws] */
    private function pull(int $now): array
    {
        $this->client->fakeNow = $now;
        $r = $this->client->pullResult();

        return [$r['status'], $r['jws']];
    }

    // ── negative cache of the forced retry ─────────────────────────────────

    public function testTheForcedJwksRetryIsNotRepeatedWithinTheNegativeWindow(): void
    {
        $this->serveTamperedSnapshot();

        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0)[0]);
        self::assertSame(2, $this->client->jwksFetches, 'descarga + reintento forzado');

        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0 + 30)[0]);
        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0 + 59)[0]);
        self::assertSame(2, $this->client->jwksFetches, 'dentro de la ventana no se vuelve a descargar');

        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0 + 61)[0]);
        self::assertSame(3, $this->client->jwksFetches, 'vencida la ventana se reintenta una vez');
        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0 + 62)[0]);
        self::assertSame(3, $this->client->jwksFetches);
    }

    public function testThreeCheckoutsWithAnUnverifiableSnapshotDownloadTheJwksTwiceNotSixTimes(): void
    {
        $this->serveTamperedSnapshot();
        $this->client->fakeNow = self::T0;
        $this->mode('strict');
        foreach ([0, 10, 20] as $offset) {
            $this->client->fakeNow = self::T0 + $offset;
            $this->presentToken($this->token());
            $msg = $this->outcome();
            self::assertNotNull($msg);
            self::assertStringContainsString('trusteed:CEL-SNAPSHOT-INVALID', $msg);
        }
        self::assertSame(2, $this->client->jwksFetches);
    }

    public function testAnotherKidInsideTheWindowStillGetsItsForcedRetry(): void
    {
        // Real rotation right after a failure: the new `kid` does not inherit the verdict.
        $rotated = sodium_crypto_sign_keypair();
        $rotatedX = SnapshotClient::base64UrlEncode(sodium_crypto_sign_publickey($rotated));
        $old = $this->jwks([self::SNAP_KID => $this->platformPublicX]);
        $new = $this->jwks([self::SNAP_KID => $this->platformPublicX, 'trusteed-rotated-kid' => $rotatedX]);
        $this->client->jwksResponses = [$old, $old, $new];
        $this->serveTamperedSnapshot();
        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0)[0]);

        $this->client->snapshotResponse = [
            'code'  => 200,
            'body'  => $this->signSnapshot($this->payload(), sodium_crypto_sign_secretkey($rotated), 'trusteed-rotated-kid'),
            'error' => '',
        ];

        self::assertSame(SnapshotClient::PULL_OK, $this->pull(self::T0 + 5)[0], 'una rotación no es manipulación');
    }

    public function testAnUnreachableJwksIsNotDownloadedAgainWithinTheWindow(): void
    {
        $this->client->jwksResponses = [['code' => 0, 'body' => '', 'error' => 'timeout']];

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $this->pull(self::T0)[0]);
        self::assertSame(2, $this->client->jwksFetches);

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $this->pull(self::T0 + 20)[0]);
        self::assertSame(2, $this->client->jwksFetches, 'ni la descarga normal se repite: el JWKS no responde');

        // The JWKS comes back and the window expires: it recovers on its own.
        $this->client->jwksResponses = [$this->jwks([self::SNAP_KID => $this->platformPublicX])];
        self::assertSame(SnapshotClient::PULL_OK, $this->pull(self::T0 + 61)[0]);
    }

    public function testAJwksThatVerifiesIsNeverBlockedByTheNegativeCache(): void
    {
        // A recent failure (tampered), then the good snapshot with the key already cached.
        $this->serveTamperedSnapshot();
        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0)[0]);
        $this->serveSnapshot();

        self::assertSame(SnapshotClient::PULL_OK, $this->pull(self::T0 + 1)[0]);
    }

    public function testAFailedNegativeCacheWriteStillClassifiesAndNeverThrows(): void
    {
        $this->client->retryCacheWriteFails = true;
        $this->serveTamperedSnapshot();

        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0)[0]);
        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0 + 1)[0]);
        self::assertSame(3, $this->client->jwksFetches, 'sin caché negativa reintenta, como antes (1 + 1 + 1)');
    }

    // ── validUntil ─────────────────────────────────────────────────────────

    public function testAServedSnapshotWhoseValidUntilPassedIsUnavailable(): void
    {
        $payload = array_merge($this->payload(), ['validUntil' => gmdate('Y-m-d\TH:i:s\Z', time() - 10)]);
        $this->client->snapshotResponse = ['code' => 200, 'body' => $this->signSnapshot($payload), 'error' => ''];

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $this->pull(self::T0)[0]);

        $this->presentToken($this->token());
        $msg = $this->outcome();
        self::assertNotNull($msg, 'con token y sin snapshot vigente: retenido');
        self::assertStringContainsString('snapshot_pull_failed', (string) $msg);
    }

    public function testACachedSnapshotWhoseValidUntilPassedIsNotServed(): void
    {
        // The test store does not expire by TTL: only the snapshot's own `validUntil` can stop it.
        $expired = array_merge($this->payload(), ['validUntil' => gmdate('Y-m-d\TH:i:s\Z', time() - 10)]);
        $mem = new \ReflectionProperty(SnapshotClient::class, 'memCache');
        $mem->setValue(null, ['trusteed_cel_snap_' . substr($this->merchantId, 0, 8) => $this->signSnapshot($expired)]);
        $this->snapshotDown();

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $this->pull(self::T0)[0]);
    }

    public function testACachedSnapshotStillInsideItsValidUntilIsServedWhenTheEndpointIsDown(): void
    {
        $fresh = $this->signSnapshot($this->payload());
        $mem = new \ReflectionProperty(SnapshotClient::class, 'memCache');
        $mem->setValue(null, ['trusteed_cel_snap_' . substr($this->merchantId, 0, 8) => $fresh]);
        $this->snapshotDown();

        [$status, $jws] = $this->pull(self::T0);
        self::assertSame(SnapshotClient::PULL_OK, $status);
        self::assertSame($fresh, $jws);
    }

    /**
     * A snapshot signed by our server ALWAYS carries an ISO `validUntil`.
     * Missing or unreadable means a malformed payload: "unavailable" (like an
     * expired one), never "tampered" (the signature verified) and never served.
     *
     * @return array<string,array{0:bool,1:mixed}> [is it missing?, value]
     */
    public static function unusableValidUntil(): array
    {
        return [
            'ausente'              => [true, null],
            'null'                 => [false, null],
            'cadena vacía'         => [false, ''],
            'cadena basura'        => [false, 'no es una fecha'],
            'número entero'        => [false, 1893456000],
            'número fuera de rango' => [false, PHP_INT_MAX],
            'flotante enorme'      => [false, 1.0e+20],
            'cadena numérica enorme' => [false, '99999999999999999999'],
            'fecha relativa que strtotime acepta' => [false, 'tomorrow'],
            'fecha imposible'      => [false, '2099-13-45T00:00:00Z'],
            'ISO sin zona'         => [false, '2099-01-01T00:00:00'],
            'lista'                => [false, ['2099-01-01T00:00:00Z']],
            'booleano'             => [false, true],
        ];
    }

    /** @return array<string,mixed> */
    private function payloadWithValidUntil(bool $absent, mixed $value): array
    {
        $payload = $this->payload();
        if ($absent) {
            unset($payload['validUntil']);
        } else {
            $payload['validUntil'] = $value;
        }

        return $payload;
    }

    /** @dataProvider unusableValidUntil */
    public function testAServedSnapshotWithAnUnusableValidUntilIsUnavailableNotTampered(bool $absent, mixed $value): void
    {
        $jws = $this->signSnapshot($this->payloadWithValidUntil($absent, $value));
        $this->client->snapshotResponse = ['code' => 200, 'body' => $jws, 'error' => ''];

        [$status, $served] = $this->pull(self::T0);

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $status);
        self::assertNull($served);
        self::assertSame(SnapshotClient::PULL_OK, $this->client->verifyResult($jws)[0], 'la firma verifica: no es manipulación');
    }

    /** @dataProvider unusableValidUntil */
    public function testACachedSnapshotWithAnUnusableValidUntilIsNotServed(bool $absent, mixed $value): void
    {
        $jws = $this->signSnapshot($this->payloadWithValidUntil($absent, $value));
        (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->setValue(null, ['trusteed_cel_snap_' . substr($this->merchantId, 0, 8) => $jws]);
        $this->snapshotDown();

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $this->pull(self::T0)[0]);
    }

    /** @dataProvider unusableValidUntil */
    public function testTheLocalOfflineCopyWithAnUnusableValidUntilIsNotServed(bool $absent, mixed $value): void
    {
        $this->client->seedLocalSnapshotCache($this->signSnapshot($this->payloadWithValidUntil($absent, $value)), time() - 10);

        self::assertNull($this->client->getLocalSnapshotCache(), 'sin validUntil legible no hay copia utilizable offline');
    }

    public function testTheLocalOfflineCopyWithAReadableFutureValidUntilIsStillServed(): void
    {
        // Control: the same path with a good validUntil does serve the copy.
        $this->client->seedLocalSnapshotCache($this->signSnapshot($this->payload()), time() - 10);

        self::assertNotNull($this->client->getLocalSnapshotCache());
    }

    public function testASnapshotWithoutValidUntilBlocksTheAgentThroughTheHook(): void
    {
        $payload = $this->payload();
        unset($payload['validUntil']);
        $this->client->snapshotResponse = ['code' => 200, 'body' => $this->signSnapshot($payload), 'error' => ''];
        $this->presentToken($this->token());

        $msg = $this->outcome();

        self::assertNotNull($msg, 'con token y sin snapshot utilizable: retenido');
        self::assertStringContainsString('snapshot_pull_failed', (string) $msg);
        self::assertStringNotContainsString('CEL-SNAPSHOT-INVALID', (string) $msg, 'no es manipulación');
    }

    // ── size limit ─────────────────────────────────────────────────────────

    public function testAnOversizedBodyIsUnavailableWithoutBeingVerified(): void
    {
        // Over 4 MiB of garbage: if it were verified it would be "tampered" (it has no JWS shape).
        $this->client->snapshotResponse = ['code' => 200, 'body' => str_repeat('a', SnapshotClient::MAX_RESPONSE_BYTES + 1), 'error' => ''];

        [$status, $jws] = $this->pull(self::T0);

        self::assertSame(SnapshotClient::PULL_UNAVAILABLE, $status);
        self::assertNull($jws);
        self::assertSame(0, $this->client->jwksFetches, 'ni siquiera se descargó el JWKS');
    }

    public function testABodyExactlyAtTheCapIsStillRead(): void
    {
        $this->client->snapshotResponse = ['code' => 200, 'body' => str_repeat('a', SnapshotClient::MAX_RESPONSE_BYTES), 'error' => ''];

        // Within the limit it is examined (and has no JWS shape: tampered, not "too large").
        self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0)[0]);
    }

    // ── crit ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $header */
    private function signWithHeader(array $header): string
    {
        $input = SnapshotClient::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.' . SnapshotClient::base64UrlEncode(json_encode($this->payload(), JSON_THROW_ON_ERROR));

        return $input . '.' . SnapshotClient::base64UrlEncode(sodium_crypto_sign_detached($input, $this->platformSecretKey));
    }

    public function testASnapshotHeaderWithCritIsRejectedEvenWithAValidSignature(): void
    {
        $base = ['alg' => 'EdDSA', 'kid' => self::SNAP_KID];

        // Control: the same signature without `crit` verifies.
        $this->client->snapshotResponse = ['code' => 200, 'body' => $this->signWithHeader($base), 'error' => ''];
        self::assertSame(SnapshotClient::PULL_OK, $this->pull(self::T0)[0]);

        foreach ([['exp'], [], 'exp', null] as $crit) {
            $this->client->snapshotResponse = ['code' => 200, 'body' => $this->signWithHeader($base + ['crit' => $crit, 'exp' => 1]), 'error' => ''];
            \Closure::bind(static function (): void {
                self::$memCache = [];
            }, null, SnapshotClient::class)();
            self::assertSame(SnapshotClient::PULL_TAMPERED, $this->pull(self::T0)[0], 'crit=' . json_encode($crit));
        }
    }

    public function testACritSnapshotBlocksTheAgentThroughTheHook(): void
    {
        $this->client->snapshotResponse = [
            'code'  => 200,
            'body'  => $this->signWithHeader(['alg' => 'EdDSA', 'kid' => self::SNAP_KID, 'crit' => ['b64'], 'b64' => true]),
            'error' => '',
        ];
        $this->presentToken($this->token());

        $msg = $this->outcome();

        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:CEL-SNAPSHOT-INVALID', $msg);
    }
}
