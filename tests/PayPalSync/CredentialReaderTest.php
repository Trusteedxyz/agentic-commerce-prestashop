<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\PayPalSync\CredentialReader;
use Trusteed\Service\PayPalSync\PayPalCredentials;
use Trusteed\Service\PayPalSync\SecretDecryptor;

require_once __DIR__ . '/InMemoryConfig.php';

/**
 * Lector de `paypal` (202 ecommerce) para PrestaShop. Las claves y el cifrado
 * están verificados contra el código del módulo (classes/MethodEC.php,
 * services/ToolKit.php); aquí se fija la lógica con un descifrador inyectado.
 */
final class CredentialReaderTest extends TestCase
{
    private const CID = 'AbCdEfGhIjKlMnOpQrStUv01';
    private string $secret;

    protected function setUp(): void
    {
        // Concatenado: el escáner de secretos no debe verlo como una credencial.
        $this->secret = 'EXAMPLE' . '-' . 'secret' . '_' . 'value' . '-0123456789';
    }

    private function decryptor(): SecretDecryptor
    {
        $plain = $this->secret;
        return new class($plain) implements SecretDecryptor {
            public function __construct(private string $plain)
            {
            }

            public function decrypt(string $cipher): ?string
            {
                return $cipher === 'CIPHER' ? $this->plain : null;
            }
        };
    }

    private function config(string $sandbox, string $suffix, string $id = self::CID, string $cipher = 'CIPHER'): InMemoryConfig
    {
        return (new InMemoryConfig())
            ->put('PAYPAL_SANDBOX', $sandbox, 1, 3)
            ->put('PAYPAL_EC_CLIENTID_' . $suffix, $id, 1, 3)
            ->put('PAYPAL_EC_SECRET_' . $suffix, $cipher, 1, 3);
    }

    public function testSandboxLeeLasClavesSandbox(): void
    {
        $c = (new CredentialReader($this->config('1', 'SANDBOX'), $this->decryptor()))->read(1, 3);
        $this->assertSame(PayPalCredentials::STATE_OK, $c->state());
        $this->assertSame('sandbox', $c->mode());
        $this->assertSame(self::CID, $c->clientId());
        $this->assertSame($this->secret, $c->secret());
    }

    public function testLiveLeeLasClavesLive(): void
    {
        $c = (new CredentialReader($this->config('0', 'LIVE'), $this->decryptor()))->read(1, 3);
        $this->assertSame(PayPalCredentials::STATE_OK, $c->state());
        $this->assertSame('production', $c->mode());
    }

    public function testElModoSaleDeLaTiendaPedidaNoDeOtra(): void
    {
        $cfg = $this->config('1', 'SANDBOX')
            ->put('PAYPAL_SANDBOX', '0', 1, 4)
            ->put('PAYPAL_EC_CLIENTID_LIVE', 'ZyXwVuTsRqPoNmLkJiHg9876', 1, 4)
            ->put('PAYPAL_EC_SECRET_LIVE', 'CIPHER', 1, 4);
        $reader = new CredentialReader($cfg, $this->decryptor());
        $this->assertSame('sandbox', $reader->read(1, 3)->mode());
        $this->assertSame('production', $reader->read(1, 4)->mode());
        $this->assertSame('ZyXwVuTsRqPoNmLkJiHg9876', $reader->read(1, 4)->clientId());
    }

    public function testVaciosDelModoActivoSonAusentes(): void
    {
        $cfg = $this->config('1', 'SANDBOX', '', '');
        $this->assertSame(PayPalCredentials::STATE_ABSENT, (new CredentialReader($cfg, $this->decryptor()))->read(1, 3)->state());
    }

    public function testNoLeeLasClavesDelModoContrario(): void
    {
        // Sandbox activo, pero sólo hay claves live: no se usan (y no se entregan).
        $cfg = $this->config('1', 'LIVE');
        $c = (new CredentialReader($cfg, $this->decryptor()))->read(1, 3);
        $this->assertNotSame(PayPalCredentials::STATE_OK, $c->state());
        $this->assertSame('', $c->clientId());
    }

    /** M2: cambiar de modo sin credenciales en el nuevo NO es «vació PayPal». */
    public function testModoActivoVacioConElOtroConfiguradoEsInvalidoNoAusente(): void
    {
        $cfg = $this->config('1', 'LIVE');
        $this->assertSame(PayPalCredentials::STATE_INVALID, (new CredentialReader($cfg, $this->decryptor()))->read(1, 3)->state());

        $cfg2 = $this->config('0', 'SANDBOX');
        $this->assertSame(PayPalCredentials::STATE_INVALID, (new CredentialReader($cfg2, $this->decryptor()))->read(1, 3)->state());
    }

    public function testSoloEsAusenteConLosDosModosVacios(): void
    {
        $cfg = $this->config('1', 'SANDBOX', '', '');
        $cfg->put('PAYPAL_EC_CLIENTID_LIVE', '', 1, 3)->put('PAYPAL_EC_SECRET_LIVE', '', 1, 3);
        $this->assertSame(PayPalCredentials::STATE_ABSENT, (new CredentialReader($cfg, $this->decryptor()))->read(1, 3)->state());
    }

    /** M2: el otro modo con sólo el secreto (o sólo el id) también cuenta como configurado. */
    public function testOtroModoMedioConfiguradoTambienBloqueaLaAusencia(): void
    {
        $cfg = $this->config('1', 'SANDBOX', '', '');
        $cfg->put('PAYPAL_EC_SECRET_LIVE', 'CIPHER', 1, 3);
        $this->assertSame(PayPalCredentials::STATE_INVALID, (new CredentialReader($cfg, $this->decryptor()))->read(1, 3)->state());
    }

    public function testSecretoQueNoDescifraEsInvalidoYNoSeEntregaCifrado(): void
    {
        $cfg = $this->config('0', 'LIVE', self::CID, 'OTRA-COSA');
        $c = (new CredentialReader($cfg, $this->decryptor()))->read(1, 3);
        $this->assertSame(PayPalCredentials::STATE_INVALID, $c->state());
        $this->assertSame('', $c->secret());
    }

    /** @dataProvider valoresFueraDeCota */
    public function testValidacionDeLongitudYJuego(string $id): void
    {
        $cfg = $this->config('0', 'LIVE', $id);
        $this->assertSame(PayPalCredentials::STATE_INVALID, (new CredentialReader($cfg, $this->decryptor()))->read(1, 3)->state());
    }

    /** @return array<string,array{string}> */
    public static function valoresFueraDeCota(): array
    {
        return [
            'corto' => ['abc'],
            'largo' => [str_repeat('a', 201)],
            'caracter ajeno' => ['AbCdEfGhIjKlMnOpQrSt U'],
        ];
    }

    public function testDescribeSoloModoYUltimosCuatro(): void
    {
        $c = (new CredentialReader($this->config('1', 'SANDBOX'), $this->decryptor()))->read(1, 3);
        $d = $c->describe();
        $this->assertSame(['mode' => 'sandbox', 'last4' => 'Uv01'], $d);
        $this->assertStringNotContainsString($this->secret, json_encode($d));
        $this->assertStringNotContainsString($this->secret, print_r($c, true));
        $this->assertStringNotContainsString($this->secret, var_export($c->describe(), true));
    }

    public function testHuellaCambiaConIdSecretoOModo(): void
    {
        $r = new CredentialReader($this->config('1', 'SANDBOX'), $this->decryptor());
        $a = $r->read(1, 3)->fingerprint();
        $this->assertSame(hash('sha256', self::CID . '|' . $this->secret . '|sandbox'), $a);
        $b = (new CredentialReader($this->config('1', 'SANDBOX', 'QqQqQqQqQqQqQqQqQqQq01'), $this->decryptor()))->read(1, 3)->fingerprint();
        $this->assertNotSame($a, $b);
    }
}
