<?php

declare(strict_types=1);

/**
 * AdminTrusteedPaymentMethodsController — entrada «Métodos de pago» del menú.
 *
 * Hasta 2.3.2 «payment-methods» sólo se alcanzaba como pestaña de Merchant Center.
 * El menú unificado (diseño 2026-10-03 §4) lo promueve a entrada propia; la SPA ya
 * conoce la sección y `AdminTrusteedController::ALLOWED_SECTIONS` ya la admite, así
 * que este controlador no añade lógica: sólo fija la sección por defecto, igual que
 * los demás AdminTrusteed*Controller.
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
