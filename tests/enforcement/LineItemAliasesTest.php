<?php

declare(strict_types=1);

namespace Trusteed\Tests\Enforcement;

use PHPUnit\Framework\TestCase;
use Trusteed\Enforcement\LineItemAliases;

require_once __DIR__ . '/../../src/Enforcement/LineItemAliases.php';

/**
 * R034 (lista de SKU bloqueados) compara EXACTO `lineItems[].id` con la lista del
 * comerciante, que escribe SKU/referencias. PrestaShop mandaba sólo el id numérico
 * del producto, así que un SKU bloqueado nunca casaba (barrido 2026-10-10, 20613d0ee).
 * El servidor ya usa la convención «línea alias con qty 0 y precio 0»: no altera
 * importes ni conteos de las reglas numéricas.
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
     * Cableado: que la clase exista no prueba que alguien la llame (el patrón
     * «mecanismo construido y sin llamador» de esta auditoría).
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
