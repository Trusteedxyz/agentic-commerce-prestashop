<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Lo que `ReadKeyManager` necesita de PrestaShop. Interfaz para poder fijar la
 * orquestación en tests sin levantar el framework; la implementación real es
 * `PrestaShopReadKeyEnvironment`.
 */
interface ReadKeyEnvironment
{
    public function webserviceEnabled(): bool;

    public function enableWebservice(): void;

    /** Sólo para deshacer un encendido propio cuando el acto consentido falla. */
    public function disableWebservice(): void;

    /**
     * @param array<string, array<string, int>> $permissions
     *
     * @return int id de la cuenta creada; 0 si no se pudo crear
     */
    public function createKey(string $key, string $description, array $permissions): int;

    public function deleteKey(int $id): bool;

    public function keyExists(int $id): bool;

    /** Número total de claves de Webservice que quedan en la tienda. */
    public function countKeys(): int;

    public function storedKeyId(): int;

    /** 0 borra el id guardado. */
    public function storeKeyId(int $id): void;

    public function enabledByModule(): bool;

    public function setEnabledByModule(bool $value): void;
}
