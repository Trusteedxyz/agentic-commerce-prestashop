<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Alias lines carrying the SKU (reference) of each cart product.
 *
 * R034 compares `lineItems[].id` EXACTLY against the merchant's blocked SKU
 * list; with only the numeric PrestaShop id a blocked SKU never matched. Same
 * convention as the server (`protocol-line-items.ts`): an extra line with qty 0
 * and price 0, which does not alter amounts or counts for numeric rules. They
 * are kept OUTSIDE the real lines: any local logic that walks products by id
 * does not see them.
 */
final class LineItemAliases
{
    /**
     * @param list<array<string,mixed>> $products  Products from `Cart::getProducts()`.
     * @param list<array<string,mixed>> $realLines Already projected lines (`id`, `qty`, `priceCents`).
     * @return list<array{id: string, qty: int, priceCents: int}>
     */
    public static function forProducts(array $products, array $realLines): array
    {
        $seen = [];
        foreach ($realLines as $line) {
            $seen[(string) ($line['id'] ?? '')] = true;
        }

        $aliases = [];
        foreach ($products as $product) {
            $reference = trim((string) ($product['reference'] ?? ''));
            if ($reference === '' || isset($seen[$reference])) {
                continue;
            }
            $seen[$reference] = true;
            $aliases[] = ['id' => $reference, 'qty' => 0, 'priceCents' => 0];
        }

        return $aliases;
    }
}
