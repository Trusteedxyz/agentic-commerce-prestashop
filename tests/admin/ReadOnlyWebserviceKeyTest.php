<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\ReadOnlyWebserviceKey;

/**
 * Qué concede exactamente la clave de lectura que el comerciante acepta crear.
 *
 * Lo que el texto de consentimiento promete («sólo lectura, sólo estos
 * recursos») tiene que ser lo que se escribe en `webservice_permission`. Si
 * alguien añade un recurso o un método aquí, estos tests se ponen rojos antes
 * de que el comerciante haya consentido algo distinto de lo que lee.
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
            // Forma que espera `WebserviceKey::setPermissionForAccount`: lee
            // las CLAVES de cada recurso como métodos.
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

        // El backend y `validatePrestaShopKey` exigen /^[A-Z0-9]{32}$/;
        // `WebserviceRequest::authenticate` sólo exige longitud 32.
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
        // Es lo que el comerciante verá en Parámetros avanzados > Webservice.
        $this->assertSame('Trusteed read-only', ReadOnlyWebserviceKey::DESCRIPTION);
    }
}
