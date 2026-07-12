<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use Trusteed\Service\CartSignals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Service/CartSignals.php';

/**
 * Spec-048 Sprint E (T-E39) — PrestaShop CartSignals helpers.
 *
 * Pure-function tests; mirror Magento CartSignalsTest + WC class-cart-signals-test.
 */
final class CartSignalsSprintETest extends TestCase
{
    public function test_r032_blocked_category_hit(): void
    {
        $items = [
            ['categoryIds' => [10, 20]],
            ['categoryIds' => [30]],
        ];
        $this->assertTrue(CartSignals::r032HasBlockedCategory($items, ['20']));
        $this->assertTrue(CartSignals::r032HasBlockedCategory($items, [20]));
    }

    public function test_r032_blocked_category_miss(): void
    {
        $items = [['categoryIds' => [10]]];
        $this->assertFalse(CartSignals::r032HasBlockedCategory($items, [99]));
        $this->assertFalse(CartSignals::r032HasBlockedCategory($items, []));
        $this->assertFalse(CartSignals::r032HasBlockedCategory([], [10]));
    }

    public function test_r034_blocked_sku(): void
    {
        $items = [['sku' => 'REF-A'], ['sku' => 'REF-B']];
        $this->assertTrue(CartSignals::r034HasBlockedSku($items, ['REF-B']));
        $this->assertFalse(CartSignals::r034HasBlockedSku($items, ['REF-Z']));
        $this->assertFalse(CartSignals::r034HasBlockedSku($items, []));
    }

    public function test_r038_item_count(): void
    {
        $this->assertSame(0, CartSignals::r038ItemCount([]));
        $this->assertSame(5, CartSignals::r038ItemCount([
            ['quantity' => 2],
            ['quantity' => 3],
        ]));
        $this->assertSame(2, CartSignals::r038ItemCount([
            ['quantity' => 2],
            ['quantity' => -10],
        ]));
    }

    public function test_r039_max_line_quantity(): void
    {
        $this->assertSame(0, CartSignals::r039MaxLineQuantity([]));
        $this->assertSame(7, CartSignals::r039MaxLineQuantity([
            ['quantity' => 3],
            ['quantity' => 7],
            ['quantity' => 1],
        ]));
    }

    public function test_r048_downloadable_via_is_virtual_string_one(): void
    {
        $items = [['reference' => 'PHYS-X', 'name' => 'T-shirt', 'is_virtual' => '1']];
        $this->assertSame('downloadable', CartSignals::r048DigitalGoodTypes($items));
    }

    public function test_r048_downloadable_via_is_virtual_bool(): void
    {
        $items = [['reference' => '', 'name' => '', 'is_virtual' => true]];
        $this->assertSame('downloadable', CartSignals::r048DigitalGoodTypes($items));
    }

    public function test_r048_giftcard_via_reference(): void
    {
        $items = [['reference' => 'pm_giftcard_50', 'name' => '', 'is_virtual' => '1']];
        $this->assertSame('gift_card', CartSignals::r048DigitalGoodTypes($items));
    }

    public function test_r048_giftcard_via_name(): void
    {
        $items = [['reference' => 'REF-1', 'name' => 'Tarjeta-Regalo 50€', 'is_virtual' => '0']];
        $this->assertSame('gift_card', CartSignals::r048DigitalGoodTypes($items));
    }

    public function test_r048_empty_when_physical(): void
    {
        $items = [['reference' => 'PHYS-X', 'name' => 'T-shirt', 'is_virtual' => false]];
        $this->assertSame('', CartSignals::r048DigitalGoodTypes($items));
    }

    public function test_r048_mixed_giftcard_and_downloadable(): void
    {
        $items = [
            ['reference' => 'EBOOK-1', 'name' => 'Manual', 'is_virtual' => '1'],
            ['reference' => 'GIFTCARD-100', 'name' => '', 'is_virtual' => '1'],
        ];
        $out = CartSignals::r048DigitalGoodTypes($items);
        $this->assertStringContainsString('downloadable', $out);
        $this->assertStringContainsString('gift_card', $out);
    }
}
