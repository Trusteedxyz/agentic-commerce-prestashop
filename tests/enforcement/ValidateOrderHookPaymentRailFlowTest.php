<?php

declare(strict_types=1);

/**
 * R022 (2026-10-26) — el módulo de pago que llega por el override de
 * PaymentModule::validateOrder() viaja a /v1/rules/evaluate como
 * `orderContext.paymentMethod`; el nombre del módulo (lo que el servidor
 * conoce) manda sobre el POST `payment`, que sólo se usa sin pista. Hook REAL (evaluateCart), sólo el borde de red es de prueba.
 * Arnés: ValidateOrderHookFlowHarness.php.
 */

namespace Trusteed\Tests\Enforcement;

require_once __DIR__ . '/ValidateOrderHookFlowHarness.php';

final class ValidateOrderHookPaymentRailFlowTest extends ValidateOrderHookFlowTestCase
{
    private function paymentMethodSent(): ?string
    {
        $bodies = $this->client->evaluateBodies();
        self::assertCount(1, $bodies, 'la capa 2 se llama una vez');

        return $bodies[0]['orderContext']['paymentMethod'] ?? null;
    }

    public function testTheModuleNameFromTheOverrideReachesTheEvaluator(): void
    {
        $this->hook->evaluateCart(new \Cart(), 'ps_wirepayment');

        self::assertSame('ps_wirepayment', $this->paymentMethodSent());
    }

    public function testTheModuleNameWinsOverThePostField(): void
    {
        \Tools::$mockValues['payment'] = 'Stripe';

        $this->hook->evaluateCart(new \Cart(), 'ps_wirepayment');

        self::assertSame('ps_wirepayment', $this->paymentMethodSent());
    }

    public function testThePostFieldIsTheFallbackWithoutHint(): void
    {
        \Tools::$mockValues['payment'] = 'Stripe';

        $this->hook->evaluateCart(new \Cart());

        self::assertSame('stripe', $this->paymentMethodSent());
    }

    public function testNoSignalWhenNeitherPostNorHint(): void
    {
        $this->hook->evaluateCart(new \Cart());

        self::assertNull($this->paymentMethodSent());
    }
}
