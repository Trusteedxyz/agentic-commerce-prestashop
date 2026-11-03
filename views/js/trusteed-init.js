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
   * Pide un access token al relay AJAX S2S (PHP → /v1/embed/ps/issue-token con
   * X-Embed-Ps-Secret; el secreto nunca sale al navegador, C-003).
   *
   * Sólo PIDE: la caché la lleva `TrusteedEmbed.createTokenSource`. B13
   * (auditoría 2026-09-26): la caché que vivía aquí leía `expires_at` —segundos
   * epoch— como milisegundos (`new Date(1790000000)` es enero de 1970), así que
   * no valía nunca y cada llamada de la SPA pedía un token nuevo contra un
   * límite de 10 por minuto y comerciante. Con dos pestañas, 429.
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

      // Acciones AJAX del controlador admin que la SPA llama directamente (hoy:
      // tarjeta «Usar la cuenta PayPal de PrestaShop»: paypalSyncStatus y
      // paypalSync). Es la URL del controlador y el token de admin de PS; ningún
      // secreto de PayPal ni de Trusteed (C-003). Antes de montar la SPA.
      window.__AMCP_PS_CONFIG__ = {
        ajaxUrl: cfg.ajaxUrl,
        adminToken: cfg.adminToken,
        apiBase: cfg.apiBase,
        locale: cfg.locale,
      };

      // Caché compartida por las pestañas de esta página, con la caducidad
      // REAL del token y `refresh` para el reintento tras un 401.
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
