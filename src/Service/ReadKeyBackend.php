<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Channel to Trusteed for the read key. Implemented by `AutoRegister`: same
 * client, same SSRF guard and same credential (`install_key`) as registration
 * and deregistration.
 */
interface ReadKeyBackend
{
    /**
     * @throws ReadKeyBackendException if the backend does not accept the key
     */
    public function attachReadKey(string $storeUrl, string $installKey, string $apiKey): void;

    /**
     * Never throws.
     *
     * @return bool `true` only if the backend confirmed it no longer holds the key
     */
    public function revokeReadKey(string $storeUrl, string $installKey): bool;
}
