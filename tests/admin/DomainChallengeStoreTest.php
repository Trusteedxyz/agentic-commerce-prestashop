<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\DomainChallengeStore;

/**
 * El token que se publica en `.well-known/amcp-verify.txt` — auditoría de
 * onboarding 2026-09-09, hueco A3.
 *
 * El backend emite un reto cuando la URL de la tienda ya está registrada con
 * otra `install_key`; el módulo lo publica para demostrar que controla el
 * dominio. Esta clase guarda ese token entre las dos peticiones (la que lo
 * recibe y la del verificador de Trusteed, que llega desde fuera).
 *
 * La lógica va aparte del front controller por la misma razón que
 * `SetupStatus`: un front controller de PrestaShop no se puede ejercitar sin
 * levantar medio framework, y lo que hay que fijar aquí —qué se sirve y
 * cuándo se deja de servir— es lógica pura.
 */
final class DomainChallengeStoreTest extends TestCase
{
    private const TOKEN = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

    public function testNoSirveNadaSinRetoGuardado(): void
    {
        $store = new DomainChallengeStore('', 0);

        $this->assertFalse($store->isServable());
        $this->assertSame('', $store->body());
    }

    public function testSirveElTokenTalCualCuandoEstaVigente(): void
    {
        // El verificador compara el contenido COMPLETO en tiempo constante: si
        // aquí se colase un salto de línea o una etiqueta, la comprobación
        // fallaría y el comerciante no sabría por qué.
        $store = new DomainChallengeStore(self::TOKEN, time());

        $this->assertTrue($store->isServable());
        $this->assertSame(self::TOKEN, $store->body());
    }

    public function testDejaDeServirloCuandoCaduca(): void
    {
        // El reto del backend vive 10 minutos. Seguir publicando el token
        // después deja un secreto expuesto sin ninguna utilidad.
        $store = new DomainChallengeStore(self::TOKEN, time() - 3600);

        $this->assertFalse($store->isServable());
        $this->assertSame('', $store->body());
    }

    public function testRechazaUnTokenConFormaInvalida(): void
    {
        // Lo que se guarde acaba servido en la raíz del dominio: si no tiene la
        // forma exacta que emite el backend, no viene del backend.
        foreach (['', 'corto', self::TOKEN . 'x', '<script>', str_repeat('Z', 64)] as $malo) {
            $store = new DomainChallengeStore($malo, time());
            $this->assertFalse(
                $store->isServable(),
                'aceptó un token con forma inválida: ' . var_export($malo, true)
            );
        }
    }

    public function testUnaMarcaDeTiempoFuturaNoAlargaLaVida(): void
    {
        // Un reloj adelantado o una escritura manipulada no deben conceder una
        // ventana mayor que la real.
        $store = new DomainChallengeStore(self::TOKEN, time() + 86400);

        $this->assertFalse($store->isServable());
    }

    public function testLaVentanaCubreElPlazoDelBackendConMargen(): void
    {
        // El reto del backend caduca a los 10 minutos; publicar 9 tiene que
        // seguir funcionando o el canje falla por nuestra propia ventana.
        $store = new DomainChallengeStore(self::TOKEN, time() - 9 * 60);

        $this->assertTrue($store->isServable());
    }
}
