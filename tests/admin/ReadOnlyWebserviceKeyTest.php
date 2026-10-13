<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\ReadOnlyWebserviceKey;

/**
 * What exactly the read key grants when the merchant agrees to create it.
 *
 * What the consent text promises ("read only, only these resources") must be
 * what is written to `webservice_permission`. If someone adds a resource or a
 * method here, these tests fail before the merchant has consented to
 * something different from what is read.
 */
final class ReadOnlyWebserviceKeyTest extends TestCase
{
    public function testLosPermisosSonSoloGetSobreLaListaMinima(): void
    {
        $permissions = ReadOnlyWebserviceKey::permissions();

        $this->assertSame(
            [
                'products',
                'stock_availables',
                'combinations',
                'product_options',
                'product_option_values',
                'manufacturers',
                'categories',
                'currencies',
                'configurations',
                'taxes',
                'tax_rules',
                'tax_rule_groups',
                'shops',
            ],
            array_keys($permissions)
        );

        foreach ($permissions as $resource => $methods) {
            // Shape expected by `WebserviceKey::setPermissionForAccount`: it reads
            // the KEYS of each resource as methods.
            $this->assertSame(['GET' => 1], $methods, $resource);
        }
    }

    public function testNoIncluyeDatosPersonalesNiPedidos(): void
    {
        $resources = array_keys(ReadOnlyWebserviceKey::permissions());

        foreach (['customers', 'addresses', 'orders', 'carts', 'employees', 'customer_threads', 'guests'] as $forbidden) {
            $this->assertNotContains($forbidden, $resources);
        }
    }

    public function testGeneraClavesDe32MayusculasYDigitos(): void
    {
        $key = ReadOnlyWebserviceKey::generate();

        // The backend and `validatePrestaShopKey` require /^[A-Z0-9]{32}$/;
        // `WebserviceRequest::authenticate` only requires length 32.
        $this->assertMatchesRegularExpression('/\A[A-Z0-9]{32}\z/', $key);
        $this->assertTrue(ReadOnlyWebserviceKey::isValidFormat($key));
    }

    public function testDosClavesSeguidasSonDistintas(): void
    {
        $this->assertNotSame(ReadOnlyWebserviceKey::generate(), ReadOnlyWebserviceKey::generate());
    }

    public function testFormatoInvalido(): void
    {
        $this->assertFalse(ReadOnlyWebserviceKey::isValidFormat('abcdefghijklmnopqrstuvwxyz012345'));
        $this->assertFalse(ReadOnlyWebserviceKey::isValidFormat(str_repeat('A', 31)));
        $this->assertFalse(ReadOnlyWebserviceKey::isValidFormat(str_repeat('A', 32) . "\n"));
    }

    public function testDescripcionIdentificable(): void
    {
        // This is what the merchant will see under Advanced Parameters > Webservice.
        $this->assertSame('Trusteed read-only', ReadOnlyWebserviceKey::DESCRIPTION);
    }
}
