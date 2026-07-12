{*
 * trusteed.tpl — Shell de la SPA Trusteed para el back office de PrestaShop.
 *
 * Variables asignadas por AdminTrusteedController::initContent():
 *   $bundle_url   string|null  URL del bundle JS compilado
 *   $bundle_ok    bool         True si el bundle existe en disco
 *   $section      string       Sección inicial (inicio | trust-center | ...)
 *   $ajax_url     string       URL base del controlador admin (sin token)
 *   $admin_token  string       PS admin token para el AJAX endpoint
 *   $merchant_id  string       UUID del merchant Trusteed
 *   $api_base     string       Base URL de la API Trusteed
 *   $shop_id      int          ID de la tienda PS activa
 *   $shop_domain  string       Dominio de la tienda PS activa
 *   $configured   bool         True cuando las credenciales están completas
 *
 * SEGURIDAD: Ningún token o secreto se pasa al DOM.
 * Los tokens sólo se emiten en memoria JS (constraint C-003).
 *
 * CSP: Sin inline scripts — config inyectada como data-* attributes.
 * El archivo trusteed-init.js (defer) lee los atributos y monta la SPA.
 *}

<div id="trusteed-admin-wrapper" style="margin: 0; padding: 0;">

  {if !$bundle_ok}
    {* Aviso de bundle no compilado — sólo visible durante desarrollo *}
    <div class="alert alert-warning" style="margin: 20px;">
      <strong>Trusteed:</strong>
      El bundle de la SPA no está compilado. Ejecuta
      <code>pnpm --filter trusteed build:spa</code>
      desde la raíz del monorepo y recarga esta página.
    </div>
  {elseif !$configured}
    {* Aviso de configuración incompleta *}
    <div class="alert alert-info" style="margin: 20px;">
      <strong>Trusteed:</strong>
      Configura el <em>Merchant ID</em> y el <em>Embed S2S Secret</em>
      en la sección
      <a href="{$link->getAdminLink('AdminModules')|escape:'html'}&configure=trusteed">
        Configuración del módulo
      </a>
      para activar el panel.
    </div>
  {else}
    {*
      Configuración pública (sin secretos) expuesta como data-* attributes.
      Los tokens sólo fluyen por JS vía AJAX relay S2S — nunca al DOM estático.
      Formato de atributos: data-amcp-{campo} para namespace claro.
    *}
    <div
      id="amcp-root"
      data-section="{$section|escape:'html'}"
      data-auto-mount="false"
      data-amcp-ajax-url="{$ajax_url|escape:'html'}"
      data-amcp-admin-token="{$admin_token|escape:'html'}"
      data-amcp-api-base="{$api_base|escape:'html'}"
      data-amcp-merchant-id="{$merchant_id|escape:'html'}"
      data-amcp-shop-id="{$shop_id|intval}"
      data-amcp-shop-domain="{$shop_domain|escape:'html'}"
      data-amcp-locale="{$ps_locale|escape:'html'}"
      style="min-height: 400px;"
    >
      <div style="padding: 20px; color: #666; font-family: sans-serif;">
        Cargando Trusteed…
      </div>
    </div>

    {* Bundle SPA — carga TrusteedEmbed global *}
    <script src="{$bundle_url|escape:'html'}" defer></script>

    {* Init externo — lee data-* y monta la SPA (sin inline scripts = CSP-safe) *}
    <script src="{$init_url|escape:'html'}" defer></script>
  {/if}

</div>
