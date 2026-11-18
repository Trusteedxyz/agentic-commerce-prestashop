<?php

declare(strict_types=1);

namespace Trusteed\Tests\PolicyPages;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\PolicyPages\CmsPageSource;
use Trusteed\Service\PolicyPages\PolicyPagesBuilder;
use Trusteed\Service\PolicyPages\PolicyPagesSyncService;
use Trusteed\Tests\PayPalSync\InMemoryConfig;

require_once __DIR__ . '/../PayPalSync/InMemoryConfig.php';

final class PolicyPagesSyncServiceTest extends TestCase
{
    private const API = 'https://api.trusteed.xyz';

    private InMemoryConfig $cfg;
    /** @var array<int,array{url:string,title:string,modified:string}> */
    private array $pages;
    /** @var list<array{url:string,headers:array<string,string>,body:string}> */
    private array $sent = [];
    private int $status = 200;
    private string $respBody = '{"stored":true}';
    private ?string $token = 'tok';
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->cfg = (new InMemoryConfig())
            ->put('TRUSTEED_CEL_MERCHANT_ID', 'mer_abc', 1, 3)
            ->put('PS_CONDITIONS_CMS_ID', '3', 1, 3)
            ->put('TRUSTEED_POLICY_RETURNS_CMS_ID', '7', 1, 3);
        $this->pages = [
            3 => ['url' => 'https://shop.example/content/3-terms', 'title' => 'Terms', 'modified' => '2026-11-18 10:00:00'],
            7 => ['url' => 'https://shop.example/content/7-returns', 'title' => '', 'modified' => '2026-11-17 08:30:00'],
        ];
    }

    private function source(): CmsPageSource
    {
        $pages = &$this->pages;

        return new class($pages) implements CmsPageSource {
            /** @param array<int,array{url:string,title:string,modified:string}> $pages */
            public function __construct(private array &$pages)
            {
            }

            public function page(int $idCms, int $idShop): ?array
            {
                return $this->pages[$idCms] ?? null;
            }

            public function shopHost(int $idShop): string
            {
                return 'shop.example';
            }

            public function timezone(): string
            {
                return 'Europe/Madrid';
            }

            public function activePages(int $idShop): array
            {
                return [];
            }
        };
    }

    private function service(string $api = self::API): PolicyPagesSyncService
    {
        return new PolicyPagesSyncService(
            $this->cfg,
            $this->source(),
            fn (int $s, string $m): ?string => $this->token,
            $api,
            function (string $url, array $headers, string $body): array {
                $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

                return ['status' => $this->status, 'body' => $this->respBody];
            },
            fn (): int => $this->now
        );
    }

    public function testEnviaTermsYReturnsConElContratoYSinClavesExtra(): void
    {
        $r = $this->service()->sync(1, 3);

        $this->assertSame(['ok' => true, 'code' => 'sent'], $r);
        $req = $this->sent[0];
        $this->assertSame(self::API . '/v1/embed/merchant/policy-pages', $req['url']);
        $this->assertSame('Bearer tok', $req['headers']['Authorization']);
        $this->assertSame('ps-embed', $req['headers']['X-Embed-Source']);
        $this->assertSame([
            'policyPages' => [
                ['type' => 'terms', 'url' => 'https://shop.example/content/3-terms', 'title' => 'Terms', 'page_modified_at' => '2026-11-18T09:00:00Z'],
                ['type' => 'returns', 'url' => 'https://shop.example/content/7-returns', 'page_modified_at' => '2026-11-17T07:30:00Z'],
            ],
        ], json_decode($req['body'], true));
    }

    public function testSinDevolucionesElegidasNoMandaReturnsNiPrivacy(): void
    {
        $this->cfg->put('TRUSTEED_POLICY_RETURNS_CMS_ID', '', 1, 3);
        $this->service()->sync(1, 3);
        $types = array_column(json_decode($this->sent[0]['body'], true)['policyPages'], 'type');
        $this->assertSame(['terms'], $types);
    }

    public function testSinNingunaPaginaYSinEnvioPrevioNoEnvia(): void
    {
        $this->cfg->put('TRUSTEED_POLICY_RETURNS_CMS_ID', '', 1, 3)->put('PS_CONDITIONS_CMS_ID', '', 1, 3);
        $this->assertSame(['ok' => true, 'code' => 'no_pages'], $this->service()->sync(1, 3));
        $this->assertSame([], $this->sent);
    }

    public function testQuitarTodasLasPaginasTrasEnviarMandaListaVaciaUnaSolaVez(): void
    {
        $this->service()->sync(1, 3);
        $this->cfg->put('TRUSTEED_POLICY_RETURNS_CMS_ID', '', 1, 3)->put('PS_CONDITIONS_CMS_ID', '', 1, 3);

        $this->assertSame('sent', $this->service()->syncIfChanged(1, 3)['code']);
        $this->assertCount(2, $this->sent);
        $this->assertSame('{"policyPages":[]}', $this->sent[1]['body']);
        $this->assertNull($this->service()->syncIfChanged(1, 3), 'vacío ya entregado: no repite');
        $this->assertCount(2, $this->sent);
    }

    public function testListaVaciaConStoredFalseSeDaPorEntregada(): void
    {
        $this->service()->sync(1, 3);
        $this->cfg->put('TRUSTEED_POLICY_RETURNS_CMS_ID', '', 1, 3)->put('PS_CONDITIONS_CMS_ID', '', 1, 3);
        $this->respBody = '{"stored":false}'; // «no había nada que borrar»

        $this->assertSame('sent', $this->service()->sync(1, 3)['code']);
        $this->assertNull($this->service()->syncIfChanged(1, 3), 'entregada: no repite');
    }

    public function testPaginasQueDejanDeSerValidasTrasEnviarTambienVacian(): void
    {
        $this->service()->sync(1, 3);
        $this->pages = [];
        $this->assertSame('sent', $this->service()->syncIfChanged(1, 3)['code']);
        $this->assertSame('{"policyPages":[]}', $this->sent[1]['body']);
    }

    public function testPaginaInactivaOAjenaALaTiendaSeOmite(): void
    {
        unset($this->pages[3]);
        $this->pages[7]['url'] = 'https://other.example/returns';
        $this->assertSame(['ok' => true, 'code' => 'no_pages'], $this->service()->sync(1, 3));
        $this->assertSame([], $this->sent);
    }

    /** @dataProvider urlsInvalidas */
    public function testUrlsInvalidasSeRechazan(string $url): void
    {
        $this->assertFalse(PolicyPagesBuilder::isShopUrl($url, 'shop.example'));
    }

    /** @return array<string,array{string}> */
    public static function urlsInvalidas(): array
    {
        return [
            'http' => ['http://shop.example/a'],
            'credenciales' => ['https://u:p@shop.example/a'],
            'ip' => ['https://127.0.0.1/a'],
            'otro host' => ['https://evil.example/a'],
            'larga' => ['https://shop.example/' . str_repeat('a', 2100)],
        ];
    }

    public function testFechaCeroSeOmite(): void
    {
        $this->assertNull(PolicyPagesBuilder::toUtcIso('0000-00-00 00:00:00', 'UTC'));
    }

    public function testPerezosoEsIdempotentePorHuella(): void
    {
        $this->assertSame('sent', $this->service()->syncIfChanged(1, 3)['code']);
        $this->assertNull($this->service()->syncIfChanged(1, 3));
        $this->assertCount(1, $this->sent);

        $this->pages[7]['modified'] = '2026-11-18 12:00:00';
        $this->assertSame('sent', $this->service()->syncIfChanged(1, 3)['code']);
        $this->assertCount(2, $this->sent);
    }

    public function testFalloEnfriaElPerezosoPeroNoElManual(): void
    {
        $this->status = 500;
        $this->respBody = '{"error":"policy_pages_store_failed"}';
        $r = $this->service()->syncIfChanged(1, 3);
        $this->assertSame(['ok' => false, 'code' => 'platform_rejected', 'reason' => 'policy_pages_store_failed'], $r);
        $this->assertNull($this->service()->syncIfChanged(1, 3));
        $this->assertCount(1, $this->sent);
        $this->service()->sync(1, 3);
        $this->assertCount(2, $this->sent);
        $this->assertSame('', $this->cfg->get('TRUSTEED_POLICY_PAGES_HASH', 1, 3), 'un fallo no marca entregado');
    }

    public function testStoredFalseNoMarcaEntregado(): void
    {
        $this->respBody = '{"stored":false}';
        $this->assertSame('not_stored', $this->service()->sync(1, 3)['code']);
        $this->assertSame('', $this->cfg->get('TRUSTEED_POLICY_PAGES_HASH', 1, 3));
    }

    public function testHostNoPermitidoSinComercioOSinTokenNoEnvia(): void
    {
        $this->assertSame('api_host_not_allowed', $this->service('https://evil.example')->sync(1, 3)['code']);
        $this->token = null;
        $this->assertSame('token_unavailable', $this->service()->sync(1, 3)['code']);
        $this->cfg->put('TRUSTEED_CEL_MERCHANT_ID', '', 1, 3);
        $this->assertSame('shop_not_mapped', $this->service()->sync(1, 3)['code']);
        $this->assertSame([], $this->sent);
    }
}
