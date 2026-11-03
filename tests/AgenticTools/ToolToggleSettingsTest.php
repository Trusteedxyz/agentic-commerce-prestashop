<?php

declare(strict_types=1);

/**
 * ToolToggleSettingsTest — spec-046 FR-018a (bundled catalog) + FR-018b (toggles)
 * pure logic, tested against the REAL class (no PS runtime, no filesystem mocks
 * beyond the bundled JSON shipped in the module).
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\ToolToggleSettings;
use PHPUnit\Framework\TestCase;

final class ToolToggleSettingsTest extends TestCase
{
    // ─── FR-018b defaults ──────────────────────────────────────────────────────

    public function testTrustAndIdentityDefaultOn(): void
    {
        $this->assertTrue(ToolToggleSettings::defaultForTool('sign-trust-receipt'));
        $this->assertTrue(ToolToggleSettings::defaultForTool('verify-agent-signature'));
    }

    public function testPaymentRailsDefaultOff(): void
    {
        $this->assertFalse(ToolToggleSettings::defaultForTool('dispatch-payment-acp'));
        $this->assertFalse(ToolToggleSettings::defaultForTool('dispatch-payment-x402'));
        $this->assertFalse(ToolToggleSettings::defaultForTool('dispatch-payment-ap2'));
    }

    // ─── FR-018a bundled catalog ───────────────────────────────────────────────

    public function testBundledCatalogLoadsFiveTools(): void
    {
        $catalog = ToolToggleSettings::bundledCatalog();
        $this->assertSame('1.0.0', $catalog['version']);
        $this->assertCount(5, $catalog['tools']);
    }

    public function testBackendStatusMapReflectsHonestyContract(): void
    {
        $status = ToolToggleSettings::backendStatusMap(ToolToggleSettings::bundledCatalog());

        // OLA-1 honesty: sign + ap2 are planned; verify + acp + x402 are implemented.
        $this->assertSame('planned', $status['sign-trust-receipt']);
        $this->assertSame('planned', $status['dispatch-payment-ap2']);
        $this->assertSame('implemented', $status['verify-agent-signature']);
        $this->assertSame('implemented', $status['dispatch-payment-acp']);
        $this->assertSame('implemented', $status['dispatch-payment-x402']);
    }

    public function testMissingBundleThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ToolToggleSettings::bundledCatalog('/nonexistent/catalog.json');
    }

    // ─── effectiveEnabledMap: toggle × backendStatus × fulfillability ──────────

    public function testEffectiveMapForDefaultsAgainstBundledCatalog(): void
    {
        $status = ToolToggleSettings::backendStatusMap(ToolToggleSettings::bundledCatalog());
        $effective = ToolToggleSettings::effectiveEnabledMap(ToolToggleSettings::DEFAULTS, $status);

        // ONLY x402 is enabled by default: verify is fulfillable+implemented but
        // its default toggle is ON — wait, verify default is ON and implemented
        // and fulfillable → enabled. sign default ON but planned → disabled.
        $this->assertTrue($effective['verify-agent-signature'], 'verify: ON + implemented + fulfillable');
        $this->assertFalse($effective['sign-trust-receipt'], 'sign: planned → disabled despite ON');
        $this->assertFalse($effective['dispatch-payment-acp'], 'acp: OFF default + not fulfillable');
        $this->assertFalse($effective['dispatch-payment-x402'], 'x402: OFF default');
        $this->assertFalse($effective['dispatch-payment-ap2'], 'ap2: OFF + planned');
    }

    public function testX402EnabledWhenMerchantTogglesItOn(): void
    {
        $status = ToolToggleSettings::backendStatusMap(ToolToggleSettings::bundledCatalog());
        $toggles = ['dispatch-payment-x402' => true] + ToolToggleSettings::DEFAULTS;

        $effective = ToolToggleSettings::effectiveEnabledMap($toggles, $status);
        $this->assertTrue($effective['dispatch-payment-x402'], 'x402: ON + implemented + fulfillable');
    }

    public function testAcpStaysDisabledEvenWhenToggledOn(): void
    {
        // ACP is implemented globally but NOT module-fulfillable → always disabled.
        $status = ToolToggleSettings::backendStatusMap(ToolToggleSettings::bundledCatalog());
        $toggles = ['dispatch-payment-acp' => true] + ToolToggleSettings::DEFAULTS;

        $effective = ToolToggleSettings::effectiveEnabledMap($toggles, $status);
        $this->assertFalse($effective['dispatch-payment-acp'], 'ACP not module-fulfillable');
    }

    public function testPlannedToolStaysDisabledEvenWhenToggledOn(): void
    {
        $status = ToolToggleSettings::backendStatusMap(ToolToggleSettings::bundledCatalog());
        $toggles = [
            'sign-trust-receipt'   => true,
            'dispatch-payment-ap2' => true,
        ] + ToolToggleSettings::DEFAULTS;

        $effective = ToolToggleSettings::effectiveEnabledMap($toggles, $status);
        $this->assertFalse($effective['sign-trust-receipt']);
        $this->assertFalse($effective['dispatch-payment-ap2']);
    }

    public function testUnknownBackendStatusFailsClosed(): void
    {
        // Empty status map → everything "planned" → all disabled.
        $effective = ToolToggleSettings::effectiveEnabledMap(
            array_fill_keys(array_keys(ToolToggleSettings::TOGGLE_KEYS), true),
            []
        );
        foreach ($effective as $on) {
            $this->assertFalse($on, 'unknown backendStatus must fail closed');
        }
    }

    public function testToolKeyFromCatalogId(): void
    {
        $this->assertSame(
            'dispatch-payment-x402',
            ToolToggleSettings::toolKeyFromCatalogId('trusteed/dispatch-payment-x402')
        );
        $this->assertSame('verbatim', ToolToggleSettings::toolKeyFromCatalogId('verbatim'));
    }
}
