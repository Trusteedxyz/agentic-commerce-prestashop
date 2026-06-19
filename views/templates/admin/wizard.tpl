{*
 * Trusteed Quick Setup Wizard — 4-step onboarding template.
 *
 * Smarty variables injected by AdminAgenticWizardController::initContent():
 *   {$csrf_token}     string  — CSRF token for AJAX handlers
 *   {$is_main_shop}   bool    — whether current employee is on main shop
 *   {$is_configured}  bool    — true when credentials are already saved
 *   {$merchant_id}    string  — current stored merchant_id (may be empty)
 *   {$api_base}       string  — API base URL
 *   {$controller}     string  — 'AdminAgenticWizard' (for AJAX URL building)
 *   {$shop_base_url}  string  — store public URL (auto-detected)
 *   {$shop_name}      string  — PS_SHOP_NAME
 *   {$admin_email}    string  — logged-in employee email
 *   {$has_install_key} bool   — true when store was previously auto-registered
 *
 * Step 2 calls POST /v1/embed/ps/auto-register — no portal account required.
 *
 * Uses Bootstrap 4 (bundled with PS 8 back-office — $this->bootstrap = true).
 * Vanilla JS — no external dependencies.
 *}

<div id="agenticmcp-wizard" class="panel" style="max-width:680px;margin:24px auto;">

  {* ─── Already configured banner ────────────────────────────────────────── *}
  {if $is_configured}
    <div class="alert alert-success">
      <strong>{l s='Trusteed is already configured.' mod='trusteed'}</strong>
      {l s='You can re-run this wizard to update your credentials.' mod='trusteed'}
    </div>
  {/if}

  {* ─── Progress bar ──────────────────────────────────────────────────────── *}
  <div style="margin-bottom:32px;">
    <div class="row text-center" id="amcpw-steps-labels">
      <div class="col-xs-3 amcpw-step-label active" data-step="1">
        <span class="amcpw-step-circle">1</span><br>
        <small>{l s='Welcome' mod='trusteed'}</small>
      </div>
      <div class="col-xs-3 amcpw-step-label" data-step="2">
        <span class="amcpw-step-circle">2</span><br>
        <small>{l s='Connect' mod='trusteed'}</small>
      </div>
      <div class="col-xs-3 amcpw-step-label" data-step="3">
        <span class="amcpw-step-circle">3</span><br>
        <small>{l s='Test' mod='trusteed'}</small>
      </div>
      <div class="col-xs-3 amcpw-step-label" data-step="4">
        <span class="amcpw-step-circle">4</span><br>
        <small>{l s='Done' mod='trusteed'}</small>
      </div>
    </div>
    <div class="progress" style="height:6px;margin-top:8px;">
      <div id="amcpw-progress-bar" class="progress-bar bg-primary" role="progressbar"
           style="width:25%"></div>
    </div>
  </div>

  {* ─── Step 1: Welcome ───────────────────────────────────────────────────── *}
  <div class="amcpw-step" id="amcpw-step-1">
    <h3 class="panel-title" style="margin-bottom:16px;">
      {l s='Welcome to Trusteed' mod='trusteed'}
    </h3>
    <p>
      {l s='This wizard will connect your PrestaShop store to the Trusteed Trust Center in 4 quick steps.' mod='trusteed'}
    </p>

    <div class="alert alert-info">
      <strong>{l s='Before you begin, please confirm:' mod='trusteed'}</strong>
      <ul class="amcpw-checklist" style="margin-top:8px;padding-left:20px;">
        <li>
          <label>
            <input type="checkbox" class="amcpw-check"> &nbsp;
            {l s='Your store has outbound HTTPS access to api.trusteed.xyz' mod='trusteed'}
          </label>
        </li>
        {if !$is_main_shop}
          <li class="text-warning">
            <span class="icon-warning-sign"></span>
            {l s='Note: you are on a sub-shop. Some settings are shared with the main shop.' mod='trusteed'}
          </li>
        {/if}
        <li>
          <label>
            <input type="checkbox" class="amcpw-check"> &nbsp;
            {if $is_main_shop}
              {l s='You have Super Admin privileges on this PrestaShop installation.' mod='trusteed'}
            {else}
              {l s='You have access to Back Office module configuration.' mod='trusteed'}
            {/if}
          </label>
        </li>
      </ul>
    </div>

    <div class="text-right" style="margin-top:24px;">
      <button type="button" class="btn btn-primary amcpw-next" data-next="2">
        {l s='Get started' mod='trusteed'} &rarr;
      </button>
    </div>
  </div>

  {* ─── Step 2: Auto-connect ─────────────────────────────────────────────── *}
  <div class="amcpw-step hidden" id="amcpw-step-2">
    <h3 class="panel-title" style="margin-bottom:16px;">
      {l s='Connect Your Store' mod='trusteed'}
    </h3>

    <p>
      {l s='Click the button below to connect your store to Trusteed automatically. No account creation required.' mod='trusteed'}
    </p>

    <div class="well" style="background:#f8f8f8;border-radius:4px;padding:12px 16px;margin-bottom:16px;">
      <div class="row">
        <div class="col-xs-3 text-right" style="font-weight:bold;padding-top:6px;">
          {l s='Store:' mod='trusteed'}
        </div>
        <div class="col-xs-9" style="padding-top:6px;word-break:break-all;">
          {$shop_base_url|escape:'htmlall':'UTF-8'}
        </div>
      </div>
      <div class="row" style="margin-top:6px;">
        <div class="col-xs-3 text-right" style="font-weight:bold;padding-top:6px;">
          {l s='Name:' mod='trusteed'}
        </div>
        <div class="col-xs-9" style="padding-top:6px;">
          {$shop_name|escape:'htmlall':'UTF-8'}
        </div>
      </div>
      {if $admin_email}
      <div class="row" style="margin-top:6px;">
        <div class="col-xs-3 text-right" style="font-weight:bold;padding-top:6px;">
          {l s='Email:' mod='trusteed'}
        </div>
        <div class="col-xs-9" style="padding-top:6px;">
          {$admin_email|escape:'htmlall':'UTF-8'}
        </div>
      </div>
      {/if}
    </div>

    {if $has_install_key}
      <div class="alert alert-warning">
        <span class="icon-warning-sign"></span>
        {l s='This store was previously registered. Clicking Connect will rotate your credentials.' mod='trusteed'}
      </div>
    {/if}

    <div id="amcpw-autoreg-alert" class="hidden"></div>

    <div class="text-right" style="margin-top:24px;">
      <button type="button" class="btn btn-default amcpw-prev" data-prev="1">
        &larr; {l s='Back' mod='trusteed'}
      </button>
      &nbsp;
      <button type="button" id="amcpw-autoreg-btn" class="btn btn-primary btn-lg">
        <span id="amcpw-autoreg-label">
          <span class="icon-plug"></span>
          {l s='Connect to Trusteed' mod='trusteed'}
        </span>
        <span id="amcpw-autoreg-spinner" class="hidden">
          <span class="icon-spinner icon-spin"></span>
          {l s='Connecting…' mod='trusteed'}
        </span>
      </button>
    </div>
  </div>

  {* ─── Step 3: Test connection ───────────────────────────────────────────── *}
  <div class="amcpw-step hidden" id="amcpw-step-3">
    <h3 class="panel-title" style="margin-bottom:16px;">
      {l s='Test Your Connection' mod='trusteed'}
    </h3>
    <p>
      {l s='Click the button below to verify that your store can communicate with the Trusteed API.' mod='trusteed'}
    </p>

    <div id="amcpw-test-alert" class="hidden"></div>

    <div style="margin-top:24px;">
      <button type="button" id="amcpw-test-btn" class="btn btn-success">
        <span id="amcpw-test-label">
          <span class="icon-check"></span>
          {l s='Test connection' mod='trusteed'}
        </span>
        <span id="amcpw-test-spinner" class="hidden">
          <span class="icon-spinner icon-spin"></span>
          {l s='Connecting…' mod='trusteed'}
        </span>
      </button>
    </div>

    <div id="amcpw-test-success" class="hidden" style="margin-top:24px;">
      <div class="alert alert-success">
        <strong><span class="icon-check-circle"></span>
          {l s='Connection successful!' mod='trusteed'}
        </strong>
        <p id="amcpw-test-detail"></p>
      </div>
      <div class="text-right">
        <button type="button" class="btn btn-primary amcpw-next" data-next="4">
          {l s='Continue' mod='trusteed'} &rarr;
        </button>
      </div>
    </div>

    <div id="amcpw-test-fail" class="hidden" style="margin-top:24px;">
      <div class="alert alert-danger">
        <strong><span class="icon-times-circle"></span>
          {l s='Connection failed.' mod='trusteed'}
        </strong>
        <p id="amcpw-test-fail-detail"></p>
      </div>
      <div class="text-right">
        <button type="button" class="btn btn-default amcpw-prev" data-prev="2">
          &larr; {l s='Try again' mod='trusteed'}
        </button>
      </div>
    </div>
  </div>

  {* ─── Step 4: Done ──────────────────────────────────────────────────────── *}
  <div class="amcpw-step hidden" id="amcpw-step-4">
    <h3 class="panel-title" style="margin-bottom:16px;">
      {l s='Setup Complete!' mod='trusteed'}
    </h3>

    <div class="alert alert-success">
      <strong><span class="icon-check-circle"></span>
        {l s='Your store is now connected to Trusteed.' mod='trusteed'}
      </strong>
      <p style="margin-top:8px;">
        {l s='Trust badges, eIDAS receipts and AI-agent checkout are now active.' mod='trusteed'}
      </p>
    </div>

    <div class="text-center" style="margin-top:32px;">
      <a href="{$link->getAdminLink('AdminAgenticTrust')|escape:'htmlall':'UTF-8'}"
         class="btn btn-primary btn-lg">
        <span class="icon-tachometer"></span>
        {l s='Open Trust Center' mod='trusteed'} &rarr;
      </a>
    </div>
  </div>

</div>{* /panel *}

{* ─── Inline styles ──────────────────────────────────────────────────────────── *}
<style>
  .amcpw-step-label { padding-top: 4px; cursor: default; }
  .amcpw-step-label .amcpw-step-circle {
    display: inline-block;
    width: 32px; height: 32px;
    line-height: 32px;
    border-radius: 50%;
    background: #ccc;
    color: #fff;
    font-weight: bold;
    text-align: center;
  }
  .amcpw-step-label.active .amcpw-step-circle { background: #25b9d7; }
  .amcpw-step-label.done .amcpw-step-circle   { background: #70b580; }
  .amcpw-checklist li { margin-bottom: 8px; }
  .hidden { display: none !important; }
</style>

{* ─── Vanilla JS wizard logic ────────────────────────────────────────────────── *}
<script>
(function () {
  'use strict';

  var CSRF_TOKEN  = '{$csrf_token|escape:'javascript'}';
  var AJAX_BASE   = '{$link->getAdminLink($controller, false)|escape:'javascript'}';

  // ── Step navigation ─────────────────────────────────────────────────────────

  var currentStep = 1;

  function goTo(step) {
    document.querySelectorAll('.amcpw-step').forEach(function (el) {
      el.classList.add('hidden');
    });
    var target = document.getElementById('amcpw-step-' + step);
    if (target) { target.classList.remove('hidden'); }

    document.querySelectorAll('.amcpw-step-label').forEach(function (el) {
      var s = parseInt(el.dataset.step, 10);
      el.classList.remove('active', 'done');
      if (s < step)  { el.classList.add('done'); }
      if (s === step) { el.classList.add('active'); }
    });

    var bar = document.getElementById('amcpw-progress-bar');
    if (bar) { bar.style.width = (step * 25) + '%'; }

    currentStep = step;
  }

  // Delegate click for .amcpw-next / .amcpw-prev buttons
  document.getElementById('agenticmcp-wizard').addEventListener('click', function (e) {
    var el = e.target;
    if (el.classList.contains('amcpw-next')) { goTo(parseInt(el.dataset.next, 10)); }
    if (el.classList.contains('amcpw-prev')) { goTo(parseInt(el.dataset.prev, 10)); }
  });

  // ── Helpers ─────────────────────────────────────────────────────────────────

  function showAlert(containerId, type, message) {
    var el = document.getElementById(containerId);
    if (!el) { return; }
    el.className = 'alert alert-' + type;
    el.textContent = message;
    el.classList.remove('hidden');
  }

  function hideAlert(containerId) {
    var el = document.getElementById(containerId);
    if (el) { el.classList.add('hidden'); }
  }

  function setLoading(btnId, spinnerId, labelId, loading) {
    var btn     = document.getElementById(btnId);
    var spinner = document.getElementById(spinnerId);
    var label   = document.getElementById(labelId);
    if (btn)     { btn.disabled = loading; }
    if (spinner) { spinner.classList.toggle('hidden', !loading); }
    if (label)   { label.classList.toggle('hidden', loading); }
  }

  /**
   * Minimal AJAX helper — sends a POST with URL-encoded body.
   * Returns a Promise that resolves to the parsed JSON body.
   */
  function ajaxPost(url, params) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.timeout = 15000;
      xhr.onload = function () {
        try {
          resolve(JSON.parse(xhr.responseText));
        } catch (e) {
          reject(new Error('invalid_json'));
        }
      };
      xhr.onerror   = function () { reject(new Error('network_error')); };
      xhr.ontimeout = function () { reject(new Error('timeout')); };

      var encoded = Object.keys(params).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      }).join('&');
      xhr.send(encoded);
    });
  }

  // ── Step 2: Auto-register ────────────────────────────────────────────────────

  document.getElementById('amcpw-autoreg-btn').addEventListener('click', function () {
    hideAlert('amcpw-autoreg-alert');
    setLoading('amcpw-autoreg-btn', 'amcpw-autoreg-spinner', 'amcpw-autoreg-label', true);

    ajaxPost(AJAX_BASE + '&ajax=1&action=autoRegister', { token: CSRF_TOKEN })
    .then(function (data) {
      if (data.success) {
        goTo(3);
      } else {
        var msgMap = {
          super_admin_required: '{l s='A super-administrator account is required.' mod='trusteed' js=1}',
          auto_register_failed: '{l s='Could not connect to Trusteed. Check your internet connection and try again.' mod='trusteed' js=1}',
          csrf:                 '{l s='Security token mismatch. Please refresh the page.' mod='trusteed' js=1}'
        };
        var msg = msgMap[data.error] || (data.detail || data.error || '{l s='Unknown error.' mod='trusteed' js=1}');
        showAlert('amcpw-autoreg-alert', 'danger', msg);
      }
    })
    .catch(function (err) {
      showAlert('amcpw-autoreg-alert', 'danger', '{l s='Request failed. Please try again.' mod='trusteed' js=1} (' + err.message + ')');
    })
    .finally(function () {
      setLoading('amcpw-autoreg-btn', 'amcpw-autoreg-spinner', 'amcpw-autoreg-label', false);
    });
  });

  // ── Step 3: Test connection ───────────────────────────────────────────────────

  document.getElementById('amcpw-test-btn').addEventListener('click', function () {
    hideAlert('amcpw-test-alert');
    document.getElementById('amcpw-test-success').classList.add('hidden');
    document.getElementById('amcpw-test-fail').classList.add('hidden');

    setLoading('amcpw-test-btn', 'amcpw-test-spinner', 'amcpw-test-label', true);

    ajaxPost(AJAX_BASE + '&ajax=1&action=testConnection', { token: CSRF_TOKEN })
    .then(function (data) {
      if (data.success) {
        var detail = document.getElementById('amcpw-test-detail');
        if (detail) {
          detail.textContent = '{l s='Connected as merchant' mod='trusteed' js=1} ' + (data.merchant_id || '') + '.';
        }
        document.getElementById('amcpw-test-success').classList.remove('hidden');
      } else {
        var failDetail = document.getElementById('amcpw-test-fail-detail');
        if (failDetail) {
          var errMap = {
            configure_module_first: '{l s='Credentials not saved yet. Please go back to step 2.' mod='trusteed' js=1}',
            bootstrap_failed:       '{l s='The Trusteed API rejected the connection. Try connecting again.' mod='trusteed' js=1}',
            csrf:                   '{l s='Security token mismatch. Please refresh the page.' mod='trusteed' js=1}'
          };
          failDetail.textContent = errMap[data.error] || ('{l s='Error:' mod='trusteed' js=1} ' + (data.error || 'unknown'));
        }
        document.getElementById('amcpw-test-fail').classList.remove('hidden');
      }
    })
    .catch(function (err) {
      var failDetail = document.getElementById('amcpw-test-fail-detail');
      if (failDetail) {
        failDetail.textContent = '{l s='Network error.' mod='trusteed' js=1} ' + err.message;
      }
      document.getElementById('amcpw-test-fail').classList.remove('hidden');
    })
    .finally(function () {
      setLoading('amcpw-test-btn', 'amcpw-test-spinner', 'amcpw-test-label', false);
    });
  });

}());
</script>
