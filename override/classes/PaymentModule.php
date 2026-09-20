<?php

declare(strict_types=1);

/**
 * Trusteed CEL — PaymentModule override for PrestaShop 8.x.
 *
 * IMPORTANT: `actionValidateOrderBefore` does NOT exist in PS 8.2 core.
 * The only way to intercept validateOrder() before any rows are committed
 * is via the PS Override mechanism (confirmed by T000c spike).
 *
 * This override wraps PaymentModuleCore::validateOrder() to:
 *  1. Detect agentic requests by the presence of _trusteed_agent_token in POST.
 *  2. Pull + verify the JWS-signed CEL rule snapshot offline.
 *  3. Evaluate agent token against snapshot rules.
 *  4. Throw PrestaShopException (rolls back the order) when BLOCK.
 *  5. Call parent::validateOrder() for all other cases.
 *
 * C1: PS 8.2 core does NOT fire `actionValidateOrderBefore`, so this override is
 * the SINGLE universal blocking point. To avoid maintaining a second, divergent
 * copy of the enforcement logic, the override DELEGATES to
 * `Trusteed\Enforcement\ValidateOrderHook::evaluateCart()` — the same code
 * path used by the PS 9+ pre-validate hook. That shared method already performs:
 *   (a) anti-replay nonce consume (SnapshotClient::consumeNonce, jti single-use),
 *   (b) R015 price-snapshot verification (PriceSnapVerifier) inside buildCartContext,
 *   (c) R023 agentDid propagation (CartAgentStorage::set) on the ALLOW path,
 * plus the full tier-2 cart context. The previous inline copy here was missing
 * (a)/(b)/(c) and diverged from the canonical fail-open/fail-closed semantics.
 *
 * Override collision handling: if another module ships an identical override,
 * PS will refuse installation with "class already overridden". The module
 * install() method checks for this and presents an admin warning.
 *
 * @see packages/prestashop-module-agenticmcpstores/F0C-SPIKE-RESULT.md
 */
class PaymentModule extends PaymentModuleCore
{
    /**
     * Intercept order validation — the single universal blocking point for
     * PS 8.x UI and non-Webservice agentic checkouts.
     *
     * Signature matches PaymentModuleCore::validateOrder() exactly.
     * All parameters forwarded to parent unchanged after CEL evaluation.
     *
     * @throws \PrestaShopException when CEL rules block the agentic order.
     */
    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = [],
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        \Shop $shop = null
    ) {
        // Load CEL enforcement classes (autoloader may not be active in all contexts)
        $this->loadCelClasses();

        // Run CEL evaluation when classes are available
        if (
            class_exists('Trusteed\\Enforcement\\MerchantResolver')
            && class_exists('Trusteed\\Enforcement\\ValidateOrderHook')
        ) {
            try {
                $this->runCelEvaluation((int) $id_cart, (string) $payment_method);
            } catch (\PrestaShopException $e) {
                // Re-throw — CEL explicitly blocked this order
                throw $e;
            } catch (\Throwable $e) {
                // GHSA-2j2x-5q52-g48m issue 2: an exception raised while
                // evaluating CEL rules is not the same thing as an
                // infrastructure failure, and must never be an implicit
                // permission to skip enforcement. Consult the merchant's own
                // configured fallback mode instead of unconditionally
                // proceeding.
                $this->onUnexpectedCelError($e, (int) $id_cart);
            }
        }

        // All checks passed (or CEL not configured) — proceed with order creation
        return parent::validateOrder(
            $id_cart,
            $id_order_state,
            $amount_paid,
            $payment_method,
            $message,
            $extra_vars,
            $currency_special,
            $dont_touch_amount,
            $secure_key,
            $shop
        );
    }

    /**
     * Perform CEL rule evaluation against the current cart.
     *
     * C1: This now DELEGATES to ValidateOrderHook::evaluateCart() — the single
     * canonical enforcement path shared with the PS 9+ pre-validate hook — so the
     * override gains anti-replay nonce-consume (R002/jti single-use),
     * R015 price-snapshot verification, R023 agentDid propagation
     * (CartAgentStorage::set) and the full tier-2 cart context, without keeping a
     * second divergent copy of the logic here.
     *
     * The shared method resolves the agent token itself (POST `_trusteed_agent_token`
     * → cookie fallback) and is a no-op only for unconfigured merchants / an
     * unreachable snapshot. App Store remediation (2026-07-11): it is NO
     * LONGER a no-op for human (non-agentic) flows — merchant-wide policy
     * rules (R014/R018/R019/R020/R025/R027/R030) now evaluate against every
     * cart regardless of agent presence, via Layer-2 with a null agentId.
     * It throws PrestaShopException on BLOCK / fail-closed, which
     * validateOrder() re-throws to roll back the order.
     *
     * @param string $paymentMethod Forwarded for parity; the shared path also
     *                              derives the payment gateway from the request
     *                              (Tools::getValue('payment')) for R022.
     * @throws \PrestaShopException when rules block the order.
     */
    private function runCelEvaluation(int $cartId, string $paymentMethod = ''): void
    {
        $cart = new \Cart($cartId);
        if (!\Validate::isLoadedObject($cart)) {
            return;
        }

        $hook = new \Trusteed\Enforcement\ValidateOrderHook();
        $hook->evaluateCart($cart);
    }

    /**
     * Handle an unexpected exception raised during CEL evaluation.
     *
     * GHSA-2j2x-5q52-g48m issue 2: the previous behaviour was "log and let
     * the order proceed" for ANY `\Throwable` — an unauthenticated,
     * request-controlled `SodiumException` (malformed token signature length)
     * or a missing PS-core method were both, silently, a pass. That treats
     * "our evaluator broke" as "the merchant wants this order through", which
     * is never true. Defers to `SnapshotUnavailablePolicy::decide()` — the
     * same class that already encodes "the signed mode wins when it exists,
     * an absent/corrupt local mode is fail-closed, never `balanced`" for the
     * sibling snapshot-unavailable case — passed `$failClosedEnabled = true`
     * unconditionally: this is not the API-down scenario that class's own
     * feature flag gates (today pass-all, tomorrow pass-none is a merchant
     * decision), it is the evaluator itself failing, and `strict` already
     * blocked unconditionally on the equivalent branch (Layer-2
     * unavailable + no cache) before this class existed.
     *
     * The exception's own message is logged internally — never surfaced in
     * the `\PrestaShopException` message, which PrestaShop can render to the
     * buyer.
     *
     * @throws \PrestaShopException when the merchant's fallback mode blocks.
     */
    private function onUnexpectedCelError(\Throwable $e, int $cartId): void
    {
        $shopId = \Trusteed\Enforcement\MerchantResolver::currentShopId();
        $mode   = \Trusteed\Enforcement\MerchantResolver::getFallbackMode($shopId);
        $decision = \Trusteed\Enforcement\SnapshotUnavailablePolicy::decide(null, $mode, true);

        // A logging failure (e.g. json_encode choking on invalid UTF-8 in the
        // exception message) must never change the enforcement decision —
        // hence its own try/catch, separate from the decision above.
        try {
            $detail = json_encode(
                ['error' => $e->getMessage(), 'fallbackMode' => $mode, 'decision' => $decision],
                JSON_INVALID_UTF8_SUBSTITUTE
            );
            \PrestaShopLogger::addLog(
                '[trusteed.cel.evaluator_error] ' . ($detail !== false ? $detail : $e->getMessage()),
                \PrestaShopLogger::LOG_SEVERITY_ERROR,
                null,
                'Cart',
                $cartId,
                true
            );
        } catch (\Throwable $logError) {
            // Swallowed on purpose — see docblock.
        }

        if ($decision === \Trusteed\Enforcement\SnapshotUnavailablePolicy::DECISION_BLOCK) {
            throw new \PrestaShopException(
                'Trusteed CEL: unable to complete order validation. Please try again or contact support.'
            );
        }
    }

    /**
     * Load CEL enforcement classes via require_once as fallback
     * when composer autoloader is not active in the override context.
     *
     * C1: ValidateOrderHook is now the delegation target, so it and its
     * collaborators (PriceSnapVerifier, FileCache, CartAgentStorage) must be
     * loadable here too when the composer autoloader is not active.
     */
    private function loadCelClasses(): void
    {
        $moduleDir = _PS_MODULE_DIR_ . 'trusteed/src/';

        // class FQN (without leading namespace) => relative path under src/.
        $classes = [
            'Trusteed\\Storage\\FileCache'             => 'Storage/FileCache.php',
            'Trusteed\\Storage\\CartAgentStorage'      => 'Storage/CartAgentStorage.php',
            'Trusteed\\Enforcement\\MerchantResolver'  => 'Enforcement/MerchantResolver.php',
            'Trusteed\\Enforcement\\SnapshotClient'    => 'Enforcement/SnapshotClient.php',
            'Trusteed\\Enforcement\\TokenVerifier'     => 'Enforcement/TokenVerifier.php',
            'Trusteed\\Enforcement\\PriceSnapVerifier' => 'Enforcement/PriceSnapVerifier.php',
            'Trusteed\\Enforcement\\R043HitlGate'      => 'Enforcement/R043HitlGate.php',
            'Trusteed\\Enforcement\\ValidateOrderHook' => 'Enforcement/ValidateOrderHook.php',
            // GHSA-2j2x-5q52-g48m issue 2 — needed by onUnexpectedCelError().
            'Trusteed\\Enforcement\\SnapshotUnavailablePolicy' => 'Enforcement/SnapshotUnavailablePolicy.php',
        ];

        foreach ($classes as $fqn => $relPath) {
            if (class_exists($fqn)) {
                continue;
            }
            $file = $moduleDir . $relPath;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}
