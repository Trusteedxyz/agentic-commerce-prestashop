<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * What happens when OUR code throws an exception during the order check.
 *
 * The decision is {@see FailurePolicy::decideOnInternalError()}: an agent is
 * held back in every mode (`balanced`/`permissive` open on a service outage,
 * not on a failure of our own code) and a human is never blocked. The block is
 * published as `trusteed:CEL-INTERNAL-ERROR`, never as "a rule blocked you", and
 * without the exception detail, which only goes to the internal log.
 *
 * Both the hook (`ValidateOrderHook::evaluateCart()`, which covers the PS 9
 * hook) and the `PaymentModule` override (errors outside `evaluateCart`) use it.
 *
 * @since 2.3.2
 */
final class InternalErrorGuard
{
    public const RULE_CODE = 'trusteed:CEL-INTERNAL-ERROR';

    /**
     * The `_trusteed_agent_token` of the request (POST) or, failing that, of the
     * cookie. The only source of the agentic signal in PS; no token returns ''.
     *
     * `Tools::getValue` also reads GET, and a link with
     * `?_trusteed_agent_token=x` opened by a person would turn them into an
     * "agent" (R001 in strict, held back on a fault). The legitimate token
     * arrives in the order POST body; a value that only comes in the query
     * string is ignored. If the same field comes in both POST and GET, POST wins
     * (what `Tools::getValue` already returned).
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
     * Is the current request agentic? If the token cannot even be read, we do not
     * know it is human, so it is treated as agentic (the human exemption only
     * covers a proven human).
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
     * Decides, leaves a trace and, if the verdict is BLOCK, throws.
     *
     * @throws CelVerdictException when the agent is held back.
     */
    public static function resolve(\Throwable $e, bool $isAgentic, int $cartId): void
    {
        $block = FailurePolicy::decideOnInternalError($isAgentic) === 'BLOCK';

        // A logging failure never changes the decision.
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
            // See above.
        }

        if ($block) {
            throw new CelVerdictException(
                self::RULE_CODE . ' — Agent checks could not be completed; '
                . 'agent checkouts are held in every mode. requires_escalation: true'
            );
        }
    }
}
