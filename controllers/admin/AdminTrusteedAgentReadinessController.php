<?php

declare(strict_types=1);

/**
 * Spec 065 F1 — Dashboard Agent-Friendly.
 *
 * Monta la misma SPA embebida en la sección `agent-readiness`.
 * Un solo build sirve a los cuatro hosts (WooCommerce, PrestaShop, Magento, Odoo).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/AdminTrusteedController.php';

class AdminTrusteedAgentReadinessController extends AdminTrusteedController
{
    protected string $defaultSection = 'agent-readiness';
}
