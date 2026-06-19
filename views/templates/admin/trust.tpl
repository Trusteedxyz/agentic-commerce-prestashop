{*
 * Trusteed Trust Center — MVP SPA mount template.
 * Bundle loaded from views/js/admin-spa.js (built via pnpm build:ps from admin-spa).
 *
 * S042-005: data-merchant-id removed — SPA derives tenant identity from access token.
 * S042-003: data-auto-mount="false" prevents WP-default auto-mount; the external
 *           CSP-safe bootstrap (views/js/trusteed-init.js) fetches a PS opaque token
 *           (/v1/embed/ps/issue-token relay) and mounts with source="ps-embed" +
 *           getToken (proactive TTL refresh).
 *
 * CSP-safe: NO inline <script>. Every Smarty-injected value (CSRF token, AJAX
 *           endpoint) is exposed via data-* attributes and read by trusteed-init.js
 *           through element.dataset — so a strict CSP that forbids inline JS works.
 *}
<div id="amcp-root"
     data-section="trust-center"
     data-employee-id="{$employee_id|intval}"
     data-id-shop="{$id_shop|intval}"
     data-api-base="{$api_base|escape:'htmlall':'UTF-8'}"
     data-csrf-token="{$csrf_token|escape:'htmlall':'UTF-8'}"
     data-token-endpoint="{$link->getAdminLink('AdminAgenticTrust')|escape:'htmlall':'UTF-8'}&ajax=1&action=issueBootstrap"
     data-auto-mount="false">
</div>

<script src="{$module_dir|escape:'htmlall':'UTF-8'}views/js/admin-spa.js"></script>
<script src="{$module_dir|escape:'htmlall':'UTF-8'}views/js/trusteed-init.js"></script>
