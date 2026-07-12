/**
 * trusteed-init.js — Token relay + SPA mount para el back office PrestaShop.
 *
 * Lee la configuración desde los atributos data-amcp-* del elemento #amcp-root,
 * evitando inline scripts para compatibilidad con Content-Security-Policy estricta.
 *
 * Cargado con defer después del bundle SPA (trusteed admin-spa.js), por lo que
 * TrusteedEmbed puede no estar disponible aún cuando este script se ejecuta.
 * mountWhenReady() sondea hasta que el bundle esté listo.
 *
 * CSP requirement: 'self' en script-src — sin 'unsafe-inline' necesario.
 */
(function () {
  "use strict";

  var EXPIRY_BUFFER_MS = 30000; // 30 s de margen antes de que expire el token

  var tokenCache = null;
  var tokenExpiresAt = 0;

  /**
   * Lee la configuración del elemento #amcp-root via data-amcp-* attributes.
   * Devuelve null si el elemento no existe o faltan atributos obligatorios.
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
   * Obtiene un access token Ed25519 vía relay AJAX S2S.
   *
   * PHP hace el relay S2S a /v1/embed/ps/issue-token con X-Embed-Ps-Secret.
   * El secreto nunca sale al navegador (constraint C-003).
   *
   * @returns {Promise<string>}
   */
  async function psGetToken() {
    var now = Date.now();
    if (tokenCache !== null && now < tokenExpiresAt - EXPIRY_BUFFER_MS) {
      return tokenCache;
    }

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

    tokenCache = data.token;
    tokenExpiresAt = data.expires_at
      ? new Date(data.expires_at).getTime()
      : Date.now() + 300 * 1000;
    return tokenCache;
  }

  /**
   * Espera a que el bundle esté cargado y monta la SPA.
   * Sondea cada 50 ms; abort tras 30 s para no bloquear el navegador indefinidamente.
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

      window.TrusteedEmbed.mount(rootEl, {
        section: rootEl.dataset.section || "inicio",
        source: "ps-embed",
        apiBase: cfg.apiBase,
        getToken: psGetToken,
        locale: cfg.locale,
      });
    }, 50);
  }

  document.addEventListener("DOMContentLoaded", mountWhenReady);
})();
