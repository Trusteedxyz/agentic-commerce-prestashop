<?php

declare(strict_types=1);

/**
 * McpToolDiscoveryTest — tool discovery + graceful degradation (spec-046 H1 + T030).
 *
 * The previous revision tested a MockTrusteedModule re-declaring isMcpCompliant().
 * The real module class extends PrestaShop's `Module` base class and cannot be
 * instantiated without the PS kernel, so its method body cannot be exercised in a
 * bare PHPUnit process. Instead of a tautological mock, this suite:
 *
 *   1. Asserts the REAL production source (trusteed.php) declares
 *      `isMcpCompliant(): bool` returning true (static source assertion — honest
 *      about not running the PS-coupled method).
 *   2. Exercises the REAL discovery DECISION logic that drives which tools are
 *      registered (ToolToggleSettings::effectiveEnabledMap), which is what
 *      getAgenticTools() now delegates to.
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\ToolToggleSettings;
use PHPUnit\Framework\TestCase;

final class McpToolDiscoveryTest extends TestCase
{
    private function moduleSource(): string
    {
        $path = __DIR__ . '/../../trusteed.php';
        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'trusteed.php must be readable');
        return $src;
    }

    // ─── 1. Real source contract ───────────────────────────────────────────────

    public function testRealModuleDeclaresIsMcpCompliantReturningTrue(): void
    {
        $src = $this->moduleSource();
        $this->assertMatchesRegularExpression(
            '/public function isMcpCompliant\(\)\s*:\s*bool/',
            $src,
            'Real module must declare isMcpCompliant(): bool'
        );
        // The method body must return true (the addon-96617 compliance signal).
        $this->assertMatchesRegularExpression(
            '/function isMcpCompliant\(\).*?return true;/s',
            $src
        );
    }

    public function testRealGetAgenticToolsGuardsOnSdkPresence(): void
    {
        $src = $this->moduleSource();
        // Graceful degradation: getAgenticTools returns [] when the SDK attribute
        // class is absent.
        $this->assertStringContainsString(
            "class_exists('PhpMcp\\\\Server\\\\Attributes\\\\McpTool')",
            $src,
            'getAgenticTools() must guard on the php-mcp/server attribute class'
        );
    }

    public function testRealGetAgenticToolsDelegatesToToggleResolver(): void
    {
        $src = $this->moduleSource();
        // The registration set must be computed from the effective enabled map,
        // not hard-coded (FR-018a + FR-018b).
        $this->assertStringContainsString('resolveEnabledToolMap', $src);
        $this->assertStringContainsString('effectiveEnabledMap', $src);
    }

    // ─── 2. Real discovery decision logic ──────────────────────────────────────

    public function testDefaultDiscoveryRegistersOnlyFulfillableImplementedEnabledTools(): void
    {
        $status = ToolToggleSettings::backendStatusMap(ToolToggleSettings::bundledCatalog());
        $effective = ToolToggleSettings::effectiveEnabledMap(ToolToggleSettings::DEFAULTS, $status);

        $registered = array_keys(array_filter($effective));

        // With install defaults: verify is the only tool registered (sign is
        // planned; the 3 payment rails are OFF / not-fulfillable / planned).
        $this->assertSame(['verify-agent-signature'], $registered);
    }

    public function testGracefulDegradeWhenBundleStatusUnknown(): void
    {
        // Empty status map → nothing registers (fail-closed), never crashes.
        $effective = ToolToggleSettings::effectiveEnabledMap(ToolToggleSettings::DEFAULTS, []);
        $this->assertSame([], array_keys(array_filter($effective)));
    }
}
