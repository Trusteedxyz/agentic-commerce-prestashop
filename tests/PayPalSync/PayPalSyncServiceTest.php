<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\PayPalSync\CredentialReader;
use Trusteed\Service\PayPalSync\PayPalSyncService;
use Trusteed\Service\PayPalSync\SecretDecryptor;

require_once __DIR__ . '/InMemoryConfig.php';

/**
 * Entrega a la plataforma. Misma semántica que Trusteed_PayPal_Sync (WooCommerce):
 * opt-in, huella para idempotencia, nunca el secreto fuera del cuerpo del envío.
 */
final class PayPalSyncServiceTest extends TestCase
{
    private const CID = 'AbCdEfGhIjKlMnOpQrStUv01';
    private const API = 'https://api.trusteed.xyz';
    private string $secret;
    private InMemoryConfig $cfg;
    /** @var list<array{url:string,headers:array<string,string>,body:string}> */
    private array $sent = [];
    /** @var list<array{int,string}> */
    private array $tokenAsks = [];
    private int $nextStatus = 200;
    private string $nextBody = '{}';
    private ?string $token = 'tok-embed';
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->secret = 'EXAMPLE' . '-' . 'secret' . '_' . 'value' . '-0123456789';
        $this->cfg = (new InMemoryConfig())
            ->put('TRUSTEED_CEL_MERCHANT_ID', 'mer_abc', 1, 3)
            ->put('PAYPAL_SANDBOX', '1', 1, 3)
            ->put('PAYPAL_EC_CLIENTID_SANDBOX', self::CID, 1, 3)
            ->put('PAYPAL_EC_SECRET_SANDBOX', 'CIPHER', 1, 3);
    }

    /** @param array<int,?int>|null $shops idShop => idGroup de las tiendas activas */
    private function service(string $apiBase = self::API, ?array $shops = null): PayPalSyncService
    {
        $plain = $this->secret;
        $decryptor = new class($plain) implements SecretDecryptor {
            public function __construct(private string $plain)
            {
            }

            public function decrypt(string $cipher): ?string
            {
                return $cipher === 'CIPHER' ? $this->plain : null;
            }
        };

        return new PayPalSyncService(
            $this->cfg,
            new CredentialReader($this->cfg, $decryptor),
            function (int $idShop, string $merchantId): ?string {
                $this->tokenAsks[] = [$idShop, $merchantId];
                return $this->token;
            },
            $apiBase,
            function (string $url, array $headers, string $body): array {
                $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
                return ['status' => $this->nextStatus, 'body' => $this->nextBody];
            },
            fn (): int => $this->now,
            $shops === null ? null : static fn (): array => $shops
        );
    }

    public function testSyncEntregaConnectConElContratoDeLaApi(): void
    {
        $r = $this->service()->sync(1, 3);

        $this->assertSame(['ok' => true, 'code' => 'connected', 'mode' => 'sandbox'], $r);
        $this->assertCount(1, $this->sent);
        $req = $this->sent[0];
        $this->assertSame(self::API . '/v1/embed/merchant/payment-methods/paypal/connect', $req['url']);
        $this->assertSame('Bearer tok-embed', $req['headers']['Authorization']);
        $this->assertSame('ps-embed', $req['headers']['X-Embed-Source']);
        $this->assertSame(
            ['flow' => 'apiKey', 'accountId' => self::CID, 'apiKey' => $this->secret, 'mode' => 'sandbox', 'force' => true],
            json_decode($req['body'], true)
        );
        $this->assertSame([[3, 'mer_abc']], $this->tokenAsks);
    }

    public function testSyncExitosoActivaElOptInYGuardaLaHuellaPorTienda(): void
    {
        $this->service()->sync(1, 3);
        $this->assertSame('1', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_ENABLED', 1, 3));
        $this->assertSame(
            hash('sha256', self::CID . '|' . $this->secret . '|sandbox'),
            $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3)
        );
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 4), 'la huella es de la tienda');
    }

    public function testHostFueraDeLaAllowlistNoEnviaNiPideToken(): void
    {
        $r = $this->service('https://evil.example')->sync(1, 3);
        $this->assertSame(['ok' => false, 'code' => 'api_host_not_allowed'], $r);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->tokenAsks);
    }

    public function testSinCredencialesNoEnviaNada(): void
    {
        $this->cfg->put('PAYPAL_EC_CLIENTID_SANDBOX', '', 1, 3)->put('PAYPAL_EC_SECRET_SANDBOX', '', 1, 3);
        $this->assertSame(['ok' => false, 'code' => 'no_paypal_credentials'], $this->service()->sync(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testSecretoQueNoDescifraNoSeEnvia(): void
    {
        $this->cfg->put('PAYPAL_EC_SECRET_SANDBOX', 'BASURA', 1, 3);
        $this->assertSame(['ok' => false, 'code' => 'no_paypal_credentials'], $this->service()->sync(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testTiendaSinComercioMapeadoNoEnvia(): void
    {
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', '', 1, 3);
        $this->assertSame(['ok' => false, 'code' => 'shop_not_mapped'], $this->service()->sync(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testSinTokenNoEnvia(): void
    {
        $this->token = null;
        $this->assertSame(['ok' => false, 'code' => 'token_unavailable'], $this->service()->sync(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testRechazoDeLaPlataformaDevuelveSoloElCodigoSinElSecreto(): void
    {
        $this->nextStatus = 409;
        $this->nextBody = '{"error":"demo_credentials_rejected","echo":"' . $this->secret . '"}';
        $r = $this->service()->sync(1, 3);

        $this->assertSame(['ok' => false, 'code' => 'platform_rejected', 'reason' => 'demo_credentials_rejected'], $r);
        $this->assertStringNotContainsString($this->secret, json_encode($r));
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3), 'un fallo no guarda huella');
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_ENABLED', 1, 3), 'un fallo no activa el opt-in');
    }

    public function testRechazoSinCuerpoUsaElEstadoHttp(): void
    {
        $this->nextStatus = 502;
        $this->nextBody = 'html';
        $this->assertSame('http_502', $this->service()->sync(1, 3)['reason']);
    }

    public function testDescribeNoExponeElSecreto(): void
    {
        $d = $this->service()->describe(1, 3);
        $this->assertSame(['available' => true, 'enabled' => false, 'mode' => 'sandbox', 'last4' => 'Uv01'], $d);
        $this->assertStringNotContainsString($this->secret, json_encode($d));
    }

    // ── Re-sync perezoso ────────────────────────────────────────────────

    public function testResyncSinOptInNoHaceNada(): void
    {
        $this->assertNull($this->service()->resyncIfChanged(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testResyncConHuellaIgualNoReenvia(): void
    {
        $svc = $this->service();
        $svc->sync(1, 3);
        $this->sent = [];
        $this->assertNull($svc->resyncIfChanged(1, 3));
        $this->assertSame([], $this->sent);
        $this->assertSame([], array_slice($this->tokenAsks, 1), 'ni siquiera pide token');
    }

    public function testResyncTrasCambiarElModoReenvia(): void
    {
        $svc = $this->service();
        $svc->sync(1, 3);
        $this->sent = [];
        $this->cfg->put('PAYPAL_SANDBOX', '0', 1, 3)
            ->put('PAYPAL_EC_CLIENTID_LIVE', 'ZyXwVuTsRqPoNmLkJiHg9876', 1, 3)
            ->put('PAYPAL_EC_SECRET_LIVE', 'CIPHER', 1, 3);

        $r = $svc->resyncIfChanged(1, 3);
        $this->assertSame(['ok' => true, 'code' => 'connected', 'mode' => 'production'], $r);
        $this->assertCount(1, $this->sent);
        $this->assertSame('production', json_decode($this->sent[0]['body'], true)['mode']);
    }

    public function testFalloDeResyncHaceEsperaAntesDeReintentar(): void
    {
        $svc = $this->service();
        $svc->sync(1, 3);
        $this->cfg->put('PAYPAL_EC_CLIENTID_SANDBOX', 'QqQqQqQqQqQqQqQqQqQq01', 1, 3);
        $this->nextStatus = 500;
        $this->sent = [];

        $first = $svc->resyncIfChanged(1, 3);
        $this->assertFalse($first['ok']);
        $this->assertCount(1, $this->sent);

        // Otra página del back office a los 10 s: no vuelve a llamar.
        $this->now += 10;
        $this->assertNull($svc->resyncIfChanged(1, 3));
        $this->assertCount(1, $this->sent);

        // Pasada la espera, reintenta.
        $this->now += PayPalSyncService::RETRY_COOLDOWN_SECONDS;
        $this->assertFalse($svc->resyncIfChanged(1, 3)['ok']);
        $this->assertCount(2, $this->sent);
    }

    public function testSyncManualIgnoraLaEspera(): void
    {
        $svc = $this->service();
        $this->nextStatus = 500;
        $svc->sync(1, 3);
        $svc->sync(1, 3);
        $this->assertCount(2, $this->sent);
    }

    // ── Desconexión (D5) ────────────────────────────────────────────────

    public function testVaciarPayPalConOptInYEntregaPreviaDesconecta(): void
    {
        $svc = $this->service();
        $svc->sync(1, 3);
        $this->sent = [];
        $this->cfg->put('PAYPAL_EC_CLIENTID_SANDBOX', '', 1, 3)->put('PAYPAL_EC_SECRET_SANDBOX', '', 1, 3);

        $r = $svc->resyncIfChanged(1, 3);
        $this->assertSame(['ok' => true, 'code' => 'disconnected'], $r);
        $this->assertSame(self::API . '/v1/embed/merchant/payment-methods/paypal/disconnect', $this->sent[0]['url']);
        $this->assertSame('Bearer tok-embed', $this->sent[0]['headers']['Authorization']);
        $this->assertSame('ps-embed', $this->sent[0]['headers']['X-Embed-Source']);
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3), 'reconectar las mismas credenciales vuelve a entregarlas');
    }

    public function testAusenteSinEntregaPreviaNoDesconectaNada(): void
    {
        $this->cfg->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '1', 1, 3);
        $this->cfg->put('PAYPAL_EC_CLIENTID_SANDBOX', '', 1, 3)->put('PAYPAL_EC_SECRET_SANDBOX', '', 1, 3);
        $this->assertNull($this->service()->resyncIfChanged(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testSecretoIlegibleNoDesconecta(): void
    {
        // Un fallo de descifrado (clave del CMS cambiada) no es «el comercio desconectó».
        $svc = $this->service();
        $svc->sync(1, 3);
        $this->sent = [];
        $this->cfg->put('PAYPAL_EC_SECRET_SANDBOX', 'BASURA', 1, 3);
        $this->assertNull($svc->resyncIfChanged(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testDesconexionFallidaConservaLaHuella(): void
    {
        $svc = $this->service();
        $svc->sync(1, 3);
        $hash = $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3);
        $this->cfg->put('PAYPAL_EC_CLIENTID_SANDBOX', '', 1, 3)->put('PAYPAL_EC_SECRET_SANDBOX', '', 1, 3);
        $this->nextStatus = 503;

        $this->assertFalse($svc->resyncIfChanged(1, 3)['ok']);
        $this->assertSame($hash, $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3));
    }

    public function testDesactivarBorraOptInYHuella(): void
    {
        $svc = $this->service();
        $svc->sync(1, 3);
        $svc->disable(1, 3);
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_ENABLED', 1, 3));
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3));
        $this->assertNull($svc->resyncIfChanged(1, 3));
    }

    // ── Hosts de desarrollo: nunca reciben secretos ─────────────────────

    /** @dataProvider hostsDeDesarrollo */
    public function testNoEnviaCredencialesNiPideTokenAHostsDeDesarrolloNiPorHttp(string $api): void
    {
        $r = $this->service($api)->sync(1, 3);
        $this->assertSame(['ok' => false, 'code' => 'api_host_not_allowed'], $r);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->tokenAsks);
    }

    /** @return array<string,array{string}> */
    public static function hostsDeDesarrollo(): array
    {
        return [
            'ip publica sin tls' => ['http://38.242.232.176'],
            'localhost' => ['http://localhost:3001'],
            'produccion por http' => ['http://api.trusteed.xyz'],
        ];
    }

    // ── M2: cambiar PAYPAL_SANDBOX no desconecta la cuenta del otro modo ──

    public function testCambiarAModoSinCredencialesNoDesconectaLaCuentaEntregada(): void
    {
        $this->cfg->put('PAYPAL_SANDBOX', '0', 1, 3)
            ->put('PAYPAL_EC_CLIENTID_LIVE', self::CID, 1, 3)
            ->put('PAYPAL_EC_SECRET_LIVE', 'CIPHER', 1, 3);
        $svc = $this->service();
        $svc->sync(1, 3);
        $hash = $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3);
        $this->sent = [];

        // El comercio pone PAYPAL_SANDBOX=1 sin tener credenciales sandbox.
        $this->cfg->put('PAYPAL_SANDBOX', '1', 1, 3)
            ->put('PAYPAL_EC_CLIENTID_SANDBOX', '', 1, 3)
            ->put('PAYPAL_EC_SECRET_SANDBOX', '', 1, 3);

        $this->assertNull($svc->resyncIfChanged(1, 3));
        $this->assertSame([], $this->sent, 'ni connect ni disconnect');
        $this->assertSame($hash, $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3));
    }

    // ── H2: multitienda ─────────────────────────────────────────────────

    /** Segunda tienda (grupo 1) con su propio PayPal, mapeada explícitamente al mismo comercio. */
    private function shop4(string $merchant = 'mer_abc', string $cid = 'ZyXwVuTsRqPoNmLkJiHg9876'): void
    {
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', $merchant, 1, 4)
            ->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '1', 1, 4)
            ->put('PAYPAL_SANDBOX', '1', 1, 4)
            ->put('PAYPAL_EC_CLIENTID_SANDBOX', $cid, 1, 4)
            ->put('PAYPAL_EC_SECRET_SANDBOX', 'CIPHER', 1, 4);
    }

    public function testMultitiendaExigeComercioMapeadoEnLaPropiaTiendaNoElGlobal(): void
    {
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', '', 1, 3)->values = array_diff_key(
            $this->cfg->values,
            [InMemoryConfig::key('TRUSTEED_CEL_MERCHANT_ID', 1, 3) => 1]
        );
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', 'mer_global'); // sólo global

        $r = $this->service(self::API, [3 => 1, 4 => 1])->sync(1, 3);

        $this->assertSame(['ok' => false, 'code' => 'shop_not_mapped'], $r);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->tokenAsks);
    }

    public function testMultitiendaNoAceptaElValorDeGrupo(): void
    {
        unset($this->cfg->values[InMemoryConfig::key('TRUSTEED_CEL_MERCHANT_ID', 1, 3)]);
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', 'mer_grupo', 1, null);

        $this->assertSame('shop_not_mapped', $this->service(self::API, [3 => 1, 4 => 1])->sync(1, 3)['code']);
        $this->assertSame([], $this->sent);
    }

    public function testMonotiendaAceptaElComercioGlobal(): void
    {
        unset($this->cfg->values[InMemoryConfig::key('TRUSTEED_CEL_MERCHANT_ID', 1, 3)]);
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', 'mer_global');

        $r = $this->service(self::API, [3 => 1])->sync(1, 3);

        $this->assertTrue($r['ok']);
        $this->assertSame([[3, 'mer_global']], $this->tokenAsks);
    }

    public function testDosTiendasConOptInAlMismoComercioConHuellasDistintasSeRechazan(): void
    {
        $this->shop4();
        $this->cfg->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '1', 1, 3);

        $r = $this->service(self::API, [3 => 1, 4 => 1])->sync(1, 3);

        $this->assertSame(['ok' => false, 'code' => 'shared_merchant_conflict'], $r);
        $this->assertSame([], $this->sent, 'ni connect ni disconnect');
        $this->assertSame([], $this->tokenAsks);
        $this->assertSame('', $this->cfg->get('TRUSTEED_PAYPAL_SYNC_HASH', 1, 3));
    }

    public function testElConflictoTambienBloqueaElResyncPerezoso(): void
    {
        $this->shop4();
        $this->cfg->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '1', 1, 3);
        $r = $this->service(self::API, [3 => 1, 4 => 1])->resyncIfChanged(1, 3);
        $this->assertSame('shared_merchant_conflict', $r['code'] ?? null);
        $this->assertSame([], $this->sent);
    }

    public function testVaciarPayPalEnUnaTiendaNoDesconectaElComercioCompartido(): void
    {
        // Tienda 4 entregó y luego vacía PayPal; tienda 3 comparte comercio y tiene cuenta.
        $this->shop4();
        $this->cfg->put('TRUSTEED_PAYPAL_SYNC_HASH', 'hash-previo', 1, 4)
            ->put('PAYPAL_EC_CLIENTID_SANDBOX', '', 1, 4)
            ->put('PAYPAL_EC_SECRET_SANDBOX', '', 1, 4)
            ->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '1', 1, 3);

        $r = $this->service(self::API, [3 => 1, 4 => 1])->resyncIfChanged(1, 4);

        $this->assertSame('shared_merchant_conflict', $r['code'] ?? null);
        $this->assertSame([], $this->sent, 'no se envía disconnect');
    }

    public function testMismoComercioYMismaHuellaNoEsConflicto(): void
    {
        $this->shop4('mer_abc', self::CID);
        $this->cfg->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '1', 1, 3);
        $this->assertTrue($this->service(self::API, [3 => 1, 4 => 1])->sync(1, 3)['ok']);
    }

    public function testComerciosDistintosNoSeEstorban(): void
    {
        $this->shop4('mer_otro');
        $this->assertTrue($this->service(self::API, [3 => 1, 4 => 1])->sync(1, 3)['ok']);
    }

    public function testTiendaSinOptInNoCuentaParaElConflicto(): void
    {
        $this->shop4();
        $this->cfg->put('TRUSTEED_PAYPAL_SYNC_ENABLED', '', 1, 4);
        $this->assertTrue($this->service(self::API, [3 => 1, 4 => 1])->sync(1, 3)['ok']);
    }
}
