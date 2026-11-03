<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use Trusteed\Service\PayPalSync\ConfigStore;

/**
 * Configuración en memoria con alcance (grupo, tienda), como `Configuration`
 * de PrestaShop pero sin tocar los stubs globales del bootstrap.
 *
 * Una clave sin valor propio para el alcance pedido cae al valor global
 * (grupo 0, tienda 0), igual que `Configuration::get()` con el alcance dado.
 */
final class InMemoryConfig implements ConfigStore
{
    /** @var array<string,string> */
    public array $values = [];

    public static function key(string $name, ?int $g, ?int $s): string
    {
        return $name . '@' . (int) $g . ':' . (int) $s;
    }

    public function put(string $name, string $value, ?int $g = null, ?int $s = null): self
    {
        $this->values[self::key($name, $g, $s)] = $value;
        return $this;
    }

    public function get(string $key, ?int $idShopGroup, ?int $idShop): string
    {
        foreach ([[$idShopGroup, $idShop], [$idShopGroup, null], [null, null]] as [$g, $s]) {
            $k = self::key($key, $g, $s);
            if (array_key_exists($k, $this->values)) {
                return $this->values[$k];
            }
        }
        return '';
    }

    public function getOwn(string $key, ?int $idShopGroup, ?int $idShop): ?string
    {
        $k = self::key($key, $idShopGroup, $idShop);
        return array_key_exists($k, $this->values) ? $this->values[$k] : null;
    }

    public function set(string $key, string $value, ?int $idShopGroup, ?int $idShop): void
    {
        $this->values[self::key($key, $idShopGroup, $idShop)] = $value;
    }
}
