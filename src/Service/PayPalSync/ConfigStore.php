<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Lectura/escritura de `Configuration` con alcance (grupo, tienda). Una
 * interfaz para poder probar la lógica sin el kernel de PrestaShop.
 */
interface ConfigStore
{
    /** Valor para el alcance dado; cadena vacía si no existe. */
    public function get(string $key, ?int $idShopGroup, ?int $idShop): string;

    /**
     * Valor guardado EXACTAMENTE en ese alcance (sin caer al grupo ni al
     * global); null si no existe. Con idShop se mira la tienda; sin él, el grupo.
     */
    public function getOwn(string $key, ?int $idShopGroup, ?int $idShop): ?string;

    public function set(string $key, string $value, ?int $idShopGroup, ?int $idShop): void;
}
