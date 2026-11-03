<?php

declare(strict_types=1);

namespace Trusteed\Tests\PayPalSync;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\PayPalSync\ResyncScope;

final class ResyncScopeTest extends TestCase
{
    public function testSinPermisoNoHayTiendas(): void
    {
        $this->assertSame([], ResyncScope::shops(false, [1, 2, 3], [1, 2, 3]));
    }

    public function testConPermisoSoloLasTiendasDelEmpleado(): void
    {
        $this->assertSame([2, 3], ResyncScope::shops(true, [1, 2, 3], [2, 3, 9]));
    }

    public function testEmpleadoSinTiendasAsociadasNoRecorreNinguna(): void
    {
        $this->assertSame([], ResyncScope::shops(true, [1, 2], []));
    }

    public function testDescartaIdsInvalidosYDuplicados(): void
    {
        $this->assertSame([2], ResyncScope::shops(true, [0, 2, 2, -1], [2, 0]));
    }
}
