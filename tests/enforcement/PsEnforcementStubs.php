<?php

declare(strict_types=1);

/**
 * PsEnforcementStubs.php — Minimal PrestaShop kernel stubs for CEL unit tests.
 *
 * SnapshotClient.php and TokenVerifier.php check for `_PS_VERSION_` via:
 *   if (!defined('_PS_VERSION_')) { exit; }
 *
 * This bootstrap defines that constant plus the minimum PS global classes
 * referenced by the enforcement layer so that the PHP files can be
 * require_once'd without a live PrestaShop installation.
 *
 * DO NOT add WordPress stubs here — use WpEnforcementStubs.php instead.
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.0');
}
