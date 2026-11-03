<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Líneas alias con el SKU (referencia) de cada producto del carrito.
 *
 * R034 compara EXACTO `lineItems[].id` con la lista de SKU bloqueados que
 * escribe el comerciante; con sólo el id numérico de PrestaShop un SKU
 * bloqueado nunca casaba. Misma convención que el servidor
 * (`protocol-line-items.ts`): línea extra con qty 0 y precio 0, que no altera
 * importes ni conteos de las reglas numéricas. Se mantienen FUERA de las líneas
 * reales: cualquier lógica local que recorra los productos por id no las ve.
 */
final class LineItemAliases
{
    /**
     * @param list<array<string,mixed>> $products  Productos de `Cart::getProducts()`.
     * @param list<array<string,mixed>> $realLines Líneas ya proyectadas (`id`, `qty`, `priceCents`).
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
