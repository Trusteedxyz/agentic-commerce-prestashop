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
 * half of the merchants and SILENTLY inert for the other: no exception, no
 * log, just a rule that never fires. That is what happened here: `$hasTier2`
 * came out false on a canonical merchant, so layer 2 was never called and the
 * layer 2 path (no verified agent required) was UNREACHABLE.
 *
 * Deliberately free of the PrestaShop kernel, so it is unit-testable on its
 * own, see `tests/enforcement/RuleCodeTest.php`.
 */
final class RuleCode
{
    /**
     * Codes this port evaluates LOCALLY with a real ability to block.
     *
     * Empty. `ValidateOrderHook::evaluateRule()` only knows R001 and R007, and
     * its loop only runs with VERIFIED claims: R001 always comes out ALLOW (there
     * is an agentId) and R007 is ALLOW by design. Nothing local can block, so
     * every enabled rule goes to the server, including R031 and above, which the
     * old hand-written list ("tier-2", R002 to R030) left unevaluated: a
     * merchant with R035 as the only rule never reached layer 2.
     *
     * Parity with the PrestaShop entry of the reference implementation's
     * local rule short codes, pinned by the
     * `serverRouting` vectors of `platform-flow-conformance.json`
     * (`tests/enforcement/PlatformFlowConformanceTest.php`).
     */
    public const LOCAL_ONLY_SHORT_CODES = [];

    /**
     * Strip a canonical dotted suffix: `R030.simple-controls` becomes `R030`.
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

    /** Does this rule need the server for a verdict? (any code format) */
    public static function isServerRule(string $ruleCode): bool
    {
        return !in_array(self::shortCode($ruleCode), self::LOCAL_ONLY_SHORT_CODES, true);
    }

    /**
     * Is there any ENABLED rule that only the server can decide?
     *
     * `enabled` is read with `empty()`: `true`, `1` and `"1"` enable; `false`,
     * `0`, `"0"` and missing do not. It is not tightened to `=== true`: a rule
     * saved as `1` would stop reaching the server with no error at all.
     *
     * @param array $rules Snapshot `rules` (any code format).
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
     * Same as {@see needsServer}, discarding rules in `observe`
     * (a missing `mode` counts as `enforce`).
     *
     * @param array $rules Snapshot `rules` (any code format).
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
