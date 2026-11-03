<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Rule-code normalisation for the CEL enforcement path.
 *
 * `merchant_trust_rules.ruleCode` carries TWO live formats and nothing on the
 * wire converts one into the other: the bare short code (`R001`, written by
 * the demo seeders and the pre-canonical population) and the canonical dotted
 * form (`R001.verified-agent-required`, written by the merchant panel and the
 * embed routes). The signed snapshot copies the column verbatim.
 *
 * Comparing the raw value against a short literal is therefore correct for one
 * half of the merchants and SILENTLY inert for the other — no exception, no
 * log, just a rule that never fires. That is what happened here: `$hasTier2`
 * came out false on a canonical merchant, so layer 2 was never called, and
 * with it the 2026-07-11 App Store remediation (layer 2 without a verified
 * agent) was written but UNREACHABLE.
 *
 * Deliberately free of the PrestaShop kernel, so it is unit-testable on its
 * own — see `tests/enforcement/RuleCodeTest.php`.
 */
final class RuleCode
{
    /**
     * Códigos que este port evalúa LOCALMENTE con capacidad real de bloquear.
     *
     * Vacío (auditoría 2026-09-27, A9). `ValidateOrderHook::evaluateRule()`
     * sólo conoce R001 y R007, y su bucle sólo corre con claims VERIFICADOS:
     * R001 sale siempre ALLOW (hay agentId) y R007 es ALLOW por la decisión
     * 2026-07-30. Nada local puede bloquear, así que toda regla habilitada va
     * al servidor, incluidas las ≥R031 que la antigua lista escrita a mano
     * («tier-2», R002–R030) dejaba sin evaluar: un comerciante con R035 como
     * única regla no llegaba nunca a la capa 2.
     *
     * Paridad con `PLATFORM_LOCAL_RULE_SHORT_CODES.prestashop` de
     * `packages/shared/src/enforcement/platform-flow.ts`, fijada por los
     * vectores `serverRouting` de `platform-flow-conformance.json`
     * (`tests/enforcement/PlatformFlowConformanceTest.php`).
     */
    public const LOCAL_ONLY_SHORT_CODES = [];

    /**
     * Strip a canonical dotted suffix: `R030.simple-controls` → `R030`.
     *
     * Total and idempotent: an unknown or already-short code comes back
     * unchanged, so it can be applied at any point of the chain without
     * knowing which format arrived.
     */
    public static function shortCode(string $ruleCode): string
    {
        $dot = strpos($ruleCode, '.');

        return $dot === false ? $ruleCode : substr($ruleCode, 0, $dot);
    }

    /** ¿Necesita esta regla al servidor para un veredicto? (cualquier formato de código) */
    public static function isServerRule(string $ruleCode): bool
    {
        return !in_array(self::shortCode($ruleCode), self::LOCAL_ONLY_SHORT_CODES, true);
    }

    /**
     * ¿Hay alguna regla HABILITADA que sólo el servidor puede decidir?
     *
     * `enabled` se lee con `empty()`: `true`, `1` y `"1"` habilitan; `false`,
     * `0`, `"0"` y ausente, no. No se endurece a `=== true`: una regla guardada
     * como `1` dejaría de llegar al servidor sin ningún error.
     *
     * @param array $rules Snapshot `rules` (cualquier formato de código).
     */
    public static function needsServer(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (is_array($rule) && !empty($rule['enabled']) && self::isServerRule((string) ($rule['ruleCode'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Igual que {@see needsServer}, descartando las reglas en `observe`
     * (`mode` ausente cuenta como `enforce`).
     *
     * @param array $rules Snapshot `rules` (cualquier formato de código).
     */
    public static function hasEnforcingServerRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['enabled']) || !self::isServerRule((string) ($rule['ruleCode'] ?? ''))) {
                continue;
            }
            if (strtolower((string) ($rule['mode'] ?? 'enforce')) !== 'observe') {
                return true;
            }
        }

        return false;
    }
}
