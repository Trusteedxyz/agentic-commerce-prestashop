<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\LineItemAliases;

require_once __DIR__ . '/../../src/Enforcement/LineItemAliases.php';

/**
 * R034 (blocked SKU list) compares `lineItems[].id` EXACTLY against the
 * merchant's list, which holds SKUs/references. PrestaShop only sent the numeric
 * product id, so a blocked SKU never matched. The server already uses the
 * convention "alias line with qty 0 and price 0": it does not alter amounts or
 * counts for the numeric rules.
 */
final class LineItemAliasesTest extends TestCase
{
    public function testTheProductReferenceTravelsAsAZeroQuantityAliasLine(): void
    {
        $lines = [['id' => '12', 'qty' => 2, 'priceCents' => 1999]];
        $products = [['id_product' => 12, 'reference' => 'SKU-RED-42']];

        self::assertSame(
            [['id' => 'SKU-RED-42', 'qty' => 0, 'priceCents' => 0]],
            LineItemAliases::forProducts($products, $lines)
        );
    }

    public function testNoAliasWhenThereIsNoReference(): void
    {
        $lines = [['id' => '12', 'qty' => 1, 'priceCents' => 100]];

        self::assertSame([], LineItemAliases::forProducts([['id_product' => 12, 'reference' => '']], $lines));
        self::assertSame([], LineItemAliases::forProducts([['id_product' => 12]], $lines));
        self::assertSame([], LineItemAliases::forProducts([['id_product' => 12, 'reference' => '   ']], $lines));
    }

    public function testTheReferenceIsTrimmedAndNotDuplicated(): void
    {
        $lines = [
            ['id' => '12', 'qty' => 1, 'priceCents' => 100],
            ['id' => '13', 'qty' => 1, 'priceCents' => 100],
        ];
        $products = [
            ['id_product' => 12, 'reference' => ' ABC '],
            ['id_product' => 13, 'reference' => 'ABC'],
        ];

        self::assertSame([['id' => 'ABC', 'qty' => 0, 'priceCents' => 0]], LineItemAliases::forProducts($products, $lines));
    }

    public function testAReferenceEqualToAnExistingIdAddsNothing(): void
    {
        $lines = [['id' => '12', 'qty' => 1, 'priceCents' => 100]];

        self::assertSame([], LineItemAliases::forProducts([['id_product' => 12, 'reference' => '12']], $lines));
    }

    public function testAliasLinesNeverAddQuantityOrValue(): void
    {
        $alias = LineItemAliases::forProducts(
            [['id_product' => 1, 'reference' => 'A', 'cart_quantity' => 9, 'price_wt' => 50]],
            [['id' => '1', 'qty' => 9, 'priceCents' => 5000]]
        );

        foreach ($alias as $line) {
            self::assertSame(0, $line['qty']);
            self::assertSame(0, $line['priceCents']);
        }
    }

    /**
     * Wiring: the class existing does not prove anyone calls it (the
     * "mechanism built but never called" pattern).
     */
    public function testValidateOrderHookSendsTheAliasLinesAfterTheRealOnes(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Enforcement/ValidateOrderHook.php');

        self::assertStringContainsString('LineItemAliases::forProducts(', $src);
        self::assertMatchesRegularExpression(
            "/\\\$ctx\\['lineItems'\\]\\s*=\\s*array_merge\\(\\\$lineItems,\\s*\\\$aliasLines\\)/",
            $src,
            'las alias van detrás de las reales; el bucle R013 sigue recorriendo sólo las reales'
        );
    }
}
