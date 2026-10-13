<?php

declare(strict_types=1);

/**
 * The PrestaShop TOKEN verifier caps the age since `iat` at 300 s, the same
 * number as WooCommerce, Magento and Odoo and the one the contract declares
 * (`exp - iat <= 300`).
 *
 * PrestaShop used to have no age cap: a token with an `iat` from an hour ago and
 * an `exp` inside the grace window (lifetime up to 330 s) verified.
 *
 * REAL TokenVerifier with a real Ed25519 signature; the clock is re-read around
 * the boundary call so a second rollover does not turn it into 301.
 * Harness: ValidateOrderHookFlowHarness.php.
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
        // lifetime = 330 s (at the edge of the lifetime window), iat from 320 s ago.
        self::assertNull($this->verifyAged(320, 10));
    }

    public function testAgesBetweenTheOldCapAndTheContractVerify(): void
    {
        foreach ([121, 200, 299] as $age) {
            self::assertIsArray($this->verifyAged($age), "edad {$age} s es legítima y debe verificar");
        }
    }
}
