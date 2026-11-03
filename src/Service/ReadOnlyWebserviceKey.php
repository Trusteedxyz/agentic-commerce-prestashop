<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Definición de la clave de LECTURA del Webservice que el comerciante puede
 * autorizar desde la pantalla del módulo.
 *
 * Es la única fuente de «qué se concede»: la usan el texto de consentimiento,
 * la creación de la clave y los tests. Así el comerciante no puede aceptar una
 * lista y recibir otra.
 */
final class ReadOnlyWebserviceKey
{
    /** Lo que el comerciante verá en Parámetros avanzados > Webservice. */
    public const DESCRIPTION = 'Trusteed read-only';

    /**
     * Lo mínimo que lee el conector de PrestaShop para contrastar el catálogo
     * que ven los agentes (precio, stock, variantes, marca, categoría, moneda,
     * impuestos). Ni clientes, ni direcciones, ni pedidos, ni carritos.
     * Los trece existen en `WebserviceRequest::getResources()` de 1.7.8 y 8.1.
     */
    public const RESOURCES = [
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
    ];

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const LENGTH = 32;

    /**
     * Forma de `WebserviceKey::setPermissionForAccount()`: recurso → mapa cuyas
     * CLAVES son los métodos (el core hace `array_keys($resource_methods)`).
     *
     * @return array<string, array<string, int>>
     */
    public static function permissions(): array
    {
        $permissions = [];
        foreach (self::RESOURCES as $resource) {
            $permissions[$resource] = ['GET' => 1];
        }

        return $permissions;
    }

    /**
     * 32 caracteres [A-Z0-9] con `random_int` (CSPRNG). No se usa
     * `Tools::passwdGen`: su implementación cambia entre 1.7 y 8 y el backend
     * exige mayúsculas, que `ALPHANUMERIC` no garantiza.
     */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $key = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $key .= self::ALPHABET[random_int(0, $max)];
        }

        return $key;
    }

    public static function isValidFormat(string $key): bool
    {
        return preg_match('/\A[A-Z0-9]{32}\z/', $key) === 1;
    }
}
