<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Qué tiendas recorre el re-sync perezoso del back office: ninguna si el
 * empleado no puede editar el panel; si puede, sólo las suyas.
 */
final class ResyncScope
{
    /**
     * @param list<int|string> $activeShopIds   tiendas activas de la instalación
     * @param list<int|string> $employeeShopIds tiendas asociadas al empleado
     * @return list<int>
     */
    public static function shops(bool $mayEdit, array $activeShopIds, array $employeeShopIds): array
    {
        if (!$mayEdit) {
            return [];
        }
        $mine = array_map('intval', $employeeShopIds);
        $out = [];
        foreach ($activeShopIds as $id) {
            $id = (int) $id;
            if ($id > 0 && in_array($id, $mine, true) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
