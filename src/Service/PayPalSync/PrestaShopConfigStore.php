<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * `Configuration` real de PrestaShop. Sin lógica: no tiene test unitario
 * porque el stub compartido del bootstrap sólo implementa `get()`.
 */
final class PrestaShopConfigStore implements ConfigStore
{
    public function get(string $key, ?int $idShopGroup, ?int $idShop): string
    {
        $v = \Configuration::get($key, null, $idShopGroup, $idShop);
        return is_scalar($v) ? (string) $v : '';
    }

    public function getOwn(string $key, ?int $idShopGroup, ?int $idShop): ?string
    {
        // hasKey() con idShop sólo mira el alcance de esa tienda (sin fallback).
        if (!\Configuration::hasKey($key, null, $idShopGroup, $idShop)) {
            return null;
        }
        $v = \Configuration::get($key, null, $idShopGroup, $idShop);

        return is_scalar($v) ? (string) $v : null;
    }

    public function set(string $key, string $value, ?int $idShopGroup, ?int $idShop): void
    {
        \Configuration::updateValue($key, $value, false, $idShopGroup, $idShop);
    }
}
