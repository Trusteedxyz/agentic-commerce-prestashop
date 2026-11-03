<?php

declare(strict_types=1);

/**
 * tests/bootstrap.php — PHPUnit bootstrap for the PrestaShop module.
 *
 * Goals (spec-046 H1 de-tautologization):
 *   1. Register the production PSR-4 autoloader (`Trusteed\` → src/) so tests
 *      exercise the REAL classes, not inline mock re-implementations.
 *   2. Stub the php-mcp/server attribute classes (`PhpMcp\Server\Attributes\McpTool`
 *      and `Schema`) when the SDK is absent, so the REAL tool classes — which
 *      declare `#[McpTool]` / `#[Schema]` attributes — load and can be
 *      reflected without the marketplace add-on installed.
 *   3. Define a minimal `_PS_VERSION_` so files guarded by it can be required.
 *
 * The attribute stubs are functional no-ops that capture their constructor
 * arguments, enabling reflection-based assertions on `name` / `properties`.
 */

// 1. Production autoloader for Trusteed\ classes.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Trusteed\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// 3. Minimal PS marker (the AgenticTools src does not hard-require it, but other
//    shared stubs may).
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.0');
}

// 2. php-mcp/server attribute stubs (only when the real SDK is not present).
if (!class_exists('PhpMcp\\Server\\Attributes\\McpTool')) {
    require __DIR__ . '/stubs/PhpMcpAttributeStubs.php';
}

// 4. PrestaShop global stubs (Context / Configuration) so the REAL payment tools
//    can run MerchantContextGuard::enforce() under test.
require __DIR__ . '/stubs/PsContextStubs.php';
