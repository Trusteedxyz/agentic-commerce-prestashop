<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\InstallationProvisioner;

/**
 * Alta de la instalación de enforcement tras el auto-registro (auditoría
 * agent-readiness 2026-09-26, A3): sin Installation ID ni HMAC el módulo no
 * evalúa ni una regla y el back office muestra «checkout not protected».
 *
 * Pedir la provisión con una instalación existente ROTA el secreto, así que el
 * cliente sólo debe llamarse cuando falta alguna de las dos credenciales; eso lo
 * decide `needed()`, que es lógica pura.
 */
final class InstallationProvisionerTest extends TestCase
{
    private const MERCHANT = 'ae796e63-ca00-4bba-baa4-9b25da4b910d';

    private string $secret;

    protected function setUp(): void
    {
        $this->secret = str_repeat('ab', 32);
    }

    public function testPideLaInstalacionConElSecretoS2SYDevuelveLasCredenciales(): void
    {
        $seen = null;
        $p = new InstallationProvisioner(
            'https://api.trusteed.xyz/',
            function (string $url, array $headers, string $body) use (&$seen): array {
                $seen = compact('url', 'headers', 'body');
                return ['status' => 201, 'body' => '{"installationId":"inst-1","hmacSecret":"' . str_repeat('cd', 32) . '"}'];
            }
        );

        $out = $p->provision(self::MERCHANT, $this->secret);

        $this->assertSame(['installationId' => 'inst-1', 'hmacSecret' => str_repeat('cd', 32)], $out);
        $this->assertSame('https://api.trusteed.xyz/api/v1/enforcement/installations/provision', $seen['url']);
        $this->assertSame($this->secret, $seen['headers']['X-Embed-Ps-Secret']);
        $this->assertSame(
            ['merchantId' => self::MERCHANT, 'platform' => 'PRESTASHOP'],
            json_decode($seen['body'], true)
        );
    }

    public function testSinEntradasValidasNoLlamaALaRed(): void
    {
        $called = false;
        $p = new InstallationProvisioner('https://api.trusteed.xyz', function () use (&$called): array {
            $called = true;
            return ['status' => 201, 'body' => '{}'];
        });

        $this->assertNull($p->provision('', $this->secret));
        $this->assertNull($p->provision('no-es-uuid', $this->secret));
        $this->assertNull($p->provision(self::MERCHANT, ''));
        $this->assertNull($p->provision(self::MERCHANT, 'corto'));
        $this->assertFalse($called);
    }

    /** @dataProvider fallos */
    public function testDevuelveNullSiAlgoFalla(int $status, string $body): void
    {
        $p = new InstallationProvisioner('https://api.trusteed.xyz', fn (): array => ['status' => $status, 'body' => $body]);
        $this->assertNull($p->provision(self::MERCHANT, $this->secret));
    }

    /** @return array<string,array{int,string}> */
    public static function fallos(): array
    {
        return [
            'ruta apagada (404)' => [404, '{"error":"not_found"}'],
            'secreto no casa (401)' => [401, '{"error":"unauthenticated"}'],
            'vault caído (503)' => [503, '{"error":"vault_unavailable"}'],
            'respuesta que no es JSON' => [201, 'no json'],
            'sin installationId' => [201, '{"hmacSecret":"x"}'],
            'sin hmacSecret' => [201, '{"installationId":"i"}'],
            'campos vacíos' => [201, '{"installationId":"","hmacSecret":""}'],
        ];
    }

    public function testUnaExcepcionDeRedNoSeEscapa(): void
    {
        $p = new InstallationProvisioner('https://api.trusteed.xyz', function (): array {
            throw new \RuntimeException('timeout');
        });
        $this->assertNull($p->provision(self::MERCHANT, $this->secret));
    }

    /** @dataProvider necesidad */
    public function testNeededSoloSiFaltaAlgunaCredencial(string $id, string $hmac, bool $expected): void
    {
        $this->assertSame($expected, InstallationProvisioner::needed($id, $hmac));
    }

    /** @return array<string,array{string,string,bool}> */
    public static function necesidad(): array
    {
        return [
            'faltan las dos' => ['', '', true],
            'falta el HMAC' => ['inst-1', '', true],
            'falta el id' => ['', 'abc', true],
            'solo espacios' => ['  ', 'abc', true],
            'ya hay las dos: no se rota' => ['inst-1', 'abc', false],
        ];
    }
}
