<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Canal hacia Trusteed para la clave de lectura. Lo implementa `AutoRegister`:
 * mismo cliente, misma guarda SSRF y misma credencial (`install_key`) que el
 * alta y la baja.
 */
interface ReadKeyBackend
{
    /**
     * @throws ReadKeyBackendException si el backend no acepta la clave
     */
    public function attachReadKey(string $storeUrl, string $installKey, string $apiKey): void;

    /**
     * Nunca lanza.
     *
     * @return bool `true` sólo si el backend confirmó que ya no la tiene
     */
    public function revokeReadKey(string $storeUrl, string $installKey): bool;
}
