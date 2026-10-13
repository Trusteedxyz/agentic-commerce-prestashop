<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * What `ReadKeyManager` needs from PrestaShop. An interface so the
 * orchestration can be tested without booting the framework; the real
 * implementation is `PrestaShopReadKeyEnvironment`.
 */
interface ReadKeyEnvironment
{
    public function webserviceEnabled(): bool;

    public function enableWebservice(): void;

    /** Only to undo our own enabling when the consented action fails. */
    public function disableWebservice(): void;

    /**
     * @param array<string, array<string, int>> $permissions
     *
     * @return int id of the created account; 0 if it could not be created
     */
    public function createKey(string $key, string $description, array $permissions): int;

    public function deleteKey(int $id): bool;

    public function keyExists(int $id): bool;

    /** Total number of Webservice keys left in the shop. */
    public function countKeys(): int;

    public function storedKeyId(): int;

    /** 0 clears the stored id. */
    public function storeKeyId(int $id): void;

    public function enabledByModule(): bool;

    public function setEnabledByModule(bool $value): void;
}
