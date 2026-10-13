<?php

declare(strict_types=1);

/**
 * The cause of the failure decides: tampered snapshot (invalid_signature),
 * unavailable (missing_snapshot) and layer switched off (layer_disabled), with
 * the real SnapshotClient and the JWKS retried once.
 *
 * Harness, stubs and test network client: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use TestPsState;
use Trusteed\Enforcement\SnapshotClient;
use Trusteed\Enforcement\ValidateOrderHook;

final class ValidateOrderHookDegradedFlowTest extends ValidateOrderHookFlowTestCase
{
    // ── Tampered snapshot (invalid_signature) ──────────────────────────────

    public function testTamperedSnapshotWithTokenBlocksTheAgentInEveryMode(): void
    {
        $this->serveTamperedSnapshot();
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            $this->presentToken($this->token());
            $msg = $this->outcome();
            self::assertNotNull($msg, 'antes pasaba en ' . $mode . ' sin verificar el token');
            self::assertStringContainsString('trusteed:CEL-SNAPSHOT-INVALID', $msg, $mode);
        }
        self::assertCount(0, $this->client->posts, 'con un snapshot manipulado no se evalúa nada más');
        self::assertSame(3, $this->logsContaining('"kind":"invalid_signature"'));
    }

    public function testTamperedSnapshotNeverBlocksAHumanEvenWithAMerchantCap(): void
    {
        // The R030 ALL cap comes INSIDE the tampered snapshot: it is not the merchant's.
        $this->withRules([['ruleCode' => 'R030', 'enabled' => true, 'appliesTo' => 'ALL', 'params' => ['maxAmountCents' => 5000]]]);
        $this->serveTamperedSnapshot();
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            self::assertNull($this->outcome(), $mode);
        }
        self::assertSame(3, $this->logsContaining('"kind":"invalid_signature"'));
    }

    public function testTheJwksIsRefetchedOnceBeforeCallingASnapshotTampered(): void
    {
        // Rotation: the JWKS cache (first response) does not have the kid yet.
        $this->client->jwksResponses = [
            $this->jwks(['trusteed-old-kid' => $this->platformPublicX]),
            $this->jwks([self::SNAP_KID => $this->platformPublicX]),
        ];
        $this->mode('strict');
        $this->presentToken($this->token());

        self::assertNull($this->outcome(), 'una rotación no es manipulación');
        self::assertSame(2, $this->client->jwksFetches);
    }

    public function testUnknownSigningKeyAfterTheRefetchIsTampered(): void
    {
        $this->client->snapshotResponse = [
            'code'  => 200,
            'body'  => $this->signSnapshot($this->payload(), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            'error' => '',
        ];
        $this->presentToken($this->token());

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:CEL-SNAPSHOT-INVALID', $msg);
        self::assertSame(2, $this->client->jwksFetches, 'reintentó el JWKS una vez');
    }

    public function testAnUnreachableJwksIsUnavailableNotTampered(): void
    {
        $this->client->jwksResponses = [['code' => 0, 'body' => '', 'error' => 'timeout']];
        $this->presentToken($this->token());

        $msg = $this->outcome();
        self::assertNotNull($msg, 'sin snapshot verificable y con token: retenido en todo modo');
        self::assertStringContainsString('trusteed:CEL-TIMEOUT', $msg);
        self::assertStringNotContainsString('SNAPSHOT-INVALID', $msg);
    }

    // ── Unavailable snapshot (missing_snapshot) ────────────────────────────

    public function testUnavailableSnapshotWithTokenBlocksTheAgentInEveryMode(): void
    {
        $this->snapshotDown();
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            $this->presentToken($this->token());
            $msg = $this->outcome();
            self::assertNotNull($msg, 'antes pasaba en ' . $mode . ' con la bandera apagada');
            self::assertStringContainsString('trusteed:CEL-TIMEOUT', $msg, $mode);
            self::assertStringContainsString('snapshot_pull_failed', $msg, $mode);
        }
        self::assertSame(3, $this->logsContaining('"kind":"missing_snapshot"'));
    }

    public function testUnavailableSnapshotNeverBlocksAHuman(): void
    {
        $this->snapshotDown();
        foreach (self::MODES as $mode) {
            $this->mode($mode);
            self::assertNull($this->outcome(), $mode);
        }
        self::assertCount(0, $this->client->posts);
    }

    public function testTheFiveMinuteCacheServesWhenTheSnapshotEndpointFails(): void
    {
        $this->presentToken($this->token());
        self::assertNull($this->outcome());

        $this->client->snapshotResponse = ['code' => 503, 'body' => '', 'error' => ''];
        $this->mode('strict');
        $this->presentToken($this->token());

        self::assertNull($this->outcome(), 'la caché propia verificada es un snapshot válido');
        self::assertCount(2, $this->client->evaluateBodies());
    }

    public function testOurOwnCacheThatNoLongerVerifiesIsUnavailableNotTampered(): void
    {
        $this->presentToken($this->token());
        self::assertNull($this->outcome());

        // Platform key rotated and endpoint down: the cached copy no longer verifies.
        (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->setValue(null, array_filter(
            (new \ReflectionProperty(SnapshotClient::class, 'memCache'))->getValue(),
            static fn (string $k): bool => !str_starts_with($k, 'trusteed_cel_jwks_'),
            ARRAY_FILTER_USE_KEY
        ));
        $this->client->jwksResponses = [$this->jwks([self::SNAP_KID => SnapshotClient::base64UrlEncode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))])];
        $this->client->snapshotResponse = ['code' => 503, 'body' => '', 'error' => ''];
        $this->presentToken($this->token());

        $msg = $this->outcome();
        self::assertNotNull($msg);
        self::assertStringContainsString('trusteed:CEL-TIMEOUT', $msg, 'nuestra caché no es prueba de manipulación');
    }

    // ── Layer switched off (layer_disabled) ────────────────────────────────

    public function testDisabledLayerNeverBlocksAnAgent(): void
    {
        TestPsState::$config['TRUSTEED_CEL_ENABLED'] = '0';
        $this->mode('strict');
        $this->presentToken($this->forgedToken());

        self::assertNull($this->outcome());
        self::assertSame(0, $this->client->snapshotFetches);
    }

    public function testUnconfiguredModuleNeverBlocksAnAgent(): void
    {
        $this->mode('strict');
        $this->presentToken($this->forgedToken());
        $hook = new ValidateOrderHook(static fn (int $shopId): ?SnapshotClient => null);

        $hook->evaluateCart(new \Cart());

        self::assertSame(0, $this->client->snapshotFetches);
    }
}
