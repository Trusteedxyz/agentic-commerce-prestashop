<?php

declare(strict_types=1);

namespace Trusteed\Tests\Meta;

use PHPUnit\Framework\TestCase;

/**
 * PrestaShop 8.1 añadió un 11.º parámetro `?string $order_reference = null` a
 * `PaymentModuleCore::validateOrder()`. Un override que declara sólo diez es una
 * firma incompatible y PHP 8 lo trata como error fatal al cargar CUALQUIER
 * módulo de pago: `/order` da 500 (medido en PS 8.1.7, 2026-11-09). Añadir un
 * parámetro opcional al final sigue siendo compatible con el padre de PS 8.0.
 */
final class OverrideValidateOrderFirmaPs81Test extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/override/classes/PaymentModule.php');
    }

    public function test_declara_order_reference_opcional_al_final(): void
    {
        $this->assertMatchesRegularExpression(
            '/\?\\\\Shop\s+\$shop\s*=\s*null,\s*\?string\s+\$order_reference\s*=\s*null\s*\)/',
            self::source(),
            'validateOrder() debe cerrar con ?\Shop $shop = null, ?string $order_reference = null'
        );
    }

    public function test_reenvia_order_reference_al_padre(): void
    {
        $this->assertMatchesRegularExpression(
            '/parent::validateOrder\([^;]*\$shop,\s*\$order_reference\s*\)/s',
            self::source(),
            'parent::validateOrder() debe recibir $order_reference'
        );
    }
}
