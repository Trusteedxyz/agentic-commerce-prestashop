<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Definition of the READ-only Webservice key the merchant can authorize from
 * the module screen.
 *
 * It is the single source of "what is granted": the consent text, the key
 * creation and the tests all use it. That way the merchant cannot accept one
 * list and receive another.
 */
final class ReadOnlyWebserviceKey
{
    /** What the merchant will see under Advanced Parameters > Webservice. */
    public const DESCRIPTION = 'Trusteed read-only';

    /**
     * The minimum the PrestaShop connector reads to compare the catalog agents
     * see (price, stock, variants, brand, category, currency, taxes). No
     * customers, addresses, orders or carts. All thirteen exist in
     * `WebserviceRequest::getResources()` in 1.7.8 and 8.1.
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
     * Shape of `WebserviceKey::setPermissionForAccount()`: resource => map whose
     * KEYS are the methods (the core does `array_keys($resource_methods)`).
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
     * 32 characters [A-Z0-9] from `random_int` (CSPRNG). `Tools::passwdGen` is
     * not used: its implementation differs between 1.7 and 8, and the backend
     * requires uppercase, which `ALPHANUMERIC` does not guarantee.
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
