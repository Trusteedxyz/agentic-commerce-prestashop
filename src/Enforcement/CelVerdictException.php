<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * A verdict of OURS: the order check decided to block (a merchant rule, an
 * unverified token, an unavailable service that closes on an agent, an internal
 * fault that holds the agent back, and so on).
 *
 * It exists to tell it apart from the `\PrestaShopException`s thrown by the
 * PrestaShop core (database, unloaded objects...): those are NOT a verdict, they
 * are a fault, and an internal fault never blocks a human nor opens up to an
 * agent ({@see InternalErrorGuard}). It counts as a `\PrestaShopException` for
 * PrestaShop, so the order is rolled back all the same.
 *
 * Every blocking `throw` in src/ uses this class; a gate
 * (tests/meta/NingunBloqueoLanzaPrestaShopExceptionTest.php) fails if another
 * `new \PrestaShopException` appears, because that verdict would be read as a
 * fault and let through a human the merchant wanted to stop.
 *
 * @since 2.3.2
 */
class CelVerdictException extends \PrestaShopException
{
}
