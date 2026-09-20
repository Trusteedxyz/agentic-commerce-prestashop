<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Admin\SetupStatus;

/**
 * Los tres estados del alta — auditoría de onboarding 2026-09-09, huecos A4 y A2.
 *
 * ## Los dos fallos que fija esta suite
 *
 * **A4**: `hookDisplayBackOfficeTop` devolvía cadena vacía cuando no había
 * `merchantId`, o sea que el ÚNICO indicador visual del módulo aparecía sólo
 * después de configurarlo. Instalar y no terminar no producía ninguna señal:
 * el comerciante creía haber acabado porque nada le decía lo contrario.
 *
 * **A2**: `handleAutoRegister()` guarda 2 de las 5 claves que el módulo usa, y
 * anunciaba «Credentials configured automatically». Las otras tres —entre ellas
 * `TRUSTEED_CEL_INSTALLATION_ID` y `TRUSTEED_CEL_HMAC_SECRET`, sin las cuales no
 * se evalúa ni una regla— no tienen escritor automático en todo el módulo.
 *
 * La lógica vive aparte del hook a propósito: el hook pinta HTML y toca
 * `Configuration`, y una decisión de tres estados enterrada ahí no se puede
 * probar sin levantar medio PrestaShop.
 */
final class SetupStatusTest extends TestCase
{
    public function testSinMerchantIdEstaSinConfigurar(): void
    {
        $status = new SetupStatus('', '', '');

        $this->assertSame(SetupStatus::STATE_NOT_CONFIGURED, $status->state());
        $this->assertTrue($status->needsAttention());
    }

    public function testSoloEspaciosCuentaComoSinConfigurar(): void
    {
        // Un `!== ''` ingenuo deja pasar "   ", que no identifica ninguna tienda.
        $status = new SetupStatus('   ', '', '');

        $this->assertSame(SetupStatus::STATE_NOT_CONFIGURED, $status->state());
    }

    public function testConMerchantIdPeroSinCredencialesDeEnforcement(): void
    {
        // El estado real tras pulsar «Auto-registrar esta tienda».
        $status = new SetupStatus('mrc_123', '', '');

        $this->assertSame(SetupStatus::STATE_ENFORCEMENT_INCOMPLETE, $status->state());
        $this->assertTrue($status->needsAttention());
    }

    public function testUnInstallationIdSinSecretoSigueSiendoIncompleto(): void
    {
        // Sin secreto no se puede firmar: /v1/rules/evaluate rechaza la llamada
        // y el evaluador degrada a «dejar pasar» por la otra puerta. Desde
        // fuera es indistinguible de no tener nada.
        $status = new SetupStatus('mrc_123', 'inst_abc', '');

        $this->assertSame(SetupStatus::STATE_ENFORCEMENT_INCOMPLETE, $status->state());
    }

    public function testUnSecretoSinInstallationIdSigueSiendoIncompleto(): void
    {
        $status = new SetupStatus('mrc_123', '', 'shhh');

        $this->assertSame(SetupStatus::STATE_ENFORCEMENT_INCOMPLETE, $status->state());
    }

    public function testConLasTresEstaCompleto(): void
    {
        $status = new SetupStatus('mrc_123', 'inst_abc', 'shhh');

        $this->assertSame(SetupStatus::STATE_READY, $status->state());
        $this->assertFalse($status->needsAttention());
    }

    public function testCadaEstadoTieneSuPropioColorYNoSeRepiten(): void
    {
        // Si «falta configurar» y «listo» se pintan igual, el badge vuelve a no
        // decir nada — que es exactamente el fallo A4 con otra forma.
        $colores = [
            (new SetupStatus('', '', ''))->badgeColor(),
            (new SetupStatus('mrc_123', '', ''))->badgeColor(),
            (new SetupStatus('mrc_123', 'i', 's'))->badgeColor(),
        ];

        $this->assertCount(3, array_unique($colores));
    }

    public function testLaClaveDeTraduccionEsDistintaPorEstado(): void
    {
        // El hook traduce por clave; dos estados compartiendo clave darían el
        // mismo texto al comerciante.
        $claves = [
            (new SetupStatus('', '', ''))->labelKey(),
            (new SetupStatus('mrc_123', '', ''))->labelKey(),
            (new SetupStatus('mrc_123', 'i', 's'))->labelKey(),
        ];

        $this->assertCount(3, array_unique($claves));
        foreach ($claves as $clave) {
            $this->assertNotSame('', $clave);
        }
    }

    public function testSoloElEstadoCompletoSeConsideraProtegido(): void
    {
        $this->assertFalse((new SetupStatus('', '', ''))->enforcementActive());
        $this->assertFalse((new SetupStatus('mrc_123', '', ''))->enforcementActive());
        $this->assertTrue((new SetupStatus('mrc_123', 'i', 's'))->enforcementActive());
    }
}
