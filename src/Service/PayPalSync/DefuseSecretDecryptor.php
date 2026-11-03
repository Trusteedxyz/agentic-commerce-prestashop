<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Reproduce `PaypalAddons\services\ToolKit::decrypt()` (módulo `paypal`,
 * `services/ToolKit.php`): Defuse `Crypto::decrypt($cipher,
 * Key::loadFromAsciiSafeString(_NEW_COOKIE_KEY_))`, devolviendo null ante
 * cualquier fallo. No instancia el módulo PayPal.
 *
 * El módulo PayPal usa su copia con prefijo (`PaypalAddons\Defuse\...`); el
 * formato del texto cifrado es el de la librería, así que vale la del núcleo
 * de PrestaShop (`Defuse\Crypto`, requerida por PS 8.2.x en su composer.json).
 * SIN VERIFICAR: que esa clase esté disponible en PS 9.
 */
final class DefuseSecretDecryptor implements SecretDecryptor
{
    private const CRYPTO = '\\Defuse\\Crypto\\Crypto';
    private const KEY = '\\Defuse\\Crypto\\Key';

    public function __construct(private readonly string $cookieKey)
    {
    }

    public static function fromPrestaShop(): self
    {
        return new self(defined('_NEW_COOKIE_KEY_') ? (string) constant('_NEW_COOKIE_KEY_') : '');
    }

    public function decrypt(string $cipher): ?string
    {
        if ($cipher === '' || $this->cookieKey === '' || !class_exists(self::CRYPTO) || !class_exists(self::KEY)) {
            return null;
        }
        try {
            $key = call_user_func([self::KEY, 'loadFromAsciiSafeString'], $this->cookieKey);
            $plain = call_user_func([self::CRYPTO, 'decrypt'], $cipher, $key);
        } catch (\Throwable) {
            // Sin mensaje: una excepción de la librería puede arrastrar material sensible.
            return null;
        }
        return is_string($plain) ? $plain : null;
    }
}
