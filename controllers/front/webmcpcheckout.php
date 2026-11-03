<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Trusteed\Enforcement\BrowserAgentSignature;
use Trusteed\Enforcement\PsWebmcpCart;
use Trusteed\Enforcement\WebmcpCheckout;
use Trusteed\Storage\CartAgentStorage;

/**
 * Trusteed — endpoint WebMCP del checkout (POST JSON `{tool, arguments}`).
 * index.php?fc=module&module=trusteed&controller=webmcpcheckout
 *
 * Toda la lógica vive en {@see WebmcpCheckout} (con tests); aquí sólo se extraen petición y entorno.
 * Ajuste `TRUSTEED_WEBMCP_CHECKOUT_TOOLS` apagado (defecto) = 404.
 */
class TrusteedWebmcpcheckoutModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $ajax = true;

    public function init(): void
    {
        parent::init();

        $shopId  = isset($this->context->shop) ? (int) $this->context->shop->id : 0;
        $enabled = WebmcpCheckout::isOn((string) Configuration::get(WebmcpCheckout::CONFIG, null, null, $shopId > 0 ? $shopId : null));
        $raw     = (string) file_get_contents('php://input', false, null, 0, WebmcpCheckout::MAX_BODY_BYTES + 1);
        $header  = static fn (string $name): ?string => isset($_SERVER[$name]) && is_string($_SERVER[$name]) ? $_SERVER[$name] : null;

        $handler = new WebmcpCheckout(
            new PsWebmcpCart($this->context->cart ?? null),
            static fn (int $cartId): bool => CartAgentStorage::markChannel($cartId, 'webmcp'),
            static function () use ($shopId): void {
                (new BrowserAgentSignature())->resolveFromServer($_SERVER, $shopId);
            }
        );

        [$code, $body] = $handler->handle(
            $enabled,
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $header('HTTP_X_TRUSTEED_TOKEN'),
            (string) Tools::getToken(false),
            $header('HTTP_ORIGIN'),
            (string) Tools::getShopDomainSsl(true),
            $raw
        );

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
