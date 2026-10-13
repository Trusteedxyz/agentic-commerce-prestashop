<?php

declare(strict_types=1);

/**
 * Trusteed CEL: PaymentModule override for PrestaShop 8.x.
 *
 * IMPORTANT: `actionValidateOrderBefore` does NOT exist in PS 8.2 core.
 * The only way to intercept validateOrder() before any rows are committed
 * is via the PS Override mechanism.
 *
 * This override wraps PaymentModuleCore::validateOrder() to:
 *  1. Detect agentic requests by the presence of _trusteed_agent_token in POST.
 *  2. Pull + verify the JWS-signed CEL rule snapshot offline.
 *  3. Evaluate agent token against snapshot rules.
 *  4. Throw CelVerdictException (a PrestaShopException; rolls back the order) when BLOCK.
 *  5. Call parent::validateOrder() for all other cases.
 *
 * PS 8.2 core does NOT fire `actionValidateOrderBefore`, so this override is
 * the SINGLE universal blocking point. To avoid maintaining a second, divergent
 * copy of the enforcement logic, the override DELEGATES to
 * `Trusteed\Enforcement\ValidateOrderHook::evaluateCart()`, the same code
 * path used by the PS 9+ pre-validate hook. That shared method already performs:
 *   (a) anti-replay nonce consume (SnapshotClient::consumeNonce, jti single-use),
 *   (b) R015 price-snapshot verification (PriceSnapVerifier) inside buildCartContext,
 *   (c) R023 agentDid propagation (CartAgentStorage::set) on the ALLOW path,
 * plus the full tier-2 cart context.
 *
 * Override collision handling: if another module ships an identical override,
 * PS will refuse installation with "class already overridden". The module
 * install() method checks for this and presents an admin warning.
 */
class PaymentModule extends PaymentModuleCore
{
    /**
     * Intercept order validation: the single universal blocking point for
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
            } catch (\Trusteed\Enforcement\CelVerdictException $e) {
                // Re-throw: CEL explicitly blocked this order
                throw $e;
            } catch (\Throwable $e) {
                // A failure in OUR code is neither a verdict nor a permit.
                // `evaluateCart()` already resolves its own; only outside
                // failures (cart, hook, class loading) and core
                // \PrestaShopException reach here. See onUnexpectedCelError().
                $this->onUnexpectedCelError($e, (int) $id_cart);
            }
        }

        // All checks passed (or CEL not configured): proceed with order creation
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
     * This DELEGATES to ValidateOrderHook::evaluateCart(), the single
     * canonical enforcement path shared with the PS 9+ pre-validate hook, so the
     * override gains anti-replay nonce-consume (R002/jti single-use),
     * R015 price-snapshot verification, R023 agentDid propagation
     * (CartAgentStorage::set) and the full tier-2 cart context, without keeping a
     * second divergent copy of the logic here.
     *
     * The shared method resolves the agent token itself (POST `_trusteed_agent_token`
     * → cookie fallback) and is a no-op only for unconfigured merchants / an
     * unreachable snapshot. It is NOT a no-op for human (non-agentic)
     * flows: merchant-wide policy
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

        $this->newEvaluationHook()->evaluateCart($cart);
    }

    /** Test seam: the hook that evaluates the cart. */
    protected function newEvaluationHook(): \Trusteed\Enforcement\ValidateOrderHook
    {
        return new \Trusteed\Enforcement\ValidateOrderHook();
    }

    /**
     * Handle an unexpected exception raised during CEL evaluation.
     *
     * An unexpected \Throwable is never treated as a permit. The decision is
     * made by `FailurePolicy::decideOnInternalError()` (via
     * `InternalErrorGuard`), independent of the merchant's mode: an agent is
     * held in every mode with `trusteed:CEL-INTERNAL-ERROR` and a human is
     * never blocked. If the token cannot even be read, the request is treated
     * as agentic.
     *
     * The exception's own message is logged internally; never surfaced in the
     * `\PrestaShopException` message, which PrestaShop can render to the buyer.
     *
     * @throws \PrestaShopException when an agent checkout is held.
     */
    private function onUnexpectedCelError(\Throwable $e, int $cartId): void
    {
        \Trusteed\Enforcement\InternalErrorGuard::resolve(
            $e,
            \Trusteed\Enforcement\InternalErrorGuard::requestIsAgentic(),
            $cartId
        );
    }

    /**
     * Load CEL enforcement classes via require_once as fallback
     * when composer autoloader is not active in the override context.
     *
     * ValidateOrderHook is the delegation target, so it and its
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
            // Decodes the `zip: DEF` payload (used by SnapshotClient and the hook).
            'Trusteed\\Enforcement\\SnapshotPayloadDecoder' => 'Enforcement/SnapshotPayloadDecoder.php',
            // Bounded HTTP reads and the negative JWKS cache used by SnapshotClient.
            'Trusteed\\Enforcement\\BoundedHttpGet'         => 'Enforcement/BoundedHttpGet.php',
            'Trusteed\\Enforcement\\JwksRetryVerdict'       => 'Enforcement/JwksRetryVerdict.php',
            'Trusteed\\Enforcement\\JwksCacheCodec'         => 'Enforcement/JwksCacheCodec.php',
            'Trusteed\\Enforcement\\LineItemAliases'        => 'Enforcement/LineItemAliases.php',
            'Trusteed\\Enforcement\\TokenVerifier'     => 'Enforcement/TokenVerifier.php',
            'Trusteed\\Enforcement\\PriceSnapVerifier' => 'Enforcement/PriceSnapVerifier.php',
            'Trusteed\\Enforcement\\R043HitlGate'      => 'Enforcement/R043HitlGate.php',
            'Trusteed\\Enforcement\\ValidateOrderHook' => 'Enforcement/ValidateOrderHook.php',
            // Own verdict and own failure policy
            // (onUnexpectedCelError, ValidateOrderHook::evaluateCart).
            'Trusteed\\Enforcement\\CelVerdictException'     => 'Enforcement/CelVerdictException.php',
            'Trusteed\\Enforcement\\InternalErrorGuard'      => 'Enforcement/InternalErrorGuard.php',
            // Used by ValidateOrderHook (derived routing, failure policy,
            // offline safety valve). Guarded by tests/meta/OverrideCargaLasClasesDeEnforcementTest.php.
            'Trusteed\\Enforcement\\FailurePolicy'     => 'Enforcement/FailurePolicy.php',
            'Trusteed\\Enforcement\\RuleCode'          => 'Enforcement/RuleCode.php',
            'Trusteed\\Enforcement\\OfflineSafetyValveEvaluator' => 'Enforcement/OfflineSafetyValveEvaluator.php',
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
