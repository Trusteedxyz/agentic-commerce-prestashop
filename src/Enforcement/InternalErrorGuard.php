<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Qué se hace cuando NUESTRO código lanza una excepción durante el chequeo del
 * pedido (wiki/gotchas/una-averia-propia-no-puede-ser-un-permiso-2026-09-09.md).
 *
 * La decisión es {@see FailurePolicy::decideOnInternalError()}: un agente se
 * retiene en todos los modos (`balanced`/`permissive` abren ante una caída del
 * servicio, no ante un fallo de nuestro código) y un humano nunca se bloquea. El
 * bloqueo se publica como `trusteed:CEL-INTERNAL-ERROR`, nunca como «una regla te
 * bloqueó», y sin el detalle de la excepción, que sólo va al log interno.
 *
 * Lo usan por igual el hook (`ValidateOrderHook::evaluateCart()`, que cubre el
 * hook de PS 9) y el override `PaymentModule` (errores fuera de `evaluateCart`).
 *
 * @since 2.3.2
 */
final class InternalErrorGuard
{
    public const RULE_CODE = 'trusteed:CEL-INTERNAL-ERROR';

    /**
     * `_trusteed_agent_token` de la petición (POST) o, si no, de la cookie. Única
     * fuente de la señal agéntica de PS; sin token ⇒ ''.
     *
     * Task 27c (L5): `Tools::getValue` también lee GET, y un enlace con
     * `?_trusteed_agent_token=x` que abre una persona la convertiría en «agente»
     * (R001 en strict, retenida ante una avería). El token legítimo llega en el
     * cuerpo del POST del pedido; un valor que sólo viene en la query string se
     * ignora. Si el mismo campo viene en POST y en GET, manda el POST (lo que
     * ya devolvía `Tools::getValue`).
     */
    public static function presentedToken(): string
    {
        $queryStringOnly = isset($_GET['_trusteed_agent_token']) && !isset($_POST['_trusteed_agent_token']);
        $token           = $queryStringOnly ? '' : (string) \Tools::getValue('_trusteed_agent_token', '');
        if ($token !== '') {
            return $token;
        }

        return (string) (\Context::getContext()->cookie->trusteed_agent_token ?? '');
    }

    /**
     * ¿La petición actual es agéntica? Si ni siquiera se puede leer el token no
     * se sabe que sea humana: se trata como agéntica (D1 sólo ampara al humano
     * probado).
     */
    public static function requestIsAgentic(): bool
    {
        try {
            return FailurePolicy::isAgentic(null, self::presentedToken() !== '', false);
        } catch (\Throwable $ignored) {
            return true;
        }
    }

    /**
     * Decide, deja rastro y, si el veredicto es BLOCK, lanza.
     *
     * @throws CelVerdictException cuando se retiene al agente.
     */
    public static function resolve(\Throwable $e, bool $isAgentic, int $cartId): void
    {
        $block = FailurePolicy::decideOnInternalError($isAgentic) === 'BLOCK';

        // Un fallo al registrar nunca cambia la decisión.
        try {
            if ($block) {
                $detail = json_encode(
                    ['error' => $e->getMessage(), 'class' => get_class($e), 'isAgentic' => true, 'decision' => 'BLOCK'],
                    JSON_INVALID_UTF8_SUBSTITUTE
                );
                $line = '[trusteed.cel.evaluator_error] ' . ($detail !== false ? $detail : get_class($e));
            } else {
                $line = '[trusteed.cel.evaluator_error_human_allowed] ' . get_class($e);
            }
            \PrestaShopLogger::addLog($line, \PrestaShopLogger::LOG_SEVERITY_ERROR, null, 'Cart', $cartId, true);
        } catch (\Throwable $logError) {
            // Ver arriba.
        }

        if ($block) {
            throw new CelVerdictException(
                self::RULE_CODE . ' — Agent checks could not be completed; '
                . 'agent checkouts are held in every mode. requires_escalation: true'
            );
        }
    }
}
