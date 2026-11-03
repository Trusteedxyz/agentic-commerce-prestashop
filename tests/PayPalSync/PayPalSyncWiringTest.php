<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\PayPalSync\PayPalSyncService;

/**
 * Gates de cableado sobre las fuentes (trusteed.php y el controlador no se
 * pueden cargar sin PrestaShop). Cada aserción nombra el hecho que protege.
 */
final class PayPalSyncWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        $s = file_get_contents(__DIR__ . '/../../' . $rel);
        $this->assertIsString($s, $rel);
        return $s;
    }

    public function testElControladorDelegaLaAllowlistYNoTieneCopiaPrivada(): void
    {
        $c = $this->src('controllers/admin/AdminTrusteedController.php');
        $this->assertStringContainsString('ApiBaseAllowlist::isAllowedForSecrets(', $c);
        $this->assertStringNotContainsString('ApiBaseAllowlist::isAllowed(', $c);
        $this->assertStringNotContainsString('private const ALLOWED_API_HOSTS', $c);
        $this->assertStringNotContainsString('private const DEV_API_HOSTS', $c);
    }

    public function testElHookDelBackOfficeComparaLaHuella(): void
    {
        $m = $this->src('trusteed.php');
        $this->assertMatchesRegularExpression('/function hookDisplayBackOfficeTop[\s\S]*PayPalSyncFactory::[a-zA-Z]*\(/', $m);
    }

    public function testElControladorExponeLasAccionesAjaxDelSync(): void
    {
        $c = $this->src('controllers/admin/AdminTrusteedController.php');
        $this->assertStringContainsString('function ajaxProcessPaypalSync(', $c);
        $this->assertStringContainsString('function ajaxProcessPaypalSyncStatus(', $c);
    }

    public function testLaDesinstalacionBorraLasClavesPropiasYNoTocaLasDePayPal(): void
    {
        $m = $this->src('trusteed.php');
        $uninstall = substr($m, (int) strpos($m, 'public function uninstall('));
        $this->assertNotSame('', $uninstall);
        foreach (PayPalSyncService::OWN_CONFIG_KEYS as $key) {
            $this->assertStringContainsString("deleteByName('" . $key . "')", $uninstall, $key);
        }
        // Las claves del módulo PayPal son ajenas: ni lectura-borrado ni escritura.
        $this->assertDoesNotMatchRegularExpression("/(deleteByName|updateValue)\\('PAYPAL_/", $m);
        $svc = $this->src('src/Service/PayPalSync/PayPalSyncService.php')
            . $this->src('src/Service/PayPalSync/CredentialReader.php')
            . $this->src('src/Service/PayPalSync/PrestaShopConfigStore.php');
        $this->assertDoesNotMatchRegularExpression("/(set|updateValue|deleteByName)\\([^)]*'PAYPAL_/", $svc);
    }

    public function testElInitPublicaLaConfigAjaxParaLaTarjetaDeSincronizacionDeLaSpa(): void
    {
        $js = $this->src('views/js/trusteed-init.js');
        // La SPA lee window.__AMCP_PS_CONFIG__.{ajaxUrl,adminToken} (WooPlatformSync).
        $this->assertMatchesRegularExpression('/__AMCP_PS_CONFIG__\s*=/', $js);
        $this->assertMatchesRegularExpression('/ajaxUrl:\s*cfg\.ajaxUrl/', $js);
        $this->assertMatchesRegularExpression('/adminToken:\s*cfg\.adminToken/', $js);
        // Debe fijarse ANTES de montar la SPA.
        $this->assertLessThan(
            (int) strpos($js, 'TrusteedEmbed.mount('),
            (int) strpos($js, '__AMCP_PS_CONFIG__ =')
        );
    }

    public function testLaPlantillaEscapaUrlYTokenDeAdminQueViajanComoDataAttributes(): void
    {
        $tpl = $this->src('views/templates/admin/trusteed.tpl');
        $this->assertStringContainsString('data-amcp-ajax-url="{$ajax_url|escape:\'html\'}"', $tpl);
        $this->assertStringContainsString('data-amcp-admin-token="{$admin_token|escape:\'html\'}"', $tpl);
        $this->assertStringNotContainsString('<script>', str_replace('<script src', '', $tpl));
    }

    public function testLasClavesPropiasSonLasDeclaradas(): void
    {
        $this->assertSame(
            ['TRUSTEED_PAYPAL_SYNC_ENABLED', 'TRUSTEED_PAYPAL_SYNC_HASH', 'TRUSTEED_PAYPAL_SYNC_RETRY_AT'],
            PayPalSyncService::OWN_CONFIG_KEYS
        );
    }

    public function testNingunaClaseDelSyncRegistraExcepcionesNiSecretos(): void
    {
        foreach (glob(__DIR__ . '/../../src/Service/PayPalSync/*.php') ?: [] as $f) {
            $s = (string) file_get_contents($f);
            $this->assertDoesNotMatchRegularExpression('/getMessage\s*\(/', $s, basename($f));
            $this->assertDoesNotMatchRegularExpression('/(error_log|PrestaShopLogger::addLog)\s*\(/', $s, basename($f));
        }
    }

    /** L3: el re-sync perezoso no lo dispara cualquier empleado ni recorre todas las tiendas. */
    public function testElResyncPerezosoExigePermisoYLimitaLasTiendasDelEmpleado(): void
    {
        $f = $this->src('src/Service/PayPalSync/PayPalSyncFactory.php');
        $body = substr($f, (int) strpos($f, 'function resyncOnBackOfficePage('));
        $this->assertStringContainsString('ResyncScope::shops(', $body);
        $this->assertStringContainsString('ROLE_MOD_TAB_ADMINTRUSTEED_EDIT', $f);
        $this->assertStringContainsString('getAssociatedShops', $f);
        $this->assertStringNotContainsString('Shop::getShops(true, null, true) as', $body);
    }

    public function testElServicioNoUsaLaAllowlistGeneralParaSecretos(): void
    {
        $s = $this->src('src/Service/PayPalSync/PayPalSyncService.php');
        $this->assertStringContainsString('ApiBaseAllowlist::isAllowedForSecrets(', $s);
        $this->assertStringNotContainsString('ApiBaseAllowlist::isAllowed(', $s);
    }
}
