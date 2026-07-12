<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

use Trusteed\Enforcement\PriceSnapVerifier;
use Trusteed\Storage\FileCache;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Trusteed CEL Hook Handler for PrestaShop.
 *
 * IMPORTANT: `actionValidateOrderBefore` does NOT exist in PS 8.2 core (confirmed
 * by T000c spike). The primary blocking path for PS 8.x is the PaymentModule
 * override in override/classes/PaymentModule.php.
 *
 * This class:
 *  - Registers `actionValidateOrderBefore` for PS 9+ forward compatibility
 *    (no-op when the hook does not exist in PS 8.2).
 *  - Implements `actionCartSave` for non-blocking telemetry (early signal).
 *
 * @package Trusteed\Enforcement
 */
class ValidateOrderHook
{
    /**
     * Register CEL hooks on the module.
     * Called from Trusteed::install().
     */
    public static function register(\Trusteed $module): void
    {
        // PS 9+ forward compat — no-op in PS 8.2 (hook does not exist in core)
        $module->registerHook('actionValidateOrderBefore');

        // Telemetry: cart add/update — non-blocking early signal
        $module->registerHook('actionCartSave');
    }

    /**
     * Unregister CEL hooks on the module.
     * Called from Trusteed::uninstall().
     */
    public static function unregister(\Trusteed $module): void
    {
        $module->unregisterHook('actionValidateOrderBefore');
        $module->unregisterHook('actionCartSave');
    }

    /**
     * PS 9+ pre-validate hook (forward compatibility).
     *
     * In PS 9+ if this hook exists in core, we block here too.
     * Throws PrestaShopException matching the PaymentModule override behavior.
     *
     * @param array $params May contain 'cart' key with the active Cart object.
     * @throws \PrestaShopException when the agent token fails CEL evaluation.
     */
    public function hookActionValidateOrderBefore(array $params): void
    {
        $cart = $params['cart'] ?? null;

        if (!($cart instanceof \Cart)) {
            return;
        }

        $this->evaluateCart($cart);
    }

    /**
     * Cart save hook — non-blocking telemetry (PS 8.2 + PS 9+).
     *
     * Used to detect agentic carts early for observability.
     * Does NOT block — only logs / emits metrics.
     *
     * @param array $params May contain 'cart' key.
     */
    public function hookActionCartSave(array $params): void
    {
        $cart = $params['cart'] ?? null;

        if (!($cart instanceof \Cart)) {
            return;
        }

        $shopId  = MerchantResolver::currentShopId();
        if (!MerchantResolver::isEnabled($shopId)) {
            return;
        }

        // Detect agentic cart by presence of _trusteed_agent_token cookie or attribute
        $agentToken = $this->resolveAgentToken($cart);
        if ($agentToken === '') {
            return;
        }

        // Telemetry breadcrumb — log presence, do not block
        \PrestaShopLogger::addLog(
            'CEL: agentic cart detected (id=' . (int) $cart->id . ')',
            \PrestaShopLogger::LOG_SEVERITY_INFO,
            null,
            'Cart',
            (int) $cart->id,
            true
        );
    }

    /**
     * Shared evaluation logic used by PS 9+ hook and PaymentModule override.
     *
     * Pulls snapshot, verifies agent token, evaluates rules.
     * Throws PrestaShopException with UCP reason_code on BLOCK.
     *
     * @throws \PrestaShopException when CEL rules block the order.
     */
    public function evaluateCart(\Cart $cart): void
    {
        $shopId = MerchantResolver::currentShopId();

        if (!MerchantResolver::isEnabled($shopId)) {
            return;
        }

        // App Store remediation (2026-07-11): previously this method returned
        // immediately for any cart with no agent token ("human flow, not
        // subject to CEL") — which meant merchant-wide policy rules (R019
        // country restriction, R020 business hours, R030 max-order-amount,
        // etc., all in $tier2Codes below) never applied to a normal human
        // checkout. `$agentToken` may now be '' — $claims stays null in that
        // case (no verification attempted, since there is nothing to verify)
        // and every AGENT-specific branch below is gated on `$claims !== null`
        // so it degrades to a no-op exactly as it already did for an absent
        // snapshot/misconfigured merchant. The shared Layer-2 evaluator
        // resolves each rule's `appliesTo` from ruleCode identity and safely
        // excludes AGENT-only rules (R001, etc.) when `agentId` is null.
        $agentToken = $this->resolveAgentToken($cart);

        $snapshotClient = MerchantResolver::buildSnapshotClient($shopId);
        if ($snapshotClient === null) {
            // CEL not configured — fail-open (balanced fallback)
            return;
        }

        $jws = $snapshotClient->pull();
        if ($jws === null) {
            // Cannot pull snapshot — balanced fail-open
            return;
        }

        $payload = $snapshotClient->verifyAndDecode($jws);
        if ($payload === null) {
            return;
        }

        $claims = null;
        $nonceAttrs = [];

        if ($agentToken !== '') {
            // Compute checkoutIntentHash from cart
            $intentHash = $this->computeCartHash($cart, $payload['merchantId'] ?? '');

            $verifier = new TokenVerifier(
                (array) ($payload['agentDidResolver'] ?? []),
                MerchantResolver::getMerchantId($shopId)
            );

            $claims = $verifier->verify($agentToken, $intentHash);

            if ($claims === null) {
                // Token PRESENTED but invalid — evaluate as unverified agent
                // under fallback policy. This is distinct from "no token at
                // all" (handled below the outer `if`), which must never hit
                // this fail-closed path.
                $fallback = MerchantResolver::getFallbackMode($shopId);
                if ($fallback === 'strict') {
                    throw new \PrestaShopException(
                        'trusteed:R001 — Agent identity not verified. '
                        . 'requires_escalation: true'
                    );
                }
            } else {
                // Spec-048 P2.8 — single-use replay protection via backend
                // nonce-consume. verify() guarantees jti is present +
                // well-formed; consume it now so any subsequent reuse of the
                // same token returns REPLAY. Failure_mode tri-state (Gap 5/6
                // parity with WC):
                //   ACCEPTED      → continue
                //   REPLAY        → treat as INVALID, apply fallbackMode (strict throws)
                //   INDETERMINATE → strict=block (throws), balanced/permissive=allow + telemetry attr
                $nonceAttrs = $this->consumeAgentNonce($snapshotClient, $shopId, $claims);
            }
        }

        // Local (offline) tier-1 rules from the snapshot — R001/R043 etc. are
        // genuinely agent-specific (evaluateRule()'s default branch ALLOWs
        // any rule it does not explicitly implement), so this loop is
        // meaningless — and must not run — without verified claims.
        if ($claims !== null) {
            $rules = (array) ($payload['rules'] ?? []);
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $ruleCode = (string) ($rule['ruleCode'] ?? '');
                $decision = $this->evaluateRule($rule, $claims, $payload);

                if ($decision === 'BLOCK') {
                    $rulePrefix  = preg_match('/^(R\d{3})/', $ruleCode, $m) ? $m[1] : substr($ruleCode, 0, 4);
                    $helpUrl     = 'https://trusteed.xyz/es/agent-rules#' . $rulePrefix;
                    throw new \PrestaShopException(
                        $ruleCode . ' — '
                        . (string) ($rule['localizedMessage'] ?? 'Checkout blocked by Trusteed CEL policy.')
                        . ' requires_escalation: true | Más info: ' . $helpUrl
                    );
                }
            }
        }

        $rules = (array) ($payload['rules'] ?? []);

        // ── Capa 2: R002-R006, R008-R030 require server-side evaluation ──────
        $tier2Codes = [
            'R002', 'R003', 'R004', 'R005', 'R006', 'R008', 'R009', 'R010',
            'R011', 'R012', 'R013', 'R014', 'R015', 'R016', 'R017', 'R018',
            'R019', 'R020', 'R021', 'R022', 'R023', 'R024', 'R025', 'R026',
            'R027', 'R028', 'R029', 'R030',
        ];
        $hasTier2 = false;
        foreach ($rules as $rule) {
            if (is_array($rule) && !empty($rule['enabled'])) {
                if (in_array($rule['ruleCode'] ?? '', $tier2Codes, true)) {
                    $hasTier2 = true;
                    break;
                }
            }
        }

        // App Store remediation (2026-07-11): Layer-2 must run even without
        // verified claims (organic checkout) — $agentId is null in that case
        // and the shared evaluator's ruleCode-identity `appliesTo` partition
        // safely excludes AGENT-only rules while still evaluating universal
        // ones (R014/R018/R019/R020/R025/R027/R030) against orderContext.
        if ($hasTier2) {
            $agentId         = $claims !== null ? (string) ($claims['agentId'] ?? '') : null;
            $agentTrustScore = isset($claims['trustScore']) ? (float) $claims['trustScore'] : null;
            $products        = $cart->getProducts();
            $cartTotalCents  = (int) round((float) $cart->getOrderTotal(true, \Cart::BOTH) * 100);
            $currency        = \Currency::getCurrencyInstance((int) $cart->id_currency)->iso_code ?? 'EUR';

            $psCartCtx = $this->buildCartContext($cart, $claims ?? [], $payload);
            // Merge spec-048 P2.8 nonce-consume flags (replay/indeterminate) into
            // cartAttributes so backend tier-2 evaluators can react.
            if (!empty($nonceAttrs)) {
                if (!isset($psCartCtx['cartAttributes']) || !is_array($psCartCtx['cartAttributes'])) {
                    $psCartCtx['cartAttributes'] = [];
                }
                $psCartCtx['cartAttributes'] = array_merge($psCartCtx['cartAttributes'], $nonceAttrs);
            }
            $layer2 = $snapshotClient->callRulesEvaluate(
                $agentId,
                $cartTotalCents,
                $currency,
                count($products),
                $agentTrustScore,
                $psCartCtx
            );

            $fallback = MerchantResolver::getFallbackMode($shopId);
            if ($layer2 === null) {
                // Sprint C T13: try the 24h local snapshot cache before
                // applying FallbackMode. See PaymentModule::runCelEvaluation
                // and `docs/analysis/rules_claude.md` §4.1 / §6.2.
                $cached = $snapshotClient->getLocalSnapshotCache();
                if ($cached !== null) {
                    \PrestaShopLogger::addLog(
                        sprintf(
                            '[trusteed.snapshot.cache_fallback] {"merchantId":"%s","cacheAgeSeconds":%d,"reason":"layer2_unavailable"}',
                            (string) MerchantResolver::getMerchantId($shopId),
                            (int) $cached['ageSeconds']
                        ),
                        \PrestaShopLogger::LOG_SEVERITY_WARNING,
                        null,
                        'Cart',
                        (int) $cart->id,
                        true
                    );

                    // App Store remediation follow-up (2026-07-11): the
                    // cached snapshot was previously ONLY used for this log
                    // breadcrumb — nothing actually re-evaluated the cart
                    // against it, so a merchant's own safety-valve rules
                    // (max order amount, blocked countries, business hours,
                    // PO-box block, gift-card cap...) silently never fired
                    // during a Layer-2 outage. Evaluate the offline-capable
                    // subset now, using the same last-known-good rules[].
                    $cachedRules = $this->decodeCachedSnapshotRules($cached['jws']);
                    if ($cachedRules !== null) {
                        $offlineBlock = OfflineSafetyValveEvaluator::evaluate(
                            $cachedRules,
                            [
                                'cartTotalCents' => $cartTotalCents,
                                'itemCount' => count($products),
                                'billingCountry' => $psCartCtx['billingCountry'] ?? null,
                                'shippingCountry' => $psCartCtx['shippingCountry'] ?? null,
                                'lineItems' => $psCartCtx['lineItems'] ?? [],
                            ],
                            $psCartCtx['cartAttributes'] ?? []
                        );
                        if ($offlineBlock !== null) {
                            \PrestaShopLogger::addLog(
                                sprintf(
                                    '[trusteed.offline_safety_valve] {"ruleCode":"%s","reason":"%s"}',
                                    $offlineBlock['ruleCode'],
                                    $offlineBlock['reason']
                                ),
                                \PrestaShopLogger::LOG_SEVERITY_WARNING,
                                null,
                                'Cart',
                                (int) $cart->id,
                                true
                            );
                            $offlineHelp = 'https://trusteed.xyz/es/agent-rules#' . $offlineBlock['ruleCode'];
                            throw new \PrestaShopException(
                                $offlineBlock['ruleCode'] . ' — ' . $offlineBlock['reason']
                                . ' requires_escalation: true | Más info: ' . $offlineHelp
                            );
                        }
                    }
                } else {
                    // OBSERVE-only Tier-2 rules: even in strict, do not block.
                    $hasEnforce = false;
                    foreach ($rules as $r) {
                        if (!is_array($r) || empty($r['enabled'])) {
                            continue;
                        }
                        if (!in_array($r['ruleCode'] ?? '', $tier2Codes, true)) {
                            continue;
                        }
                        $mode = strtolower((string) ($r['mode'] ?? 'enforce'));
                        if ($mode !== 'observe') {
                            $hasEnforce = true;
                            break;
                        }
                    }

                    if ($fallback === 'strict' && $hasEnforce) {
                        throw new \PrestaShopException(
                            'trusteed:CEL-TIMEOUT — Rules evaluation service unavailable. requires_escalation: true'
                        );
                    }

                    if ($fallback !== 'strict') {
                        \PrestaShopLogger::addLog(
                            sprintf(
                                '[trusteed.snapshot.fallback_open] {"merchantId":"%s","fallbackMode":"%s","reason":"layer2_unavailable_no_cache"}',
                                (string) MerchantResolver::getMerchantId($shopId),
                                $fallback
                            ),
                            \PrestaShopLogger::LOG_SEVERITY_WARNING,
                            null,
                            'Cart',
                            (int) $cart->id,
                            true
                        );
                    }
                }
            } elseif ($layer2['decision'] === 'BLOCK') {
                // H2: R043 HITL outcomes (decision=BLOCK + ucp.state=
                // requires_escalation + ucp.reason_code=trusteed:R043…) must be
                // HONORED via R043HitlGate rather than treated like a plain block
                // or, worse, allowed. In the PS validateOrder() context the only
                // safe primitive is to NOT create the order (a true create-then-
                // freeze would require manipulating the parent order state, which
                // is out of scope for the enforcement seam). We therefore block
                // with the gate-derived R043 reason/evaluation id and log the
                // freeze intent so the merchant dashboard escalation is traceable.
                if (R043HitlGate::isHitlResponse($layer2)) {
                    $freeze   = R043HitlGate::buildFreezePayload($layer2);
                    $ruleCode = $freeze['rule_code'] !== '' ? $freeze['rule_code'] : 'R043';
                    $reason   = $freeze['reason'] !== ''
                        ? $freeze['reason']
                        : 'Human review required before this agentic order can complete.';
                    \PrestaShopLogger::addLog(
                        sprintf(
                            '[trusteed.r043_hitl] {"ruleCode":"%s","evaluationId":"%s","action":"block_pending_review"}',
                            $ruleCode,
                            $freeze['evaluation_id']
                        ),
                        \PrestaShopLogger::LOG_SEVERITY_WARNING,
                        null,
                        'Cart',
                        (int) $cart->id,
                        true
                    );
                    // H5(b): mirror Magento's local quote freeze (is_active=0 +
                    // QUOTE_META_HITL_PENDING) with a durable per-cart pending
                    // record before aborting. The throw below keeps the order
                    // from being created (parity with Magento, which also throws
                    // and creates no order).
                    $this->applyHitlFreeze($cart, $freeze);
                    $hitlHelp = 'https://trusteed.xyz/es/agent-rules#'
                        . (preg_match('/^(R\d{3})/', $ruleCode, $hm) ? $hm[1] : 'R043');
                    throw new \PrestaShopException(
                        $ruleCode . ' — ' . $reason
                        . ' requires_escalation: true | Más info: ' . $hitlHelp
                    );
                }

                $blockedCode   = $layer2['ruleCode'] !== '' ? $layer2['ruleCode'] : 'R000';
                $blockedReason = $layer2['reason'] !== '' ? $layer2['reason'] : 'Checkout blocked by Trusteed CEL policy.';
                $blockedPrefix = preg_match('/^(R\d{3})/', $blockedCode, $m) ? $m[1] : substr($blockedCode, 0, 4);
                $blockedHelp   = 'https://trusteed.xyz/es/agent-rules#' . $blockedPrefix;
                throw new \PrestaShopException(
                    $blockedCode . ' — ' . $blockedReason . ' requires_escalation: true | Más info: ' . $blockedHelp
                );
            }
        }

        // ALLOW path: persist agentDid so OrderEventsEmitter can include it
        // in the webhook payload for R023 agentIdHash propagation to PlatformOrder.
        if (
            $claims !== null
            && isset($claims['iss'])
            && (string) $claims['iss'] !== ''
            && (int) $cart->id > 0
            && class_exists('Trusteed\\Storage\\CartAgentStorage')
        ) {
            try {
                \Trusteed\Storage\CartAgentStorage::set((int) $cart->id, (string) $claims['iss']);
            } catch (\Throwable) {
                // Non-fatal — R023 will fail-open.
            }
        }
    }

    /**
     * Resolve agent token from cart attributes or cookie.
     */
    private function resolveAgentToken(\Cart $cart): string
    {
        // Check for token in request (sent by agentic checkout layer)
        $token = (string) \Tools::getValue('_trusteed_agent_token', '');
        if ($token !== '') {
            return $token;
        }

        // Check cookie fallback
        $cookie = (string) ($cart->getContext()->cookie->trusteed_agent_token ?? '');

        return $cookie;
    }

    /**
     * App Store remediation follow-up (2026-07-11) — decode the `rules[]`
     * array from a cached, already-verified snapshot JWS (written by
     * SnapshotClient::pull() only after signature verification, so no
     * re-verification — and no JWKS network call — is needed here; this
     * runs on the Layer-2-unavailable path specifically to avoid one).
     *
     * @return array|null Snapshot `rules[]`, or null on any decode failure.
     */
    private function decodeCachedSnapshotRules(string $jws): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }
        $payloadJson = SnapshotClient::base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['rules']) || !is_array($payload['rules'])) {
            return null;
        }
        return $payload['rules'];
    }

    /**
     * Compute a checkoutIntentHash from cart contents for offline token binding.
     *
     * ── M2 INVESTIGATION NOTE — canonical format divergence (documented, NOT changed)
     *
     * The CANONICAL checkoutIntentHash SSOT is the TypeScript JCS (RFC 8785)
     * implementation at
     *   packages/shared/src/enforcement/checkout-intent-hash.service.ts
     *     ::computeCheckoutIntentHash()
     * which produces `sha256:<hex>` over a fixed field set
     * {billingCountry, cartTotalCents, currency, customerEmailHash,
     *  discountCodes, itemCount, lineItems[{id,priceCents,qty}], paymentMethod,
     *  shippingCountry, taxAmountCents}, keys sorted lexicographically.
     *
     * This PS routine instead emits a BARE sha256 hex (no `sha256:` prefix) over
     * a different, smaller shape {merchantId, lineItems[{id,qty}], currency}
     * via plain json_encode (NOT JcsCanonicalize). This DIVERGES from the SSOT.
     *
     * Why it is intentionally left as-is here:
     *   - `computeCheckoutIntentHash` has NO production caller in apps/ — the
     *     backend does not currently mint agent tokens that embed the JCS hash;
     *     the agent/SDK side supplies the token's checkoutIntentHash.
     *   - The deployed, WC-parity model (wp-plugin token-verifier) likewise uses
     *     a platform-specific cart identifier, not the JCS hash.
     *   - Switching this to JCS unilaterally (without the agent-side minting the
     *     SAME field set) would make NO token match → every agentic order
     *     rejected. That is a worse failure than the current best-effort binding.
     *
     * ACTION REQUIRED FOR ALIGNMENT (future, coordinated): port
     * `computeCheckoutIntentHash` to PHP using JcsCanonicalize (src/Hmac/),
     * emit the `sha256:` prefix and the full field set, AND verify against the
     * cross-language golden vectors — simultaneously with the token-minting side.
     * Do NOT change one side in isolation.
     */
    private function computeCartHash(\Cart $cart, string $merchantId): string
    {
        $products = $cart->getProducts();
        $items    = [];

        foreach ($products as $p) {
            $items[] = [
                'id'  => (string) ($p['id_product'] ?? ''),
                'qty' => (int) ($p['cart_quantity'] ?? 0),
            ];
        }

        usort($items, static fn($a, $b) => strcmp($a['id'], $b['id']));

        $canonical = json_encode([
            'merchantId' => $merchantId,
            'lineItems'  => $items,
            'currency'   => $cart->id_currency ?? '',
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /**
     * Evaluate a single rule against verified agent claims.
     * Returns 'ALLOW' or 'BLOCK'.
     */
    private function evaluateRule(array $rule, array $claims, array $snapshot): string
    {
        $ruleCode = (string) ($rule['ruleCode'] ?? '');
        $enabled  = (bool) ($rule['enabled'] ?? true);

        if (!$enabled) {
            return 'ALLOW';
        }

        switch ($ruleCode) {
            case 'R001':
                // Agent identity must be verified
                return isset($claims['agentId']) && $claims['agentId'] !== '' ? 'ALLOW' : 'BLOCK';

            case 'R007':
                // High-risk country check — requires trust score
                $trustScore = $claims['trustScore'] ?? null;
                if ($trustScore !== null && (float) $trustScore < 0.3) {
                    return 'BLOCK';
                }

                return 'ALLOW';

            default:
                // Unknown rules default to ALLOW (fail-open for unimplemented)
                return 'ALLOW';
        }
    }

    /**
     * Build enriched cart context from PrestaShop Cart for Layer-2 evaluation.
     * All fields are best-effort; missing data is omitted (fail-open for context).
     */
    private function buildCartContext(\Cart $cart, array $agentResult = [], array $snapshot = []): array
    {
        $ctx = [];

        try {
            $cartAttributes    = [];
            $lineItems         = [];
            $productCategories = [];
            $lowestStock       = PHP_INT_MAX;
            $hasSubscription   = false;

            // R022: Payment gateway selected by the buyer — available in POST at hook time.
            $paymentGateway = \Tools::getValue('payment', '');
            if (is_string($paymentGateway) && $paymentGateway !== '') {
                // Strip anything outside [a-z0-9_-] — gateway IDs are always slug-like.
                $ctx['paymentMethod'] = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $paymentGateway));
            }

            // R015: Price snapshot stored in cookie at cart-add time.
            // B4: HMAC-verified envelope only — raw client cookies are rejected.
            // The merchant HMAC key arrives via R015.params.priceSnapHmacKeyHex
            // inside the signed snapshot (server-controlled, not agent-writable).
            $priceSnapshotKey = 'trusteed_price_snap_' . (int) $cart->id;
            $priceSnapshotRaw = isset($_COOKIE[$priceSnapshotKey]) ? (string) $_COOKIE[$priceSnapshotKey] : '';
            $hmacKeyHex       = $this->resolveR015HmacKey($snapshot);
            $priceSnapshot    = PriceSnapVerifier::verify($priceSnapshotRaw, $hmacKeyHex);
            $maxPriceDeltaBps = 0;

            foreach ($cart->getProducts() as $p) {
                $productId  = (int) ($p['id_product'] ?? 0);
                $qty        = (int) ($p['cart_quantity'] ?? 1);
                $priceCents = (int) round((float) ($p['price_wt'] ?? $p['price'] ?? 0) * 100);

                $lineItems[] = [
                    'id'         => (string) $productId,
                    'qty'        => $qty,
                    'priceCents' => $priceCents,
                ];

                // R015: Compare current price to snapshot price if available.
                $pid = (string) $productId;
                if (isset($priceSnapshot[$pid]) && (int) $priceSnapshot[$pid] > 0) {
                    $origCents = (int) $priceSnapshot[$pid];
                    $deltaBps  = (int) round(abs($priceCents - $origCents) / $origCents * 10000);
                    if ($deltaBps > $maxPriceDeltaBps) {
                        $maxPriceDeltaBps = $deltaBps;
                    }
                }

                $stock = \StockAvailable::getQuantityAvailableByProduct($productId, 0);
                if ($stock < $lowestStock) {
                    $lowestStock = $stock;
                }

                // R026: subscription detection via product features (best-effort).
                // PS has no native subscription type — modules typically add a Feature.
                if (!$hasSubscription && $productId > 0) {
                    try {
                        $features = \Product::getFeaturesStatic($productId);
                        foreach ($features as $feat) {
                            $featName = strtolower((string) ($feat['name'] ?? ''));
                            if (strpos($featName, 'subscription') !== false
                                || strpos($featName, 'recurring') !== false
                                || strpos($featName, 'abonnement') !== false
                                || strpos($featName, 'suscripcion') !== false
                            ) {
                                $hasSubscription = true;
                                break;
                            }
                        }
                    } catch (\Throwable $ignored) {
                        // Best-effort — never block on feature extraction failure
                    }
                }

                $catId = (int) ($p['id_category_default'] ?? 0);
                if ($catId > 0) {
                    $cat = new \Category($catId, \Context::getContext()->language->id);
                    if (\Validate::isLoadedObject($cat)) {
                        $productCategories[] = $cat->link_rewrite;
                    }
                }
            }

            $ctx['lineItems'] = $lineItems;

            // Shipping / billing country from delivery address
            $addressId = (int) $cart->id_address_delivery;
            if ($addressId > 0) {
                $addr = new \Address($addressId);
                if (\Validate::isLoadedObject($addr)) {
                    $country = \Country::getIsoById($addr->id_country);
                    if ($country) {
                        $ctx['shippingCountry'] = strtoupper((string) $country);
                        $ctx['billingCountry']  = strtoupper((string) $country);

                        $addrLine = trim((string) ($addr->address1 ?? ''));
                        // Audit docs/analysis/rules.md L228 — multi-language
                        // PO-box coverage (en/es/fr/de/it/pt/nl/ru/zh/ja).
                        if ($addrLine !== '' && self::detectPoBox($addrLine)) {
                            $cartAttributes['_shipping_po_box'] = 'true';
                        }
                    }
                }
            }

            if (!empty($productCategories)) {
                $cartAttributes['_product_categories'] = implode(',', array_unique($productCategories));
                $cartAttributes['_product_platform']   = 'prestashop';
            }
            if ($lowestStock !== PHP_INT_MAX) {
                $cartAttributes['_lowest_stock'] = (string) $lowestStock;
            }
            if ($hasSubscription) {
                $cartAttributes['_subscription'] = 'true';
                $cartAttributes['_autorenew']    = 'true';
            }

            // Applied cart rule codes
            $coupons = \CartRule::getCustomerCartRules(
                \Context::getContext()->language->id,
                (int) $cart->id_customer,
                true,
                true,
                true,
                $cart,
                false,
                false
            );
            if (!empty($coupons)) {
                $codes = array_column($coupons, 'code');
                if (!empty($codes)) {
                    $ctx['discountCodes'] = array_values($codes);
                }
            }

            // R004: Track key first-seen across requests.
            // B5: persistent FileCache so plain PS installs (no APCu) also see
            // real key age instead of "0h" on every request.
            $kid = (string) ($agentResult['kid'] ?? '');
            if ($kid !== '') {
                $kidKey      = 'trusteed_kid_fs_' . hash('sha256', $kid);
                $fc          = self::r004Cache();
                $firstSeenTs = null;
                if ($fc !== null) {
                    $stored = $fc->getInt($kidKey);
                    if ($stored > 0) {
                        $firstSeenTs = $stored;
                    } else {
                        $firstSeenTs = time();
                        $fc->addIfAbsent($kidKey, $firstSeenTs, 365 * 24 * 3600);
                    }
                } elseif (function_exists('apcu_fetch')) {
                    $apcuVal = apcu_fetch($kidKey);
                    if ($apcuVal === false) {
                        $firstSeenTs = time();
                        if (function_exists('apcu_add')) {
                            apcu_add($kidKey, $firstSeenTs, 365 * 24 * 3600);
                        }
                    } else {
                        $firstSeenTs = (int) $apcuVal;
                    }
                } else {
                    $firstSeenTs = time();
                }
                $ageHours = (time() - (int) $firstSeenTs) / 3600;
                $cartAttributes['_agent_key_age_hours'] = (string) round($ageHours, 2);
            }

            // R008: Declared scopes from agent token.
            $scope = (string) ($agentResult['scope'] ?? '');
            if ($scope !== '') {
                $cartAttributes['_requested_scopes'] = $scope;
            }

            // R013: Return-policy mismatch.
            $hasFinalSale      = false;
            $agentReturnPolicy = strtolower((string) ($agentResult['returnPolicyAccepted'] ?? ''));
            // Check if any line item is a virtual (non-returnable) product.
            foreach ($lineItems as $li) {
                $product = new \Product((int) ($li['id'] ?? 0), false, \Context::getContext()->language->id ?? 0);
                if ($product->is_virtual ?? false) {
                    $hasFinalSale = true;
                    break;
                }
            }
            if ($hasFinalSale && $agentReturnPolicy !== 'final_sale') {
                $cartAttributes['_return_policy_mismatch'] = 'true';
            }

            // R015: Inject max price delta.
            if ($maxPriceDeltaBps > 0) {
                $cartAttributes['_price_delta_bps'] = (string) $maxPriceDeltaBps;
            }

            // R028: B2B purchase-order guard.
            // Billing address company field is the most reliable PS B2B signal.
            $billAddrId = (int) $cart->id_address_invoice;
            $billAddr   = new \Address($billAddrId);
            $isB2b      = \Validate::isLoadedObject($billAddr)
                && trim((string) ($billAddr->company ?? '')) !== '';
            if (!$isB2b) {
                $customer = new \Customer((int) $cart->id_customer);
                $isB2b    = \Validate::isLoadedObject($customer)
                    && trim((string) ($customer->company ?? '')) !== '';
            }
            if ($isB2b) {
                $cartAttributes['_b2b_order'] = 'true';
                $poHash = trim((string) ($agentResult['purchaseOrderHash'] ?? ''));
                if ($poHash !== '') {
                    $cartAttributes['_purchase_order_hash'] = $poHash;
                }
            }

            if (!empty($cartAttributes)) {
                $ctx['cartAttributes'] = $cartAttributes;
            }
        } catch (\Throwable $e) {
            // Best-effort — never block on extraction failure
        }

        return $ctx;
    }

    /**
     * Audit `docs/analysis/rules.md` L228 — multi-language PO-box detection.
     * Mirrors Magento `CartSignals::detectPoBox` + WC `trusteed_cel_detect_po_box`
     * + Shopify Function `detect_po_box`. Covered locales: en, es (ES/MX/AR/UY/
     * CL/PY/CO), fr (FR/CH-FR/Africa), de, it (incl. abbr CP, Vaticano), pt
     * (BR/PT), nl, ru (а/я), ja (私書箱), zh (邮政信箱).
     */
    public static function detectPoBox(string $street): bool
    {
        static $patterns = [
            '/\b(p\.?\s*o\.?\s*box|post\s+office\s+box|postbox|po\s*-?\s*box)\b/iu',
            '/\b(apartado(\s+postal|\s+de\s+correos|\s+a[eé]reo)?|apdo\.?\s*(postal)?)\b/iu',
            '/\bcasilla(\s+(de\s+)?correo)?(\s+postal)?\b/iu',
            '/\b(bo[iî]te\s+postale|case\s+postale|b\.?p\.?)\b/iu',
            '/\b(postfach|postlagernd)\b/iu',
            '/\b(casella\s+postale|c\.p\.?)\b/iu',
            '/\bcaixa[\s-]?postal\b/iu',
            '/\bpostbus\b/iu',
            '/(а\/я|абонентский\s+ящик)/iu',
            '/私書箱/u',
            '/(邮政信箱|郵政信箱)/u',
        ];
        foreach ($patterns as $regex) {
            if (preg_match($regex, $street) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function r004Cache(): ?FileCache
    {
        static $instance = null;
        if ($instance !== null) {
            return $instance;
        }
        if (!defined('_PS_CACHE_DIR_')) {
            return null;
        }
        $instance = new FileCache(rtrim((string) constant('_PS_CACHE_DIR_'), '/') . '/trusteed_kid_seen');
        return $instance;
    }

    /**
     * H5(b) — FileCache scoped to R043 HITL pending records. Separate directory
     * from the R004 first-seen cache so their TTL/lifecycle never collide.
     */
    private static function hitlCache(): ?FileCache
    {
        static $instance = null;
        if ($instance !== null) {
            return $instance;
        }
        if (!defined('_PS_CACHE_DIR_')) {
            return null;
        }
        $instance = new FileCache(rtrim((string) constant('_PS_CACHE_DIR_'), '/') . '/trusteed_hitl');
        return $instance;
    }

    /**
     * H5(b) — Apply the R043 HITL freeze to the cart before the caller aborts
     * order validation.
     *
     * PrestaShop's enforcement seam runs inside the PaymentModule::validateOrder()
     * override — BEFORE any order row is committed — exactly like Magento's
     * sales_model_service_quote_submit_before observer. In both cases the safe
     * primitive is to NOT create the order (the caller's PrestaShopException
     * aborts it). PS's \Cart has no `is_active` column nor free-form meta, so the
     * faithful mirror of Magento's local quote freeze (setIsActive(false) +
     * {@see R043HitlGate::META_HITL_PENDING}) is a durable best-effort per-cart
     * pending record, persisted here so a merchant-side reader can recognise the
     * frozen cart. The AUTHORITATIVE cross-system HITL resolution for both
     * platforms remains the backend evaluationId path (already logged above).
     *
     * Best-effort: a persistence failure never masks the reviewable-block
     * message thrown by the caller.
     *
     * @param array{rule_code:string,reason:string,evaluation_id:string,freeze:bool} $freeze
     */
    private function applyHitlFreeze(\Cart $cart, array $freeze): void
    {
        try {
            $cartId = (int) $cart->id;
            if ($cartId <= 0) {
                return;
            }
            $cache = self::hitlCache();
            if ($cache === null) {
                return;
            }
            $cache->set(
                'trusteed_hitl_pending_' . $cartId,
                [
                    R043HitlGate::META_HITL_PENDING  => 1,
                    R043HitlGate::META_RULE_CODE     => (string) ($freeze['rule_code'] ?? ''),
                    R043HitlGate::META_REASON        => (string) ($freeze['reason'] ?? ''),
                    R043HitlGate::META_EVALUATION_ID => (string) ($freeze['evaluation_id'] ?? ''),
                ],
                7 * 24 * 3600
            );
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog(
                '[trusteed.r043_hitl_freeze] bookkeeping error: ' . $e->getMessage(),
                \PrestaShopLogger::LOG_SEVERITY_WARNING,
                null,
                'Cart',
                (int) $cart->id,
                true
            );
        }
    }

    /**
     * B4: extract per-merchant HMAC key for R015 from the signed snapshot.
     * Returns '' when R015 is absent or the key field is missing → verifier
     * rejects every cookie, degrading R015 to PASS (safer than trusting raw).
     */
    /**
     * Spec-048 P2.8 — consume the jti against backend /v1/agent-events/nonce-consume.
     *
     * Returns an array of cartAttributes to merge into psCartCtx. May throw on
     * strict-fail-closed paths (REPLAY in strict, INDETERMINATE in strict).
     *
     * @param SnapshotClient $client     Snapshot/HMAC client (provides consumeNonce()).
     * @param int            $shopId     Multishop scope.
     * @param array          $claims     Verified token claims (must include jti+exp+agentId).
     * @return array<string,string>
     * @throws \PrestaShopException on strict-mode REPLAY or INDETERMINATE.
     */
    private function consumeAgentNonce(SnapshotClient $client, int $shopId, array $claims): array
    {
        $jti      = (string) ($claims['jti'] ?? '');
        $agentDid = (string) ($claims['agentId'] ?? '');
        $exp      = (int)    ($claims['exp'] ?? 0);

        if ($jti === '' || $agentDid === '') {
            // verify() guarantees both are present; defensive return — no attrs.
            return [];
        }

        $result   = $client->consumeNonce($agentDid, $jti, $exp);
        $fallback = MerchantResolver::getFallbackMode($shopId);
        $outcome  = (string) ($result['outcome'] ?? NonceOutcome::INDETERMINATE);

        if ($outcome === NonceOutcome::ACCEPTED) {
            return [];
        }

        if ($outcome === NonceOutcome::REPLAY) {
            // Token already seen — must reject. Strict fallback throws hard;
            // balanced/permissive surface attribute so tier-2 R002/R005 can decide.
            \PrestaShopLogger::addLog(
                '[trusteed.enforcement_replay] platform=prestashop merchant='
                    . MerchantResolver::getMerchantId($shopId)
                    . ' fallback=' . $fallback,
                \PrestaShopLogger::LOG_SEVERITY_WARNING,
                null,
                'Cart',
                0,
                true
            );
            if ($fallback === 'strict') {
                throw new \PrestaShopException(
                    'trusteed:R002 — Agent token replay detected. requires_escalation: true'
                );
            }
            return [
                '_agent_token_signature_invalid' => 'true',
                '_agent_token_replay'            => 'true',
            ];
        }

        // INDETERMINATE — honor failure_mode.
        \PrestaShopLogger::addLog(
            '[trusteed.enforcement_indeterminate] platform=prestashop merchant='
                . MerchantResolver::getMerchantId($shopId)
                . ' mode=' . $fallback
                . ' reason=' . (string) ($result['reason'] ?? 'unknown'),
            \PrestaShopLogger::LOG_SEVERITY_WARNING,
            null,
            'Cart',
            0,
            true
        );
        if ($fallback === 'strict') {
            throw new \PrestaShopException(
                'trusteed:CEL-NONCE-UNAVAILABLE — Replay protection service unavailable. requires_escalation: true'
            );
        }
        return [
            '_agent_token_nonce_unavailable' => 'true',
        ];
    }

    private function resolveR015HmacKey(array $snapshot): string
    {
        $rules = $snapshot['rules'] ?? [];
        if (!is_array($rules)) {
            return '';
        }
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $code = (string) ($rule['ruleCode'] ?? '');
            if ($code !== 'R015' && strpos($code, 'R015.') !== 0) {
                continue;
            }
            $params = $rule['params'] ?? [];
            if (is_array($params) && isset($params['priceSnapHmacKeyHex'])) {
                $key = (string) $params['priceSnapHmacKeyHex'];
                if ($key !== '') {
                    return $key;
                }
            }
        }
        return '';
    }
}
