<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Cableado con PrestaShop real de la sincronización de PayPal. Sin lógica de
 * negocio (esa vive en PayPalSyncService); no tiene test unitario porque
 * depende de `Context`, `Shop` y `Configuration` reales.
 */
final class PayPalSyncFactory
{
    /**
     * Servicio para el empleado actual, o null si falta algo (sin sesión de
     * back office o sin API base).
     */
    public static function build(): ?PayPalSyncService
    {
        $employeeId = (int) (\Context::getContext()->employee->id ?? 0);
        if ($employeeId <= 0) {
            return null;
        }
        $apiBase = (string) \Configuration::get('TRUSTEED_API_BASE', null, null, null, 'https://api.trusteed.xyz');

        // Misma procedencia del secreto S2S que AdminTrusteedController::ajaxProcessToken().
        $secret = (string) \Configuration::get('TRUSTEED_EMBED_S2S_SECRET');
        if ($secret === '') {
            $secret = (string) \Configuration::get('TRUSTEED_BOOTSTRAP_TOKEN');
        }

        $store = new PrestaShopConfigStore();
        $issuer = new EmbedTokenIssuer($apiBase, $secret, $employeeId, [CurlHttpPost::class, 'post']);

        return new PayPalSyncService(
            $store,
            new CredentialReader($store, DefuseSecretDecryptor::fromPrestaShop()),
            static fn (int $idShop, string $merchantId): ?string => $issuer->issue($idShop, $merchantId),
            $apiBase,
            [CurlHttpPost::class, 'post'],
            null,
            [self::class, 'activeShopMap']
        );
    }

    /**
     * Tiendas activas de la instalación: [idShop => idGroup|null].
     *
     * @return array<int,?int>
     */
    public static function activeShopMap(): array
    {
        $map = [];
        $shops = \Shop::getShops(true, null, true);
        foreach (is_array($shops) ? $shops : [] as $idShop) {
            $idShop = (int) $idShop;
            if ($idShop > 0) {
                $map[$idShop] = self::shopGroupOf($idShop);
            }
        }

        return $map;
    }

    /**
     * ¿Puede el empleado actual editar el panel de Trusteed? Mismo rol de pestaña
     * que protege las acciones manuales; superadmin siempre. Falla cerrado.
     *
     * SIN VERIFICAR: el slug ROLE_MOD_TAB_ADMINTRUSTEED_EDIT en PS 8/9 reales
     * (es el formato estándar de roles de pestaña, no se ha ejecutado aquí).
     */
    private static function employeeMayEdit(): bool
    {
        try {
            $employee = \Context::getContext()->employee ?? null;
            if (!is_object($employee) || (int) ($employee->id ?? 0) <= 0) {
                return false;
            }
            $profile = (int) ($employee->id_profile ?? 0);
            if ($profile > 0 && defined('_PS_ADMIN_PROFILE_') && $profile === (int) \_PS_ADMIN_PROFILE_) {
                return true;
            }

            return $profile > 0
                && class_exists('\\Access')
                && (bool) \Access::isGranted('ROLE_MOD_TAB_ADMINTRUSTEED_EDIT', $profile);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<int> */
    private static function employeeShopIds(): array
    {
        $employee = \Context::getContext()->employee ?? null;
        if (!is_object($employee) || !method_exists($employee, 'getAssociatedShops')) {
            return [];
        }
        $ids = $employee->getAssociatedShops();

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public static function shopGroupOf(int $idShop): ?int
    {
        $g = (int) \Shop::getGroupFromShop($idShop);

        return $g > 0 ? $g : null;
    }

    /**
     * Gancho perezoso de `displayBackOfficeTop`: por cada tienda con opt-in,
     * reenvía sólo si la huella cambió. Nunca lanza ni escribe nada a la salida.
     *
     * SIN VERIFICAR: que `displayBackOfficeTop` se ejecute en las páginas
     * Symfony de PrestaShop 9 (sólo leído en 8.2.x).
     */
    public static function resyncOnBackOfficePage(): void
    {
        try {
            // L3: la página la pide cualquier empleado. Sólo actúa si puede
            // editar el panel de Trusteed y únicamente sobre SUS tiendas.
            $mayEdit = self::employeeMayEdit();
            if (!$mayEdit) {
                return;
            }
            $service = self::build();
            if ($service === null) {
                return;
            }
            $active = array_keys(self::activeShopMap());
            foreach (ResyncScope::shops($mayEdit, $active, self::employeeShopIds()) as $idShop) {
                $service->resyncIfChanged(self::shopGroupOf($idShop), $idShop);
            }
        } catch (\Throwable) {
            // Un fallo del sync no puede romper el back office; se reintenta
            // en otra página. Sin mensaje: podría arrastrar material sensible.
        }
    }
}
