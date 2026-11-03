<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Lee las credenciales REST del módulo `paypal` (202 ecommerce) para una
 * tienda, sin instanciar ese módulo.
 *
 * Claves (verificadas en el código del módulo, `classes/MethodEC.php`,
 * `paypal.php`): `PAYPAL_SANDBOX` elige el modo; el client id está en claro en
 * `PAYPAL_EC_CLIENTID_{SANDBOX|LIVE}` y el secreto cifrado con Defuse en
 * `PAYPAL_EC_SECRET_{SANDBOX|LIVE}`.
 *
 * SIN VERIFICAR: si con EC vacío hay que leer los métodos MB/PPP
 * (`PAYPAL_MB_*`, `PAYPAL_PPP_*`). No se leen: EC vacío es `absent`.
 */
final class CredentialReader
{
    /**
     * Cota local, no un formato: PayPal no publica uno y no se inventa. La
     * plataforma valida de verdad contra PayPal.
     */
    private const CREDENTIAL_PATTERN = '/\A[A-Za-z0-9_-]{20,200}\z/';

    public function __construct(
        private readonly ConfigStore $config,
        private readonly SecretDecryptor $decryptor
    ) {
    }

    public function read(?int $idShopGroup, ?int $idShop): PayPalCredentials
    {
        $sandbox = in_array(
            strtolower(trim($this->config->get('PAYPAL_SANDBOX', $idShopGroup, $idShop))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        $mode = $sandbox ? 'sandbox' : 'production';
        $suffix = $sandbox ? 'SANDBOX' : 'LIVE';

        $clientId = trim($this->config->get('PAYPAL_EC_CLIENTID_' . $suffix, $idShopGroup, $idShop));
        $cipher = trim($this->config->get('PAYPAL_EC_SECRET_' . $suffix, $idShopGroup, $idShop));

        if ($clientId === '' && $cipher === '') {
            // «Ausente» (= el comercio vació PayPal, desconectable) sólo si LOS
            // DOS modos están vacíos. Si el otro modo tiene algo, el comercio ha
            // cambiado PAYPAL_SANDBOX: no es utilizable pero tampoco es «vació».
            $other = $sandbox ? 'LIVE' : 'SANDBOX';
            $otherConfigured = trim($this->config->get('PAYPAL_EC_CLIENTID_' . $other, $idShopGroup, $idShop)) !== ''
                || trim($this->config->get('PAYPAL_EC_SECRET_' . $other, $idShopGroup, $idShop)) !== '';

            return $otherConfigured ? PayPalCredentials::invalid($mode) : PayPalCredentials::absent($mode);
        }
        if ($clientId === '' || $cipher === '' || preg_match(self::CREDENTIAL_PATTERN, $clientId) !== 1) {
            return PayPalCredentials::invalid($mode);
        }

        $secret = $this->decryptor->decrypt($cipher);
        if ($secret === null || preg_match(self::CREDENTIAL_PATTERN, $secret) !== 1) {
            return PayPalCredentials::invalid($mode);
        }

        return PayPalCredentials::ok($mode, $clientId, $secret);
    }
}
