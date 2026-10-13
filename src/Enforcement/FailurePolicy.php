<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Main-flow failure policy. PHP port of the shared Trusteed reference
 * implementation, pinned by the conformance vectors in
 * tests/fixtures/platform-flow-conformance.json
 * (tests/enforcement/PlatformFlowConformanceTest.php).
 *
 * Invariant: a human buyer (a checkout with no agentic signal) is never blocked
 * by a fault of ours (network, 5xx, any 4xx including 429, timeout, unreadable
 * response, missing or unverifiable snapshot, internal exception), not even in
 * `strict`. Only a merchant rule with `appliesTo: ALL`/`HUMAN` that the offline
 * valve still evaluates can block them (the R030 cap, countries, schedule...).
 * `TRUSTEED_CEL_FALLBACK_MODE` only governs agentic checkouts: `strict` means
 * "strict with agents".
 *
 * The fourth decision of the reference (`needsServerEvaluation`) lives in
 * {@see RuleCode::needsServer()}, next to the code normalization; the sixth
 * (`resolveAgentDidPublicKey`) in {@see TokenVerifier::resolveAgentDidPublicKey()}.
 *
 * The mode passed to {@see decideUnavailable()} is resolved by
 * {@see MerchantResolver::getFallbackMode()}: it returns a value of
 * {@see FAILURE_MODES} compared exactly, and anything else (missing, empty,
 * `observe`/`enforce` from another platform, `STRICT`, `BALANCED`) becomes
 * `strict` (a corruption guard, pinned by FallbackModeDefaultTest). An
 * unresolved mode therefore CLOSES on an agent and never touches a human.
 *
 * Pure functions: no PrestaShop, no I/O. They do not mutate their parameters
 * (PHP passes arrays by value and only copies are filtered here).
 *
 * @since 2.3.2
 */
final class FailurePolicy
{
    /** Vocabulary of `TRUSTEED_CEL_FALLBACK_MODE` in this module (same as the fixture). */
    public const FAILURE_MODES = ['strict', 'balanced', 'permissive'];

    /**
     * Modes that OPEN to an agent on a fault, compared exactly. This is the
     * reference's GLOBAL list (`AGENT_OPENING_FAILURE_MODES`); `observe` is not
     * part of this module's vocabulary and never gets here (the resolver turns
     * it into `strict`).
     */
    public const AGENT_OPENING_FAILURE_MODES = ['observe', 'balanced', 'permissive'];

    /**
     * Any signal that only our side or the agent sets means agentic. A presented
     * token counts even if it does not verify: it is never treated as human.
     *
     * @param string|null $agentId      Agent DID; '' is the same as missing.
     * @param bool        $tokenPresent `_trusteed_agent_token` was presented.
     * @param bool        $originMarker Origin marker set by our MCP.
     */
    public static function isAgentic(?string $agentId, bool $tokenPresent, bool $originMarker): bool
    {
        return ($agentId !== null && $agentId !== '') || $tokenPresent || $originMarker;
    }

    /**
     * Rules the offline valve may apply, in received order and reindexed.
     * `appliesTo` is case-insensitive.
     *
     * - Agent: all but `HUMAN` (a missing `appliesTo` included).
     * - Human: only `ALL` and `HUMAN`; missing does not count (when in doubt,
     *   never block a person).
     *
     * @param array<int,mixed> $rules `rules[]` of the signed snapshot.
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
     * The server did not answer (or there is no readable policy). The offline
     * valve, already filtered with offlineRulesFor(), blocks anyone; without it a
     * human ALWAYS passes, and an agent gets the merchant's mode: it opens only
     * with a mode in AGENT_OPENING_FAILURE_MODES and closes on any other value,
     * null included. It does not normalize (same as the reference).
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
     * An exception of OURS during evaluation (not a rule, not the network). A
     * human is never blocked by it; an agent is not let through in any mode (an
     * internal fault is not a permission), not even with `balanced` or
     * `permissive`, which open on a service outage and not on a failure of our
     * own code. Same as `decide_on_internal_error` in Odoo and
     * `trusteed:CEL-INTERNAL-ERROR` in WooCommerce.
     *
     * @return string 'ALLOW' | 'BLOCK'.
     */
    public static function decideOnInternalError(bool $isAgentic): string
    {
        return $isAgentic ? 'BLOCK' : 'ALLOW';
    }

    /**
     * Causes of a degraded layer (`PlatformDegradedKind` in the reference).
     * In this module:
     *
     * - `invalid_signature`: the freshly served snapshot does not verify after
     *   fetching the JWKS once more ({@see SnapshotClient::pullResult()}).
     * - `missing_snapshot`: no usable snapshot (network, HTTP other than 200,
     *   unreachable JWKS, own cache that no longer verifies).
     * - `evaluator_unavailable`: verified snapshot (cached too) and
     *   `/v1/rules/evaluate` did not answer.
     * - `layer_disabled`: `TRUSTEED_CEL_ENABLED` off or module not configured
     *   (MerchantResolver::buildSnapshotClient() returns null).
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
     * Port of `decideOnDegradedEnforcement` (platform-flow.ts; pinned by the
     * `degradedEnforcement` section of the fixture). For an AGENT:
     *
     * - `invalid_signature`: BLOCK in every mode (neither the rules nor the DID
     *   resolver belong to the merchant).
     * - `missing_snapshot` with a token: BLOCK in every mode (without a snapshot
     *   there is no resolver to verify the token); without a token, the mode.
     * - `evaluator_unavailable`: the mode and the offline valve
     *   ({@see decideUnavailable()}).
     * - `layer_disabled`: ALLOW (configuration, not a fault).
     *
     * A HUMAN is never closed on a fault: only `offlineBlock` from a VERIFIED
     * snapshot (`evaluator_unavailable`) blocks them; it is ignored for
     * `invalid_signature` and `missing_snapshot`. A presented token counts as
     * agentic even if `$isAgentic` arrives as false. An unknown cause is treated
     * as `invalid_signature`.
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
                // `invalid_signature` and any unknown cause.
                return $agentic ? 'BLOCK' : 'ALLOW';
        }
    }
}
