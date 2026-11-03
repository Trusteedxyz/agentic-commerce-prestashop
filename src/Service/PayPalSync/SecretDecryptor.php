<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/** Descifra el secreto que guarda el módulo `paypal` de 202 ecommerce. */
interface SecretDecryptor
{
    /** @return string|null El secreto en claro, o null si no se puede descifrar. */
    public function decrypt(string $cipher): ?string;
}
