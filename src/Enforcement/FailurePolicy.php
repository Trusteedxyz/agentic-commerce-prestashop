<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Política del flujo principal — port PHP de
 * packages/shared/src/enforcement/platform-flow.ts, fijado por
 * packages/shared/src/enforcement/__fixtures__/platform-flow-conformance.json
 * (tests/enforcement/PlatformFlowConformanceTest.php).
 *
 * Invariante (auditoría spec-048 2026-09-27, A8/A9; decisión D1 del plan 04):
 * un comprador humano —checkout sin ninguna señal agéntica— nunca se bloquea
 * por una avería nuestra (red, 5xx, 4xx incluido 429, timeout, respuesta
 * ilegible, snapshot ausente o que no verifica, excepción interna), tampoco en
 * `strict`. Sólo puede bloquearlo una regla `appliesTo: ALL`/`HUMAN` del propio
 * comerciante que la válvula offline siga evaluando (el tope de R030, países,
 * horario…). `TRUSTEED_CEL_FALLBACK_MODE` sólo gobierna a los agénticos:
 * `strict` pasa a significar «estricto con los agentes».
 *
 * La cuarta decisión de la referencia (`needsServerEvaluation`) vive en
 * {@see RuleCode::needsServer()}, junto a la normalización del código; la
 * sexta (`resolveAgentDidPublicKey`) en
 * {@see TokenVerifier::resolveAgentDidPublicKey()}.
 *
 * El modo que recibe {@see decideUnavailable()} lo resuelve
 * {@see MerchantResolver::getFallbackMode()}: devuelve un valor de
 * {@see FAILURE_MODES} comparado exactamente, y cualquier otro —ausente,
 * vacío, `observe`/`enforce` de otra plataforma, `STRICT`, `BALANCED`— lo
 * convierte en `strict` (guarda de corrupción, fijada por
 * FallbackModeDefaultTest). Un modo no resuelto, por tanto, CIERRA a un agente
 * y no toca a un humano.
 *
 * Funciones puras: sin PrestaShop, sin I/O. No mutan sus parámetros (PHP pasa
 * los arrays por valor y aquí sólo se filtran copias).
 *
 * @since 2.3.2
 */
final class FailurePolicy
{
    /** Vocabulario de `TRUSTEED_CEL_FALLBACK_MODE` en este módulo (igual que el fixture). */
    public const FAILURE_MODES = ['strict', 'balanced', 'permissive'];

    /**
     * Modos que ABREN a un agente ante una avería, comparados exactamente. Es la
     * lista GLOBAL de la referencia (`AGENT_OPENING_FAILURE_MODES`); `observe`
     * no es de este vocabulario y nunca llega aquí (el resolutor lo convierte
     * en `strict`).
     */
    public const AGENT_OPENING_FAILURE_MODES = ['observe', 'balanced', 'permissive'];

    /**
     * Cualquier señal que sólo pone nuestro lado o el agente ⇒ agéntico. Un
     * token presentado cuenta aunque no verifique: nunca se trata como humano.
     *
     * @param string|null $agentId      DID del agente; '' equivale a ausente.
     * @param bool        $tokenPresent Se presentó `_trusteed_agent_token`.
     * @param bool        $originMarker Marca de origen de nuestro MCP.
     */
    public static function isAgentic(?string $agentId, bool $tokenPresent, bool $originMarker): bool
    {
        return ($agentId !== null && $agentId !== '') || $tokenPresent || $originMarker;
    }

    /**
     * Reglas que la válvula offline puede aplicar, en el orden recibido y
     * reindexadas. `appliesTo` sin distinguir mayúsculas.
     *
     * - Agente: todas salvo las `HUMAN` (con `appliesTo` ausente incluido).
     * - Humano: sólo `ALL` y `HUMAN`; ausente no cuenta (ante la duda, nunca
     *   bloquear a una persona).
     *
     * @param array<int,mixed> $rules `rules[]` del snapshot firmado.
     * @return array<int,array<string,mixed>>
     */
    public static function offlineRulesFor(array $rules, bool $isAgentic): array
    {
        return array_values(array_filter(
            $rules,
            static function ($rule) use ($isAgentic): bool {
                if (!is_array($rule)) {
                    return false;
                }
                $appliesTo = strtoupper((string) ($rule['appliesTo'] ?? ''));
                return $isAgentic
                    ? $appliesTo !== 'HUMAN'
                    : ($appliesTo === 'ALL' || $appliesTo === 'HUMAN');
            }
        ));
    }

    /**
     * El servidor no contestó (o no hay política legible). La válvula offline ya
     * filtrada con offlineRulesFor() bloquea a cualquiera; sin ella, un humano
     * pasa SIEMPRE, y a un agente se le aplica el modo del comerciante: abre
     * sólo con un modo de AGENT_OPENING_FAILURE_MODES y cierra con cualquier
     * otro valor, null incluido. No normaliza (igual que la referencia).
     *
     * @return string 'ALLOW' | 'BLOCK'.
     */
    public static function decideUnavailable(bool $isAgentic, ?string $failureMode, bool $offlineBlock): string
    {
        if ($offlineBlock) {
            return 'BLOCK';
        }
        if (!$isAgentic) {
            return 'ALLOW';
        }
        return $failureMode !== null && in_array($failureMode, self::AGENT_OPENING_FAILURE_MODES, true)
            ? 'ALLOW'
            : 'BLOCK';
    }

    /**
     * Una excepción NUESTRA durante la evaluación (no una regla, no la red). Un
     * humano nunca se bloquea por ella; a un agente no se le abre en ningún modo
     * —una avería propia no es un permiso—, ni siquiera con `balanced` o
     * `permissive`, que abren ante una caída del servicio y no ante un fallo de
     * nuestro código. Igual que `decide_on_internal_error` de Odoo y el
     * `trusteed:CEL-INTERNAL-ERROR` de WooCommerce.
     *
     * @return string 'ALLOW' | 'BLOCK'.
     */
    public static function decideOnInternalError(bool $isAgentic): string
    {
        return $isAgentic ? 'BLOCK' : 'ALLOW';
    }

    /**
     * Causas de una capa degradada (`PlatformDegradedKind` de la referencia).
     * En este módulo:
     *
     * - `invalid_signature`: el snapshot recién servido no verifica tras volver
     *   a descargar el JWKS una vez ({@see SnapshotClient::pullResult()}).
     * - `missing_snapshot`: no hay snapshot utilizable (red, HTTP ≠ 200, JWKS
     *   inalcanzable, caché propia que ya no verifica).
     * - `evaluator_unavailable`: snapshot verificado (también de caché) y
     *   `/v1/rules/evaluate` sin respuesta.
     * - `layer_disabled`: `TRUSTEED_CEL_ENABLED` apagado o módulo sin
     *   configurar (MerchantResolver::buildSnapshotClient() devuelve null).
     */
    public const KIND_INVALID_SIGNATURE     = 'invalid_signature';
    public const KIND_MISSING_SNAPSHOT      = 'missing_snapshot';
    public const KIND_EVALUATOR_UNAVAILABLE = 'evaluator_unavailable';
    public const KIND_LAYER_DISABLED        = 'layer_disabled';

    public const DEGRADED_KINDS = [
        self::KIND_INVALID_SIGNATURE,
        self::KIND_MISSING_SNAPSHOT,
        self::KIND_EVALUATOR_UNAVAILABLE,
        self::KIND_LAYER_DISABLED,
    ];

    /**
     * Port de `decideOnDegradedEnforcement` (platform-flow.ts, Task 20; fijado
     * por la sección `degradedEnforcement` del fixture). Sobre un AGENTE:
     *
     * - `invalid_signature` ⇒ BLOCK en todos los modos (ni las reglas ni el
     *   resolver de DIDs son del comerciante).
     * - `missing_snapshot` con token ⇒ BLOCK en todos los modos (sin snapshot
     *   no hay resolver con el que verificar el token); sin token ⇒ el modo.
     * - `evaluator_unavailable` ⇒ el modo y la válvula offline
     *   ({@see decideUnavailable()}).
     * - `layer_disabled` ⇒ ALLOW (configuración, no avería).
     *
     * Sobre un HUMANO nunca se cierra por avería: sólo `offlineBlock` de un
     * snapshot VERIFICADO (`evaluator_unavailable`) lo bloquea; en
     * `invalid_signature` y `missing_snapshot` se ignora. Un token presentado
     * cuenta como agéntico aunque `$isAgentic` llegue a false. Una causa
     * desconocida se trata como `invalid_signature`.
     *
     * @return string 'ALLOW' | 'BLOCK'.
     */
    public static function decideOnDegradedEnforcement(
        string $kind,
        bool $isAgentic,
        bool $tokenPresent,
        ?string $failureMode,
        bool $offlineBlock
    ): string {
        $agentic = $isAgentic || $tokenPresent;
        switch ($kind) {
            case self::KIND_LAYER_DISABLED:
                return 'ALLOW';
            case self::KIND_EVALUATOR_UNAVAILABLE:
                return self::decideUnavailable($agentic, $failureMode, $offlineBlock);
            case self::KIND_MISSING_SNAPSHOT:
                if (!$agentic) {
                    return 'ALLOW';
                }
                if ($tokenPresent) {
                    return 'BLOCK';
                }
                return self::decideUnavailable($agentic, $failureMode, false);
            default:
                // `invalid_signature` y cualquier causa desconocida.
                return $agentic ? 'BLOCK' : 'ALLOW';
        }
    }
}
