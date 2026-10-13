/**
 * trusteed-init.js: token relay and SPA mount for the PrestaShop back office.
 *
 * Reads its configuration from the data-amcp-* attributes of the #amcp-root
 * element, which avoids inline scripts and works with a strict
 * Content-Security-Policy.
 *
 * Loaded with defer after the SPA bundle (trusteed admin-spa.js), so
 * TrusteedEmbed may not be available yet when this script runs.
 * mountWhenReady() polls until the bundle is ready.
 *
 * CSP requirement: 'self' in script-src. No 'unsafe-inline' needed.
 */
(function () {
  "use strict";

  /**
   * Reads the configuration from the #amcp-root element's data-amcp-* attributes.
   * Returns null if the element is missing or a required attribute is absent.
   *
   * @returns {{ ajaxUrl: string, adminToken: string, apiBase: string,
   *             merchantId: string, shopId: number, shopDomain: string,
   *             locale: string } | null}
   */
  function readConfig() {
    var root = document.getElementById("amcp-root");
    if (!root) return null;

    var ajaxUrl = root.getAttribute("data-amcp-ajax-url");
    var adminToken = root.getAttribute("data-amcp-admin-token");
    var apiBase = root.getAttribute("data-amcp-api-base");
    var merchantId = root.getAttribute("data-amcp-merchant-id");
    var shopId = parseInt(root.getAttribute("data-amcp-shop-id") || "0", 10);
    var shopDomain = root.getAttribute("data-amcp-shop-domain") || "";
    var locale = root.getAttribute("data-amcp-locale") || "en";

    if (!ajaxUrl || !adminToken || !apiBase || !merchantId) return null;

    return {
      ajaxUrl: ajaxUrl,
      adminToken: adminToken,
      apiBase: apiBase,
      merchantId: merchantId,
      shopId: shopId,
      shopDomain: shopDomain,
      locale: locale,
    };
  }

  /**
   * Requests an access token from the S2S AJAX relay (PHP calls
   * /v1/embed/ps/issue-token with X-Embed-Ps-Secret; the secret never reaches
   * the browser).
   *
   * It only requests: caching is done by `TrusteedEmbed.createTokenSource`. The
   * cache that used to live here read `expires_at` (epoch seconds) as
   * milliseconds (`new Date(1790000000)` is January 1970), so it never hit and
   * every SPA call asked for a new token against a limit of 10 per minute per
   * merchant. With two tabs open, that meant 429 responses.
   *
   * @returns {Promise<{ token: string, expires_at: unknown }>}
   */
  async function psFetchToken() {
    var cfg = readConfig();
    if (!cfg) {
      throw new Error("[Trusteed] configuración no disponible en #amcp-root");
    }

    var resp = await fetch(
      cfg.ajaxUrl +
        "&ajax=1&action=token&token=" +
        encodeURIComponent(cfg.adminToken),
      { method: "POST", headers: { Accept: "application/json" } }
    );

    if (!resp.ok) {
      throw new Error("[Trusteed] token relay failed: HTTP " + resp.status);
    }

    var data = await resp.json();
    if (data.error) {
      throw new Error("[Trusteed] token error: " + data.error);
    }
    if (!data.token) {
      throw new Error("[Trusteed] No token in PS response");
    }
    return { token: data.token, expires_at: data.expires_at };
  }

  /**
   * Waits for the bundle to load and mounts the SPA.
   * Polls every 50 ms and gives up after 30 s so it never spins forever.
   */
  function mountWhenReady() {
    var attempts = 0;
    var MAX_ATTEMPTS = 600; // 600 * 50ms = 30 s

    var interval = setInterval(function () {
      attempts++;
      if (attempts > MAX_ATTEMPTS) {
        clearInterval(interval);
        console.error("[Trusteed] timeout esperando TrusteedEmbed bundle");
        return;
      }

      if (typeof window.TrusteedEmbed === "undefined") return;

      clearInterval(interval);

      var rootEl = document.getElementById("amcp-root");
      if (!rootEl) return;

      var cfg = readConfig();
      if (!cfg) {
        console.error(
          "[Trusteed] atributos data-amcp-* no encontrados en #amcp-root"
        );
        return;
      }

      // Cache shared by the tabs of this page, using the token's REAL expiry
      // and a `refresh` for the retry after a 401.
      var tokens = window.TrusteedEmbed.createTokenSource(psFetchToken);
      window.TrusteedEmbed.mount(rootEl, {
        section: rootEl.dataset.section || "inicio",
        source: "ps-embed",
        apiBase: cfg.apiBase,
        getToken: tokens.getToken,
        refreshToken: tokens.refresh,
        locale: cfg.locale,
      });
    }, 50);
  }

  document.addEventListener("DOMContentLoaded", mountWhenReady);
})();
