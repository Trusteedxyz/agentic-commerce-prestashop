<?php

declare(strict_types=1);

/**
 * ToolToggleSettings — per-tool enablement (spec-046 FR-018b) + bundled catalog
 * fallback (FR-018a) for the PrestaShop platform module.
 *
 * FR-018b: each of the 5 catalog tools has an independent toggle persisted in
 * `ps_configuration`. Defaults:
 *   - sign-trust-receipt    → ON
 *   - verify-agent-signature → ON
 *   - dispatch-payment-acp  → OFF (explicit merchant opt-in)
 *   - dispatch-payment-x402 → OFF
 *   - dispatch-payment-ap2  → OFF
 * Disabled tools MUST be omitted from the MCP registration.
 *
 * FR-018a: the module embeds `agentic-tools-catalog.json` (the canonical catalog
 * for the packaged version). On first bootstrap, if the remote catalog endpoint
 * is unreachable, the module registers tools from this bundle. The bundle also
 * supplies the per-tool `routing.backendStatus`, so tools whose backend is
 * `planned` are registered DISABLED regardless of the merchant toggle (no
 * deployable backend yet — spec-046 OLA-1 honesty contract).
 *
 * This class is PrestaShop-runtime aware (reads `Configuration`), but exposes
 * pure helpers (`bundledCatalog()`, `effectiveEnabledMap()`, `defaultForTool()`)
 * that are unit-testable WITHOUT a PS kernel.
 */

namespace Trusteed\AgenticTools;

final class ToolToggleSettings
{
    /**
     * Stable tool keys → ps_configuration toggle config keys.
     * Keys mirror the canonical catalog id suffix (after "trusteed/").
     *
     * @var array<string,string>
     */
    public const TOGGLE_KEYS = [
        'sign-trust-receipt'     => 'TRUSTEED_TOOL_SIGN_RECEIPT',
        'verify-agent-signature' => 'TRUSTEED_TOOL_VERIFY_SIG',
        'dispatch-payment-acp'   => 'TRUSTEED_TOOL_PAY_ACP',
        'dispatch-payment-x402'  => 'TRUSTEED_TOOL_PAY_X402',
        'dispatch-payment-ap2'   => 'TRUSTEED_TOOL_PAY_AP2',
    ];

    /**
     * FR-018b safe defaults — trust + identity ON, the three payment rails OFF.
     *
     * @var array<string,bool>
     */
    public const DEFAULTS = [
        'sign-trust-receipt'     => true,
        'verify-agent-signature' => true,
        'dispatch-payment-acp'   => false,
        'dispatch-payment-x402'  => false,
        'dispatch-payment-ap2'   => false,
    ];

    /**
     * Module fulfillability (spec-046 OLA-1/OLA-2 honesty contract).
     *
     * Distinct from catalog `backendStatus`: a backend can be `implemented`
     * globally yet NOT reachable from THIS platform module. Specifically:
     *   - sign-trust-receipt → catalog "planned" (no write endpoint at all).
     *   - dispatch-payment-ap2 → catalog "planned" (dormant, no dispatch route).
     *   - dispatch-payment-acp → catalog "implemented" BUT the PS module cannot
     *       reach `process_agent_payment` (needs a store-slug MCP gateway call +
     *       MCP JSON-RPC client the module does not provision; the MCAP tool also
     *       takes no cartId). So it is NOT module-fulfillable.
     *   - verify-agent-signature → REST, reachable. Fulfillable.
     *   - dispatch-payment-x402 → REST, reachable. Fulfillable.
     *
     * A tool that is not module-fulfillable is ALWAYS registered disabled, even
     * if the merchant toggles it ON (it would only return capability_disabled).
     *
     * @var array<string,bool>
     */
    public const MODULE_FULFILLABLE = [
        'sign-trust-receipt'     => false,
        'verify-agent-signature' => true,
        'dispatch-payment-acp'   => false,
        'dispatch-payment-x402'  => true,
        'dispatch-payment-ap2'   => false,
    ];

    /**
     * Returns the merchant's default toggle for a tool key (pure; no PS runtime).
     */
    public static function defaultForTool(string $toolKey): bool
    {
        return self::DEFAULTS[$toolKey] ?? false;
    }

    /**
     * Whether the PS module can actually fulfil this tool (pure; no PS runtime).
     */
    public static function moduleFulfillable(string $toolKey): bool
    {
        return self::MODULE_FULFILLABLE[$toolKey] ?? false;
    }

    /**
     * Loads and decodes the bundled catalog JSON (FR-018a). Pure — only touches
     * the filesystem of the packaged module, no PS runtime.
     *
     * @return array{version:string,tools:list<array<string,mixed>>}
     *
     * @throws \RuntimeException when the bundle is missing or malformed (a build
     *         packaging error — must fail loudly, not silently degrade).
     */
    public static function bundledCatalog(?string $path = null): array
    {
        $path ??= dirname(__DIR__, 2) . '/agentic-tools-catalog.json';

        if (!is_file($path)) {
            throw new \RuntimeException('Bundled catalog not found at ' . $path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Bundled catalog unreadable at ' . $path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Bundled catalog is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded) || !isset($decoded['tools']) || !is_array($decoded['tools'])) {
            throw new \RuntimeException('Bundled catalog has no tools array');
        }

        return [
            'version' => (string) ($decoded['version'] ?? '0.0.0'),
            'tools'   => array_values($decoded['tools']),
        ];
    }

    /**
     * Maps a catalog tool id (e.g. "trusteed/dispatch-payment-acp") to its
     * stable toggle key (e.g. "dispatch-payment-acp").
     */
    public static function toolKeyFromCatalogId(string $catalogId): string
    {
        $prefix = 'trusteed/';
        return str_starts_with($catalogId, $prefix)
            ? substr($catalogId, strlen($prefix))
            : $catalogId;
    }

    /**
     * Computes the effective enabled map combining FR-018a backendStatus with
     * FR-018b merchant toggles. PURE — accepts both inputs explicitly so it can
     * be tested without PS or filesystem.
     *
     * A tool is enabled iff:
     *   - the merchant toggle is ON, AND
     *   - the catalog backendStatus for that tool is "implemented", AND
     *   - the tool is module-fulfillable from PrestaShop (MODULE_FULFILLABLE).
     * Tools with backendStatus "planned" OR not module-fulfillable are ALWAYS
     * disabled.
     *
     * @param array<string,bool>   $merchantToggles  toolKey → on/off
     * @param array<string,string> $backendStatusMap toolKey → "implemented"|"planned"
     * @return array<string,bool>                     toolKey → effective enabled
     */
    public static function effectiveEnabledMap(array $merchantToggles, array $backendStatusMap): array
    {
        $result = [];
        foreach (self::TOGGLE_KEYS as $toolKey => $_cfgKey) {
            $toggle  = $merchantToggles[$toolKey] ?? self::defaultForTool($toolKey);
            $status  = $backendStatusMap[$toolKey] ?? 'planned';
            $result[$toolKey] = $toggle
                && $status === 'implemented'
                && self::moduleFulfillable($toolKey);
        }
        return $result;
    }

    /**
     * Extracts toolKey → backendStatus from a decoded catalog (pure helper).
     *
     * @param array{tools:list<array<string,mixed>>} $catalog
     * @return array<string,string>
     */
    public static function backendStatusMap(array $catalog): array
    {
        $map = [];
        foreach ($catalog['tools'] as $tool) {
            if (!is_array($tool) || !isset($tool['id'])) {
                continue;
            }
            $toolKey = self::toolKeyFromCatalogId((string) $tool['id']);
            $routing = $tool['routing'] ?? null;
            $status  = is_array($routing) ? (string) ($routing['backendStatus'] ?? 'planned') : 'planned';
            $map[$toolKey] = $status;
        }
        return $map;
    }

    // ─── PS-runtime accessors (thin; delegate to pure helpers above) ───────────

    /**
     * Optional test/wiring override for isEnabled() — toolKey → bool.
     *
     * Production NEVER sets this (it stays null → runtime resolution). It exists
     * so unit tests that exercise a tool's enabled happy-path can assert the C3
     * guard without booting a full PrestaShop `Configuration` backing store.
     *
     * @var array<string,bool>|null
     */
    private static ?array $enabledOverride = null;

    /**
     * Force the effective-enabled map for isEnabled() (tests only). Pass null to
     * clear and fall back to runtime resolution.
     *
     * @param array<string,bool>|null $map
     */
    public static function overrideEnabledForTesting(?array $map): void
    {
        self::$enabledOverride = $map;
    }

    /**
     * Runtime fail-closed check for a single tool (C3 defense-in-depth).
     *
     * The add-on 96617 auto-scans the `#[McpTool]` attribute of the Tools/*
     * classes and registers them directly, BYPASSING getAgenticTools() and its
     * FR-018a/FR-018b gating. Each tool's execute() therefore re-asserts its own
     * effective enablement at call time via this helper so a toggled-OFF or
     * non-fulfillable / "planned" tool stays fail-closed even when registered by
     * the auto-scan path.
     *
     * Reads the PS-runtime merchant toggles and the bundled catalog
     * backendStatus, then collapses them through effectiveEnabledMap(). If the
     * bundle is unreadable (packaging error) every status is unknown → "planned"
     * → the tool resolves DISABLED (fail-closed), never throwing out of the
     * guard path.
     *
     * @throws never
     */
    public static function isEnabled(string $toolKey): bool
    {
        if (self::$enabledOverride !== null) {
            return self::$enabledOverride[$toolKey] ?? false;
        }

        try {
            $statusMap = self::backendStatusMap(self::bundledCatalog());
        } catch (\Throwable $e) {
            // Bundle missing/corrupt → fail closed (treat as all "planned").
            $statusMap = [];
        }

        $effective = self::effectiveEnabledMap(self::merchantToggles(), $statusMap);

        return $effective[$toolKey] ?? false;
    }

    /**
     * Reads the merchant toggle map from ps_configuration. Falls back to the
     * FR-018b defaults for any key never persisted yet.
     *
     * @return array<string,bool>
     */
    public static function merchantToggles(): array
    {
        $toggles = [];
        foreach (self::TOGGLE_KEYS as $toolKey => $cfgKey) {
            $stored = \Configuration::get($cfgKey);
            // Never-set → use safe default. Stored "0"/"1" → bool.
            $toggles[$toolKey] = ($stored === false || $stored === null)
                ? self::defaultForTool($toolKey)
                : ((string) $stored === '1');
        }
        return $toggles;
    }

    /**
     * Persists the FR-018b defaults at install time (idempotent).
     */
    public static function installDefaults(): void
    {
        foreach (self::TOGGLE_KEYS as $toolKey => $cfgKey) {
            \Configuration::updateValue($cfgKey, self::defaultForTool($toolKey) ? '1' : '0');
        }
    }

    /**
     * Removes all toggle config keys at uninstall.
     */
    public static function uninstallDefaults(): void
    {
        foreach (self::TOGGLE_KEYS as $cfgKey) {
            // TOGGLE_KEYS values are the ps_configuration keys.
            \Configuration::deleteByName($cfgKey);
        }
    }
}
