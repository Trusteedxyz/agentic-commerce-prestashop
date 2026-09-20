<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Trusteed\Service\DomainChallengeStore;

/**
 * Trusteed — publica el token del reto de dominio en
 * `/.well-known/amcp-verify.txt`. Auditoría de onboarding 2026-09-09, hueco A3.
 *
 * Alcanzable por:
 *   index.php?fc=module&module=trusteed&controller=amcpverify
 * y, cuando hay un reto vigente, por `/.well-known/amcp-verify.txt` gracias a
 * la ruta que registra `hookModuleRoutes`.
 *
 * ## Qué demuestra
 *
 * Que quien pide recuperar la tienda controla el dominio. `auto-register` no
 * prueba propiedad en el alta nueva, así que la primera petición que llegue
 * con una URL se la queda; sin esto, el dueño real recibía
 * `409 STORE_ALREADY_REGISTERED` para siempre y no había vía de vuelta.
 *
 * ## Sólo mientras hay reto
 *
 * Sin token vigente responde 404 — no publica un fichero vacío ni deja rastro
 * de que el módulo esté instalado. `DomainChallengeStore` decide qué es
 * «vigente» y tiene tests; aquí no hay lógica que probar.
 *
 * No hay salida de red: este controlador no llama a nadie, sólo imprime. Es el
 * verificador de Trusteed quien viene a leerlo.
 */
class TrusteedAmcpverifyModuleFrontController extends ModuleFrontController
{
    /** @var bool Endpoint público de escaparate — sin sesión ni autenticación. */
    public $ssl = true;

    /** @var bool Sin plantillas: se imprime texto plano y se sale. */
    public $ajax = false;

    public function init(): void
    {
        parent::init();

        $store = new DomainChallengeStore(
            (string) Configuration::get(DomainChallengeStore::CONFIG_TOKEN),
            (int) Configuration::get(DomainChallengeStore::CONFIG_ISSUED_AT)
        );

        if (!$store->isServable()) {
            header('HTTP/1.1 404 Not Found');
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Not Found';
            exit;
        }

        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain; charset=utf-8');
        // Nunca cacheado: el token es efímero y de un solo uso, y una copia en
        // un CDN lo mantendría vivo después de caducar.
        header('Cache-Control: no-store');
        echo $store->body();
        exit;
    }
}
