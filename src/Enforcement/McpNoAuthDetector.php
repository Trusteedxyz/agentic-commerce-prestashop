<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Spec-048 W1.1 — PrestaShop MCP "No-Auth" mode detector.
 *
 * PrestaShop 9.x ships the official MCP server addon (`ps_mcp_server`,
 * addon 96617). That server can be configured in a "No-Auth" mode, which is a
 * fail-open foot-gun: any agent can invoke webservice/MCP operations without
 * presenting an authentication key, which undermines Trusteed's agent-identity
 * binding (the whole premise of the CEL enforcement layer).
 *
 * Detection posture (mirrors the observe/enforce convention used across the
 * Enforcement layer — see ValidateOrderHook + WebserviceGapMiddleware):
 *
 *   - NO_AUTH detected → emit an OBSERVE signal: a persistent admin notice flag
 *     (rendered as a back-office warning by AdminSettings) + a PS log warning.
 *     Does NOT block on its own (observe is the default posture).
 *
 *   - TOKEN mode (the common, secure case) → no-op.
 *
 *   - UNKNOWN posture (ps_mcp_server not installed/enabled) → no-op.
 *
 * ── H3 HONESTY NOTE — enforce-time fail-closed is NOT reachable from this module
 *
 * The PrestaShop MCP server is the official add-on 96617 (`ps_mcp_server`),
 * which runs OUTSIDE this module. This module has NO request-time caller in the
 * MCP/Webservice path: the only wired caller is the back-office sweep on
 * `displayBackOfficeHeader`, which invokes `detect()` with the default
 * `$unknownAgent = true` ONLY to raise the observe banner — it never has real
 * agent context and never blocks.
 *
 * Consequently the `detect(false)` / strict-mode `throw PrestaShopException`
 * branch below is effectively DEAD from within this module: there is no MCP
 * request-time integration point here to call it with a real agent context.
 * The branch is intentionally RETAINED as a reserved seam for a future
 * integration where the official add-on (or a proxy in front of it) drives this
 * detector per-request with `detect($unknownAgent)`. Until that integration
 * exists, this detector ships in OBSERVE mode only — do not assume it
 * fail-closes the MCP path. See spec-048 W1.1 + WebserviceGapMiddleware (which
 * is the analogous observe-only surface for the Webservice gap).
 *
 * The detector reads the PS MCP server configuration the same way the rest of
 * the Enforcement layer reads config: via \Configuration::get. The official
 * addon's auth posture is surfaced through a configuration value; because the
 * exact key has varied across addon releases, we probe a small set of known
 * candidate keys defensively and also derive No-Auth from the structural
 * signal "MCP enabled but no auth key configured".
 *
 * @package Trusteed\Enforcement
 */
class McpNoAuthDetector
{
    /** PS MCP server is in a No-Auth (fail-open) posture. */
    public const MODE_NO_AUTH = 'no_auth';

    /** PS MCP server requires an authentication token/key (secure). */
    public const MODE_TOKEN = 'token';

    /** PS MCP server is not installed/enabled, or posture is indeterminate. */
    public const MODE_UNKNOWN = 'unknown';

    /** Admin notice option key — avoids repeated notices across requests. */
    private const ADMIN_NOTICE_KEY = 'trusteed_cel_mcp_noauth_notice';

    /**
     * Candidate PS Configuration keys that may carry the MCP server auth mode.
     * Probed in order; first non-empty wins. Kept defensive because the
     * official addon's key name has shifted across releases.
     *
     * @var list<string>
     */
    private const AUTH_MODE_KEYS = [
        'PS_MCP_SERVER_AUTH_MODE',
        'PS_MCP_AUTH_MODE',
        'PS_MCP_SERVER_AUTH',
    ];

    /** Candidate keys for the "MCP server enabled" master switch. */
    private const ENABLED_KEYS = [
        'PS_MCP_SERVER_ENABLED',
        'PS_MCP_SERVER',
        'PS_MCP_ENABLED',
    ];

    /** Candidate keys for the configured auth key/secret. */
    private const AUTH_KEY_KEYS = [
        'PS_MCP_SERVER_AUTH_KEY',
        'PS_MCP_SERVER_TOKEN',
        'PS_MCP_AUTH_KEY',
    ];

    /** Auth-mode values that unambiguously mean "no authentication". */
    private const NO_AUTH_VALUES = ['none', 'no-auth', 'noauth', 'no_auth', 'disabled', 'off', 'open', 'public'];

    /** Auth-mode values that mean a secure/token posture. */
    private const TOKEN_VALUES = ['token', 'bearer', 'apikey', 'api_key', 'api-key', 'oauth', 'oauth2', 'secret'];

    /**
     * Register the detector on the module.
     *
     * The detector is surfaced on each back-office page load (cheap config
     * read) so the merchant sees the observe banner promptly after enabling
     * the MCP server in No-Auth mode. Wired from Trusteed::install().
     */
    public static function register(\Trusteed $module): void
    {
        $module->registerHook('displayBackOfficeHeader');
    }

    /**
     * Unregister hook on module uninstall.
     */
    public static function unregister(\Trusteed $module): void
    {
        $module->unregisterHook('displayBackOfficeHeader');
    }

    /**
     * Run detection.
     *
     * @param bool $unknownAgent Whether the agent that triggered this check is
     *                           unknown/unverified. Defaults to true because the
     *                           ONLY wired caller is the back-office sweep, which
     *                           has no agent context (observe path).
     *
     *                           NOTE (H3): no request-time MCP caller exists in
     *                           this module, so `detect(false)` is never invoked
     *                           in production and the strict-mode throw below is
     *                           an unreachable, reserved seam (see class docblock).
     *                           This parameter is kept for a future integration
     *                           that drives the detector per-request.
     *
     * @throws \PrestaShopException Reserved: in strict fallback mode when a
     *                              No-Auth MCP server is detected AND the agent
     *                              is unknown. NOT reachable from the module's
     *                              current (observe-only) wiring.
     */
    public function detect(bool $unknownAgent = true): void
    {
        $shopId = MerchantResolver::currentShopId();
        if (!MerchantResolver::isEnabled($shopId)) {
            return; // CEL disabled for this shop — fully inert.
        }

        $mode = self::classifyAuthMode($shopId);

        if ($mode !== self::MODE_NO_AUTH) {
            return; // Token mode or unknown posture — no-op.
        }

        // ── No-Auth detected: always surface the OBSERVE signal ─────────────
        self::raiseObserveSignal($shopId);

        // ── ENFORCE gate: strict fallback + unknown agent → fail-closed ─────
        $fallback = MerchantResolver::getFallbackMode($shopId);
        if ($fallback === 'strict' && $unknownAgent) {
            throw new \PrestaShopException(
                'trusteed:R001 — PrestaShop MCP server is running in No-Auth mode '
                . 'and the agent identity could not be verified. '
                . 'requires_escalation: true'
            );
        }
    }

    /**
     * Classify the PS MCP server authentication posture from Configuration.
     *
     * @param int $shopId Multishop scope (0 = global).
     * @return self::MODE_*
     */
    private static function classifyAuthMode(int $shopId): string
    {
        // 1. Explicit auth-mode value, if the addon exposes one.
        $rawMode = self::firstConfig(self::AUTH_MODE_KEYS, $shopId);
        if ($rawMode !== '') {
            $normalized = strtolower(trim($rawMode));
            if (in_array($normalized, self::NO_AUTH_VALUES, true)) {
                return self::MODE_NO_AUTH;
            }
            if (in_array($normalized, self::TOKEN_VALUES, true)) {
                return self::MODE_TOKEN;
            }
            // An unrecognised explicit value is treated as UNKNOWN — we do not
            // guess a posture we cannot interpret (no false enforce).
            return self::MODE_UNKNOWN;
        }

        // 2. Structural signal: MCP server enabled but no auth key present.
        if (self::isMcpEnabled($shopId)) {
            $authKey = self::firstConfig(self::AUTH_KEY_KEYS, $shopId);
            return $authKey === '' ? self::MODE_NO_AUTH : self::MODE_TOKEN;
        }

        // 3. No ps_mcp_server config detected at all.
        return self::MODE_UNKNOWN;
    }

    /**
     * Emit the observe signal: persistent admin notice flag + PS log warning.
     */
    private static function raiseObserveSignal(int $shopId): void
    {
        \Configuration::updateValue(self::ADMIN_NOTICE_KEY, '1');

        \PrestaShopLogger::addLog(
            'CEL: PrestaShop MCP server detected in No-Auth mode '
            . '(merchant=' . MerchantResolver::getMerchantId($shopId) . '). '
            . 'Agent identity binding is bypassed on the MCP/webservice path. '
            . 'Configure the MCP server to require an authentication token, '
            . 'or set the Trusteed CEL fallback mode to strict to fail-closed.',
            \PrestaShopLogger::LOG_SEVERITY_WARNING,
            null,
            'Trusteed',
            null,
            true
        );
    }

    /**
     * Whether any "MCP server enabled" master switch is truthy.
     */
    private static function isMcpEnabled(int $shopId): bool
    {
        foreach (self::ENABLED_KEYS as $key) {
            $value = strtolower(trim(self::getConfig($key, $shopId)));
            if ($value === '1' || $value === 'true' || $value === 'on' || $value === 'enabled') {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the first non-empty value among a list of candidate config keys.
     *
     * @param list<string> $keys
     */
    private static function firstConfig(array $keys, int $shopId): string
    {
        foreach ($keys as $key) {
            $value = trim(self::getConfig($key, $shopId));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Multishop-aware Configuration read (mirrors MerchantResolver::getConfig).
     */
    private static function getConfig(string $key, int $shopId): string
    {
        if ($shopId > 0) {
            return (string) \Configuration::get($key, null, null, $shopId);
        }
        return (string) \Configuration::get($key);
    }

    /**
     * Acknowledge and clear the No-Auth observe notice.
     * Called from the admin settings page when the merchant clicks "Acknowledge".
     */
    public static function acknowledgeNotice(): void
    {
        \Configuration::updateValue(self::ADMIN_NOTICE_KEY, '0');
    }

    /**
     * Whether an unacknowledged No-Auth observe notice is pending.
     */
    public static function hasPendingNotice(): bool
    {
        return (string) \Configuration::get(self::ADMIN_NOTICE_KEY) === '1';
    }

    /**
     * Admin banner copy surfaced by AdminSettings when a notice is pending.
     */
    public static function adminBannerMessage(): string
    {
        return 'CEL: PrestaShop MCP server is running in No-Auth mode. '
            . 'Agents can call MCP/webservice operations without authenticating, '
            . 'which bypasses Trusteed agent-identity binding. '
            . 'Require an authentication token on the MCP server, or set the '
            . 'CEL fallback mode to strict to fail-closed on unverified agents.';
    }
}
