<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\ApiBaseAllowlist;

/**
 * La lista de hosts permitidos salió de AdminTrusteedController::isAllowedApiBase()
 * (privado) sin cambiar de comportamiento. Estos casos fijan el comportamiento
 * que tenía.
 */
final class ApiBaseAllowlistTest extends TestCase
{
    /** @dataProvider casos */
    public function testComportamientoHeredadoDelControlador(string $url, bool $esperado): void
    {
        $this->assertSame($esperado, ApiBaseAllowlist::isAllowed($url), $url);
    }

    /** @return array<string,array{string,bool}> */
    public static function casos(): array
    {
        return [
            'produccion https' => ['https://api.trusteed.xyz', true],
            'railway https' => ['https://agenticmcpstores-production.up.railway.app', true],
            'staging https' => ['https://staging-api.trusteed.xyz/base', true],
            'host ajeno https' => ['https://evil.example', false],
            'subdominio engañoso' => ['https://api.trusteed.xyz.evil.example', false],
            'host de produccion por http' => ['http://api.trusteed.xyz', false],
            'dev http localhost' => ['http://localhost:3001', true],
            'dev http docker' => ['http://172.17.0.1:3001', true],
            'dev host por https no vale' => ['https://localhost', false],
            'esquema raro' => ['ftp://api.trusteed.xyz', false],
            'sin host' => ['no-es-una-url', false],
            'vacio' => ['', false],
        ];
    }

    /** @dataProvider casosSecretos */
    public function testParaSecretosSoloHttpsAHostsDeTrusteed(string $url, bool $esperado): void
    {
        $this->assertSame($esperado, ApiBaseAllowlist::isAllowedForSecrets($url), $url);
    }

    /** @return array<string,array{string,bool}> */
    public static function casosSecretos(): array
    {
        return [
            'produccion' => ['https://api.trusteed.xyz', true],
            'staging' => ['https://staging-api.trusteed.xyz/base', true],
            'railway' => ['https://agenticmcpstores-production.up.railway.app', true],
            'ip publica de desarrollo por http' => ['http://38.242.232.176', false],
            'localhost por http' => ['http://localhost:3001', false],
            'docker por http' => ['http://172.17.0.1:3001', false],
            'produccion por http' => ['http://api.trusteed.xyz', false],
            'host ajeno' => ['https://evil.example', false],
            'credenciales en la url' => ['https://api.trusteed.xyz@evil.example', false],
            'vacio' => ['', false],
        ];
    }

    public function testElComportamientoGeneralNoCambia(): void
    {
        $this->assertTrue(ApiBaseAllowlist::isAllowed('http://38.242.232.176'));
    }
}
