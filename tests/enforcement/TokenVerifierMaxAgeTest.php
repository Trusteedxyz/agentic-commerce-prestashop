<?php

declare(strict_types=1);

/**
 * Decisión 48 (2026-10-02) — el verificador de TOKEN de PrestaShop acota la
 * antigüedad desde `iat` en 300 s, el mismo número que WooCommerce, Magento y
 * Odoo y que el contrato declara (`exp - iat <= 300`).
 *
 * Antes PrestaShop no tenía tope de antigüedad: un token con `iat` de hace una
 * hora y `exp` dentro de la ventana de gracia (lifetime ≤ 330 s) verificaba.
 *
 * TokenVerifier REAL con firma Ed25519 real; el reloj se relee alrededor de la
 * llamada de frontera para que un cambio de segundo no la convierta en 301.
 * Arnés: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

use Trusteed\Enforcement\TokenVerifier;

final class TokenVerifierMaxAgeTest extends ValidateOrderHookFlowTestCase
{
    private function verifier(): TokenVerifier
    {
        return new TokenVerifier($this->payload()['agentDidResolver'], $this->merchantId);
    }

    private function intentHash(): string
    {
        return hash('sha256', json_encode([
            'merchantId' => $this->merchantId,
            'lineItems'  => [],
            'currency'   => 1,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed>|null */
    private function verifyAged(int $age, int $expIn = 10): ?array
    {
        $t = time();
        return $this->verifier()->verify(
            $this->token(null, self::AGENT_DID, ['iat' => $t - $age, 'exp' => $t + $expIn]),
            $this->intentHash()
        );
    }

    public function testControlAFreshTokenVerifies(): void
    {
        self::assertIsArray($this->verifyAged(0, 120));
    }

    public function testAgeExactlyAtTheMaximumIsValid(): void
    {
        do {
            $t      = time();
            $claims = $this->verifier()->verify(
                $this->token(null, self::AGENT_DID, ['iat' => $t - 300, 'exp' => $t + 10]),
                $this->intentHash()
            );
            $same = (time() === $t);
        } while (!$same);

        self::assertIsArray($claims, 'edad = 300 s es la frontera: válida');
    }

    public function testOneSecondPastTheMaximumIsRejected(): void
    {
        self::assertNull($this->verifyAged(301), 'edad = 301 s (exp aún dentro de la gracia y vida ≤ 330) ⇒ rechazado');
    }

    public function testAnOldIatWithARenewedExpIsRejected(): void
    {
        // vida = 330 s (en el límite de la ventana de vida), iat de hace 320 s.
        self::assertNull($this->verifyAged(320, 10));
    }

    public function testAgesBetweenTheOldCapAndTheContractVerify(): void
    {
        foreach ([121, 200, 299] as $age) {
            self::assertIsArray($this->verifyAged($age), "edad {$age} s es legítima y debe verificar");
        }
    }
}
