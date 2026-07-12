<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/AdminTrusteedController.php';

class AdminTrusteedVentasController extends AdminTrusteedController
{
    protected string $defaultSection = 'mis-ventas';
}
