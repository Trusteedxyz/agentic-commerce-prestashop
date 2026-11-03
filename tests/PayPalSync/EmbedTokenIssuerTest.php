<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\PayPalSync\EmbedTokenIssuer;

/**
 * Emisión del token embed para el sync (mismo endpoint y cuerpo que
 * AdminTrusteedController::ajaxProcessToken, acotado a UNA tienda).
 */
final class EmbedTokenIssuerTest extends TestCase
{
    private string $secret;

    protected function setUp(): void
    {
        $this->secret = str_repeat('ab', 32);
    }

    public function testPideElTokenAlRelayDePrestaShopAcotadoALaTienda(): void
    {
        $seen = null;
        $issuer = new EmbedTokenIssuer(
            'https://api.trusteed.xyz',
            $this->secret,
            7,
            function (string $url, array $headers, string $body) use (&$seen): array {
                $seen = compact('url', 'headers', 'body');
                return ['status' => 200, 'body' => '{"token":"tok-1","expires_at":"x","jti":"j"}'];
            }
        );

        $this->assertSame('tok-1', $issuer->issue(3, 'mer_abc'));
        $this->assertSame('https://api.trusteed.xyz/v1/embed/ps/issue-token', $seen['url']);
        $this->assertSame($this->secret, $seen['headers']['X-Embed-Ps-Secret']);
        $this->assertSame(
            ['merchant_id' => 'mer_abc', 'ps_employee_id' => '7', 'capability_attestation' => 'admin_trusteed', 'allowed_shops' => [3]],
            json_decode($seen['body'], true)
        );
    }

    public function testDesenvuelveElWrapperData(): void
    {
        $issuer = new EmbedTokenIssuer('https://api.trusteed.xyz', $this->secret, 7, fn (): array => ['status' => 200, 'body' => '{"data":{"token":"tok-2"}}']);
        $this->assertSame('tok-2', $issuer->issue(3, 'mer_abc'));
    }

    /** @dataProvider fallos */
    public function testDevuelveNullSiAlgoFalla(int $status, string $body): void
    {
        $issuer = new EmbedTokenIssuer('https://api.trusteed.xyz', $this->secret, 7, fn (): array => ['status' => $status, 'body' => $body]);
        $this->assertNull($issuer->issue(3, 'mer_abc'));
    }

    /** @return array<string,array{int,string}> */
    public static function fallos(): array
    {
        return [
            'http 401' => [401, '{"error":"invalid_ps_secret"}'],
            'json roto' => [200, 'no'],
            'sin token' => [200, '{"foo":1}'],
            'token vacio' => [200, '{"token":""}'],
            'transporte' => [0, ''],
        ];
    }

    public function testSinEmpleadoOSecretoMalFormadoNoLlamaALaRed(): void
    {
        $calls = 0;
        $http = function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{"token":"t"}'];
        };
        $this->assertNull((new EmbedTokenIssuer('https://api.trusteed.xyz', $this->secret, 0, $http))->issue(3, 'mer_abc'));
        $this->assertNull((new EmbedTokenIssuer('https://api.trusteed.xyz', 'corto', 7, $http))->issue(3, 'mer_abc'));
        $this->assertNull((new EmbedTokenIssuer('https://api.trusteed.xyz', $this->secret, 7, $http))->issue(3, ''));
        $this->assertSame(0, $calls);
    }
}
