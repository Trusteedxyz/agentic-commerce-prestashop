<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Un veredicto NUESTRO: el chequeo del pedido decidió bloquear (una regla del
 * comerciante, un token no verificado, un servicio caído que cierra a un agente,
 * una avería propia que retiene al agente…).
 *
 * Existe para distinguirlo de las `\PrestaShopException` que lanza el núcleo de
 * PrestaShop (base de datos, objetos sin cargar…): esas NO son un veredicto, son
 * una avería, y una avería propia nunca bloquea a un humano ni abre a un agente
 * ({@see InternalErrorGuard}). Cuenta como `\PrestaShopException` para PrestaShop:
 * el pedido se revierte igual.
 *
 * Todo `throw` de bloqueo de src/ usa esta clase; un gate
 * (tests/meta/NingunBloqueoLanzaPrestaShopExceptionTest.php) falla si aparece
 * otro `new \PrestaShopException`, porque ese veredicto se leería como avería y
 * dejaría pasar a un humano que el comerciante quería parar.
 *
 * @since 2.3.2
 */
class CelVerdictException extends \PrestaShopException
{
}
