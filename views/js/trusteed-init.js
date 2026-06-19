/*
 * Trusteed Trust Center — CSP-safe SPA bootstrap.
 *
 * This file is loaded as an EXTERNAL script (no inline <script>), so it works
 * under a strict Content-Security-Policy that forbids inline JS. All values
 * that Smarty injects (CSRF token, AJAX endpoint) are read from data-* attributes
 * on the #amcp-root container instead of being interpolated into source.
 *
 * The PS token is an OPAQUE token with a short TTL (<= 900s) issued by the
 * controller relay to POST /v1/embed/ps/issue-token. The shared SPA api-client
 * calls getToken() before every request but does NOT retry on 401, so we refresh
 * proactively here: re-fetch once the cached token enters its last 60s of life.
 * _expiresAtMs/_inflight are module-scoped so the token can be invalidated and
 * re-issued without touching the shared bundle. The token is held in JS memory
 * (and the SPA persists it in tab-scoped sessionStorage) and sent as a Bearer
 * header — it is NOT an httpOnly cookie.
 */
(function () {
  var rootEl = document.getElementById("amcp-root");
  if (!rootEl) {
    return;
  }

  var CSRF_TOKEN = rootEl.dataset.csrfToken || "";
  var AJAX_URL = rootEl.dataset.tokenEndpoint || "";

  var _cachedToken = null;
  var _expiresAtMs = 0;
  var _inflight = null;
  var REFRESH_SKEW_MS = 60 * 1000;

  function fetchToken() {
    return fetch(AJAX_URL + "&token=" + encodeURIComponent(CSRF_TOKEN), {
      method: "POST",
      headers: { Accept: "application/json" },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data.success && data.access_token) {
          _cachedToken = data.access_token;
          // expires_in is seconds; default to 300s if absent/invalid.
          var ttlSec =
            typeof data.expires_in === "number" && data.expires_in > 0
              ? data.expires_in
              : 300;
          _expiresAtMs = Date.now() + ttlSec * 1000;
          return _cachedToken;
        }
        _cachedToken = null;
        _expiresAtMs = 0;
        return null;
      })
      .catch(function () {
        _cachedToken = null;
        _expiresAtMs = 0;
        return null;
      });
  }

  function getToken() {
    var fresh = _cachedToken && Date.now() < _expiresAtMs - REFRESH_SKEW_MS;
    if (fresh) {
      return Promise.resolve(_cachedToken);
    }
    // Coalesce concurrent refreshes so a burst of requests issues one token.
    if (_inflight) {
      return _inflight;
    }
    _inflight = fetchToken().then(
      function (t) {
        _inflight = null;
        return t;
      },
      function () {
        _inflight = null;
        return null;
      }
    );
    return _inflight;
  }

  if (window.TrusteedEmbed && window.TrusteedEmbed.mount) {
    window.TrusteedEmbed.mount(rootEl, {
      section: "trust-center",
      source: "ps-embed",
      getToken: getToken,
    });
  }
})();
