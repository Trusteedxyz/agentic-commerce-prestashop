<?php

declare(strict_types=1);

/**
 * tests/bootstrap.php — PHPUnit bootstrap for the ps-module-trusteed
 * (the REAL "Trust Center" module: class `Trusteed`).
 *
 * No Composer / vendor dir ships with this module (it is a pure-PHP PrestaShop
 * add-on), so the CI-resident unit suite cannot rely on a generated autoloader.
 * This bootstrap registers a minimal PSR-4 loader so the tests exercise the
 * REAL production classes — chiefly `Trusteed\Mvp\TokenBroker`
 * (classes/TokenBroker.php) — instead of inline mock re-implementations.
 *
 * Mapping:
 *   Trusteed\Mvp\<Name>  →  classes/<Name>.php
 *
 * `classes/TokenBroker.php` declares namespace `Trusteed\Mvp` and is the
 * only network-free, PrestaShop-independent class, which is exactly why it is the
 * CI-resident real test (TokenBrokerTest.php). Anything that needs live PS
 * globals (Configuration, Db, Module::install/uninstall) is guarded with
 * markTestSkipped() — see InstallTest.php.
 */

spl_autoload_register(static function (string $class): void {
    $prefix  = 'Trusteed\\Mvp\\';
    $baseDir = __DIR__ . '/../classes/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path     = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// Minimal PrestaShop version marker for any file guarded by _PS_VERSION_.
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.0');
}
