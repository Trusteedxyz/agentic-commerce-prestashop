<?php

declare(strict_types=1);

/**
 * post-install.php — PostInstall hook for the Trusteed PrestaShop module.
 *
 * The PrestaShop MCP Server add-on (96617) maintains a discovery cache at
 * .mcp/.cache.json inside the PS root.  After installing or updating this
 * module the cache must be invalidated so the add-on re-discovers the newly
 * registered AgenticTools on next request.
 *
 * This file is invoked by the module's install() / upgrade() hooks.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

(static function (): void {
    $cacheFile = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . '.mcp' . DIRECTORY_SEPARATOR . '.cache.json';

    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
})();
