<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * P2-3 §1 (2026-09-12) — qué hacer cuando NO se puede consultar la política.
 *
 * Las cuatro plataformas tenían cuatro respuestas distintas ante la misma
 * pregunta, y dos de ellas ni siquiera consultaban el FallbackMode firmado:
 *
 *   - Shopify        → depende del snapshot embebido. Correcto por construcción.
 *   - Magento        → `failure_mode` propio, por defecto `enforce` ⇒ BLOCK.
 *   - WooCommerce B  → `failure_mode` propio, por defecto `enforce` ⇒ BLOCK.
 *   - PrestaShop     → `return` MUDO. Ni consultaba el modo ni dejaba rastro.
 *   - WooCommerce A  → bloqueaba SÓLO con `'strict'`, y el `?? 'balanced'`
 *                      convertía la AUSENCIA de política firmada en un permiso.
 *
 * Esta clase fija el criterio de los dos correctos para PrestaShop: una avería
 * nuestra no es un permiso. Ver
 * `wiki/gotchas/una-averia-propia-no-puede-ser-un-permiso-2026-09-09.md`.
 *
 * La regla, en una frase: **el modo firmado manda cuando existe; su ausencia
 * es fail-closed, no `balanced`.**
 *
 * Detrás de bandera (`TRUSTEED_CEL_FAIL_CLOSED_ON_UNAVAILABLE`, apagada por
 * defecto) porque encenderla cambia el resultado de checkouts reales: con la
 * API caída y sin caché, hoy pasan todos y mañana no pasa ninguno. Con la
 * bandera apagada el veredicto es `ALLOW` SIEMPRE — byte a byte lo de hoy.
 */
final class SnapshotUnavailablePolicy
{
    public const DECISION_ALLOW = 'ALLOW';
    public const DECISION_BLOCK = 'BLOCK';

    /** Los tres modos que el contrato de CEL reconoce. */
    private const VALID_MODES = ['strict', 'balanced', 'permissive'];

    /**
     * @param string|null $signedFallbackMode `fallbackMode` tal y como venía en
     *   el snapshot FIRMADO, o `null` cuando no hubo snapshot que leer (que es
     *   justo el caso que motiva esta clase: `pull()` devolvió null).
     * @param string|null $localFallbackMode  El modo configurado por el
     *   comerciante en su back office. Se usa SÓLO cuando no hay modo firmado.
     * @param bool $failClosedEnabled Bandera. `false` ⇒ comportamiento actual.
     */
    public static function decide(
        ?string $signedFallbackMode,
        ?string $localFallbackMode,
        bool $failClosedEnabled
    ): string {
        if (!$failClosedEnabled) {
            return self::DECISION_ALLOW;
        }

        // 1. El modo firmado manda. Es la política que el comerciante publicó y
        //    que nosotros sellamos: si dice `permissive`, decir BLOCK sería
        //    ignorar una decisión suya explícita y verificable.
        if (self::isValidMode($signedFallbackMode)) {
            return $signedFallbackMode === 'strict'
                ? self::DECISION_BLOCK
                : self::DECISION_ALLOW;
        }

        // 2. Sin modo firmado, el local. Un valor corrupto o ausente NO es
        //    `balanced`: no saber qué quiere el comerciante no es lo mismo que
        //    saber que quiere pasar. `MerchantResolver::getFallbackMode()` ya
        //    aplica esta misma regla devolviendo `strict` ante un valor
        //    inválido; aquí se repite porque este método también recibe
        //    valores que no pasan por ese resolutor.
        if (self::isValidMode($localFallbackMode)) {
            return $localFallbackMode === 'strict'
                ? self::DECISION_BLOCK
                : self::DECISION_ALLOW;
        }

        return self::DECISION_BLOCK;
    }

    private static function isValidMode(?string $mode): bool
    {
        return $mode !== null && in_array($mode, self::VALID_MODES, true);
    }
}
