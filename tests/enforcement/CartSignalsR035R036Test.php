<?php

declare(strict_types=1);

/**
 * Spec-048 Sprint E.3 — PrestaShop CartSignals R035/R036 instance evaluators.
 *
 * Mirrors `evaluateR035` (max-order-value) and `evaluateR036`
 * (max-line-item-value) from `packages/shared/src/enforcement/rule-catalog.ts`.
 *
 * Tests use a minimal global Cart stub (declared below) emulating the
 * PrestaShop `Cart::getProducts()` / `Cart::getOrderTotal(true, Cart::BOTH)`
 * contract so the pure CEL projection can be exercised without a live
 * PrestaShop kernel.
 */

namespace {
    // Minimal PrestaShop Cart class stub. Loaded only if real PS kernel absent.
    if (!class_exists('Cart', false)) {
        class Cart
        {
            public const BOTH = 3;

            /** @var float */
            public $orderTotal = 0.0;

            /** @var array<int,array<string,mixed>> */
            public $products = [];

            public function getOrderTotal(bool $withTaxes = true, int $type = self::BOTH): float
            {
                return $this->orderTotal;
            }

            /** @return array<int,array<string,mixed>> */
            public function getProducts(): array
            {
                return $this->products;
            }
        }
    }
}

namespace Trusteed\Tests\Enforcement {

require_once __DIR__ . '/../../src/Service/CartSignals.php';

use Trusteed\Service\CartSignals;
use PHPUnit\Framework\TestCase;

final class CartSignalsR035R036Test extends TestCase
{
    private function makeCart(float $total, array $products = []): \Cart
    {
        $cart = new \Cart();
        $cart->orderTotal = $total;
        $cart->products = $products;
        return $cart;
    }

    // ---- R035 -------------------------------------------------------------

    public function test_r035_hit_when_total_exceeds_cap(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(123.45); // 12345 cents
        $res = $signals->evaluateR035($cart, ['maxCents' => 10000]);
        $this->assertTrue($res['hit']);
        $this->assertSame('cart total 12345 exceeds cap 10000', $res['reason']);
    }

    public function test_r035_pass_when_total_within_cap(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(100.00); // 10000 cents — boundary inclusive (strict >)
        $res = $signals->evaluateR035($cart, ['maxCents' => 10000]);
        $this->assertFalse($res['hit']);
    }

    public function test_r035_pass_when_param_missing(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(9999.99);
        $res = $signals->evaluateR035($cart, []);
        $this->assertFalse($res['hit']);
    }

    // ---- R036 -------------------------------------------------------------

    public function test_r036_hit_first_line_over_cap(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(0.0, [
            ['id_product' => 7, 'total_wt' => 49.99],   // 4999c — under
            ['id_product' => 9, 'total_wt' => 150.00],  // 15000c — HIT
            ['id_product' => 11, 'total_wt' => 999.00], // would also hit
        ]);
        $res = $signals->evaluateR036($cart, ['maxCents' => 10000]);
        $this->assertTrue($res['hit']);
        $this->assertSame('line 9 value 15000 exceeds cap 10000', $res['reason']);
    }

    public function test_r036_pass_when_all_lines_within_cap(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(0.0, [
            ['id_product' => 1, 'total_wt' => 10.00],
            ['id_product' => 2, 'total_wt' => 100.00], // exactly cap — strict >
        ]);
        $res = $signals->evaluateR036($cart, ['maxCents' => 10000]);
        $this->assertFalse($res['hit']);
    }

    public function test_r036_pass_when_param_missing(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(0.0, [
            ['id_product' => 1, 'total_wt' => 9999.99],
        ]);
        $res = $signals->evaluateR036($cart, []);
        $this->assertFalse($res['hit']);
    }

    public function test_r036_pass_when_cart_empty(): void
    {
        $signals = new CartSignals();
        $cart = $this->makeCart(0.0, []);
        $res = $signals->evaluateR036($cart, ['maxCents' => 100]);
        $this->assertFalse($res['hit']);
    }
}

} // end namespace Trusteed\Tests\Enforcement
