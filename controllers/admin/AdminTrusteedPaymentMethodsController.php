<?php

declare(strict_types=1);

/**
 * AdminTrusteedPaymentMethodsController: "Payment methods" menu entry.
 *
 * Up to 2.3.2 "payment-methods" was only reachable as a Merchant Center tab.
 * The unified menu promotes it to its own entry. The SPA already knows the
 * section and `AdminTrusteedController::ALLOWED_SECTIONS` already allows it, so
 * this controller adds no logic: it only sets the default section, like the
 * other AdminTrusteed*Controller classes.
 *
 * @package Trusteed
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/AdminTrusteedController.php';

class AdminTrusteedPaymentMethodsController extends AdminTrusteedController
{
    protected string $defaultSection = 'payment-methods';
}
