<?php

declare(strict_types=1);

namespace Trusteed\Admin;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * SpaLoader — localiza y expone la URL del bundle de la SPA de administración.
 *
 * El bundle es el mismo que usa el plugin WordPress, compilado desde
 * `packages/wp-plugin/agenticmcpstores-for-woocommerce/admin-spa/`.
 * Se genera en `views/js/` del módulo PS con:
 *
 *   pnpm build:ps-spa        (en la raíz del monorepo)
 *   # equivale a:
 *   EMBED_STABLE_FILENAME=1 vite build \
 *     --outDir ../../../prestashop-module-agenticmcpstores/views/js \
 *     (ejecutado desde packages/wp-plugin/.../admin-spa/)
 *
 * Con EMBED_STABLE_FILENAME=1 Vite produce `admin-spa.js` (sin hash).
 * Para compatibilidad se buscan también variantes con hash `amcp-admin-*.js`.
 *
 * Rutas:
 *   - Filesystem: {module}/views/js/admin-spa.js   (o amcp-admin-*.js)
 *   - HTTP:       {module_url}views/js/admin-spa.js
 *
 * @package Trusteed\Admin
 */
class SpaLoader
{
    /** Nombre preferido del bundle (build:ps-spa con EMBED_STABLE_FILENAME=1). */
    private const BUNDLE_FILENAME = 'admin-spa.js';

    /** Patrón glob para bundles con hash generados sin EMBED_STABLE_FILENAME. */
    private const BUNDLE_GLOB = 'amcp-admin-*.js';

    /** Ruta filesystem al directorio views/js/ del módulo. */
    private string $jsDir;

    /** URL pública del directorio views/js/ del módulo. */
    private string $jsUrl;

    public function __construct(string $jsDir, string $jsUrl)
    {
        $this->jsDir = rtrim($jsDir, '/') . '/';
        $this->jsUrl = rtrim($jsUrl, '/') . '/';
    }

    /**
     * Devuelve la URL pública del bundle si el archivo existe en disco.
     *
     * Busca en orden:
     *  1. `admin-spa.js`      (build:ps-spa con EMBED_STABLE_FILENAME=1)
     *  2. `amcp-admin-*.js`   (build estándar del WP plugin, copiado manualmente)
     *
     * Devuelve null cuando ningún bundle está disponible.
     */
    public function getBundleUrl(): ?string
    {
        // Preferencia 1: nombre estable
        $path = $this->jsDir . self::BUNDLE_FILENAME;
        if (file_exists($path)) {
            return $this->jsUrl . self::BUNDLE_FILENAME . '?v=' . filemtime($path);
        }

        // Preferencia 2: primer archivo que coincide con el glob hash
        $matches = glob($this->jsDir . self::BUNDLE_GLOB);
        if (!empty($matches)) {
            return $this->jsUrl . basename($matches[0]) . '?v=' . filemtime($matches[0]);
        }

        return null;
    }

    /**
     * Devuelve true si algún bundle está disponible en disco.
     */
    public function bundleExists(): bool
    {
        return $this->getBundleUrl() !== null;
    }
}
