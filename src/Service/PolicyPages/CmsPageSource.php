<?php

declare(strict_types=1);

namespace Trusteed\Service\PolicyPages;

/** Lectura de páginas CMS de una tienda. Interfaz para probar sin el kernel de PrestaShop. */
interface CmsPageSource
{
    /**
     * Página CMS activa en el idioma por defecto de la tienda, o null si no existe o no está activa.
     *
     * @return array{url:string,title:string,modified:string}|null `modified` = `date_upd` crudo de PS.
     */
    public function page(int $idCms, int $idShop): ?array;

    /** Host público (sin esquema ni puerto) de la tienda. */
    public function shopHost(int $idShop): string;

    /** Zona horaria en la que PS guarda `date_upd`. */
    public function timezone(): string;

    /**
     * Páginas CMS activas para el selector: [idCms => título].
     *
     * @return array<int,string>
     */
    public function activePages(int $idShop): array;
}
