<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use Trusteed\Discovery\UcpWellknownResolver;

/**
 * Trusteed — UCP `.well-known` discovery front controller (ADR-077).
 *
 * Reachable via:
 *   index.php?fc=module&module=trusteed&controller=ucpwellknown
 * and, when the FriendlyURL route is registered (moduleRoutes hook, gated by the
 * same flag), via `/.well-known/ucp`.
 *
 * Behavior: OPT-IN. Serves (HTTP 200, JSON) the CENTRALLY generated per-store UCP
 * discovery profile, relayed verbatim by UcpWellknownResolver::fetchBody() from
 * the merchant's own origin (a 302 to another host is not followed by every UCP
 * client). It never builds or edits the body. The fetch is https-only, refuses
 * private/reserved hosts, is cached 5 min and falls back to a stale copy; if the
 * central endpoint is down and there is no copy it keeps the previous behaviour
 * (302 to the central URL).
 *
 * Gated by TRUSTEED_UCP_WELLKNOWN_ENABLED (default OFF). When the flag is OFF or
 * no central URL is resolvable, the controller responds 404 — the route never
 * exposes new behavior unless the merchant explicitly enables it.
 */
class TrusteedUcpwellknownModuleFrontController extends ModuleFrontController
{
    /** @var bool Public storefront endpoint — no SSL coupling, no auth. */
    public $ssl = true;

    /**
     * @var bool Disable the AJAX/template machinery — we redirect or 404 only.
     */
    public $ajax = false;

    public function init(): void
    {
        parent::init();

        $shopId = 0;
        if (isset($this->context->shop) && $this->context->shop instanceof Shop) {
            $shopId = (int) $this->context->shop->id;
        }

        if (!UcpWellknownResolver::isEnabled($shopId)) {
            $this->respondNotFound();
            return;
        }

        $target = UcpWellknownResolver::targetUrl($shopId);
        if ($target === '') {
            // Fail-closed: no resolvable central URL → 404, never a bad redirect.
            $this->respondNotFound();
            return;
        }

        $body = UcpWellknownResolver::fetchBody($target, $shopId);
        if ($body !== null) {
            header('HTTP/1.1 200 OK');
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: public, max-age=300');
            echo $body;
            exit;
        }

        // Last resort: 302 (temporary) so agents re-resolve against the live central
        // manifest. Tool::redirectLink is unsafe for absolute external URLs;
        // emit the Location header directly.
        header('Location: ' . $target, true, 302);
        header('Cache-Control: no-store');
        exit;
    }

    private function respondNotFound(): void
    {
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }
}
