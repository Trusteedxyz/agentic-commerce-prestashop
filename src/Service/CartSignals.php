<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Spec-048 Sprint E (T-E39, ADR-054) — Agentic Starter Kit signals for PrestaShop.
 *
 * Mirror of WooCommerce `AgenticMCP_Cart_Signals` and Magento
 * `Trusteed\AgenticMcpStores\Service\CartSignals` Sprint E helpers.
 *
 *   R032 (blocked-category) — PS Category lineage match (id_category or default_category).
 *   R034 (blocked-sku-list) — Product reference / EAN13 canonical SKU lookup.
 *   R038 (max-items-per-order) — Σ quantities across CartProduct rows.
 *   R039 (max-quantity-per-sku) — per-line quantity max.
 *   R048 (no-digital-goods-for-agents) — Product::$is_virtual + giftcard detection.
 *
 * R031 (kill-switch) + R042 (history) + R043 (HITL) live outside this class.
 *
 * Source mapping for PrestaShop:
 *   - R032: Cart::getProducts() row['id_category_default'] + ProductCore::getProductCategories()
 *   - R034: Cart::getProducts() row['reference'] (canonical SKU) — fallback to row['ean13']
 *   - R048: Cart::getProducts() row['is_virtual'] === '1' → "downloadable"
 *           giftcard detection via reserved category id or product reference prefix
 *
 * Items are wrapped by the observer into primitive dicts so these pure
 * functions can be unit-tested without a PrestaShop kernel.
 *
 * @deprecated M1 (audit 2026-06) — these signal helpers (R032/R034/R035/R036/
 *   R038/R039/R048) are NOT wired to any production caller. The corresponding
 *   rules are evaluated AUTHORITATIVELY server-side by the backend tier-2 path
 *   (SnapshotClient::callRulesEvaluate) from the cart context that
 *   ValidateOrderHook::buildCartContext already extracts (line items, category
 *   slugs, totals). Re-deriving the same verdicts locally would create a second,
 *   divergent enforcement source. Retained (not deleted) for the documented PS
 *   field mapping and existing unit tests. If a future offline-enforcement mode
 *   needs these, route them through buildCartContext and reconcile with the
 *   backend rule catalog (packages/shared/src/enforcement/rule-catalog.ts) first.
 */
final class CartSignals
{
    /**
     * PrestaShop giftcard product reference prefixes commonly used by modules
     * (e.g. `pm_giftcard`, the Pretashop addons giftcard product type, etc.).
     * Substring (lower-case) match.
     */
    private const GIFT_CARD_REFERENCE_NEEDLES = [
        'giftcard',
        'gift-card',
        'gift_card',
        'pm_giftcard',
        'vale-regalo',
        'tarjeta-regalo',
    ];

    /**
     * R032 signal: returns true iff any cart item has a category id in $blocked.
     *
     * @param array<int,array<string,mixed>> $items each item: ['categoryIds' => array<int,int|string>]
     * @param array<int,string|int> $blocked
     */
    public static function r032HasBlockedCategory(array $items, array $blocked): bool
    {
        if (empty($blocked)) {
            return false;
        }
        $blockedSet = [];
        foreach ($blocked as $b) {
            $key = (string) $b;
            if ($key !== '') {
                $blockedSet[$key] = true;
            }
        }
        if ($blockedSet === []) {
            return false;
        }
        foreach ($items as $item) {
            $cats = $item['categoryIds'] ?? [];
            if (!is_array($cats)) {
                continue;
            }
            foreach ($cats as $cat) {
                $key = (string) $cat;
                if ($key !== '' && isset($blockedSet[$key])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * R034 signal: returns true iff any cart item SKU (reference) is in $blocked.
     * Canonical id for PS = `reference`; falls back to `ean13` if reference empty.
     *
     * @param array<int,array<string,mixed>> $items each item: ['sku' => string]
     * @param string[] $blocked
     */
    public static function r034HasBlockedSku(array $items, array $blocked): bool
    {
        if (empty($blocked)) {
            return false;
        }
        $blockedSet = array_fill_keys(array_values(array_filter($blocked, 'is_string')), true);
        if ($blockedSet === []) {
            return false;
        }
        foreach ($items as $item) {
            $sku = $item['sku'] ?? '';
            if (is_string($sku) && $sku !== '' && isset($blockedSet[$sku])) {
                return true;
            }
        }
        return false;
    }

    /**
     * R038 signal: returns total item count Σ qty (negative-qty guard).
     *
     * @param array<int,array<string,mixed>> $items each item: ['quantity' => int]
     */
    public static function r038ItemCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty > 0) {
                $count += $qty;
            }
        }
        return $count;
    }

    /**
     * R039 signal: returns max per-line quantity (0 if cart empty).
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function r039MaxLineQuantity(array $items): int
    {
        $max = 0;
        foreach ($items as $item) {
            $qty = (int) ($item['quantity'] ?? 0);
            if ($qty > $max) {
                $max = $qty;
            }
        }
        return $max;
    }

    /**
     * R048 signal: returns comma-separated digital good types present in cart.
     *
     * Source mapping for PrestaShop:
     *   - row['is_virtual'] === '1' OR (int)1 → "downloadable"
     *   - row['reference'] / row['name'] contains giftcard needle → "gift_card"
     *
     * @param array<int,array<string,mixed>> $items each item: ['is_virtual' => bool|int|string, 'reference' => string, 'name' => string]
     */
    /**
     * R035 (max-order-value) — HIT iff cart total (with tax, BOTH) exceeds cap.
     *
     * Mirrors `evaluateR035` in `packages/shared/src/enforcement/rule-catalog.ts`.
     * Boundary inclusive (strict `>`). Missing `maxCents` param → no-op PASS.
     *
     * @param \Cart $cart
     * @param array<string,mixed> $params expects ['maxCents' => int]
     * @return array{hit:bool,reason?:string}
     */
    public function evaluateR035(\Cart $cart, array $params): array
    {
        if (!array_key_exists('maxCents', $params) || $params['maxCents'] === null) {
            return ['hit' => false];
        }
        $cap = (int) $params['maxCents'];
        $total = (int) round(((float) $cart->getOrderTotal(true, \Cart::BOTH)) * 100);
        if ($total > $cap) {
            return [
                'hit' => true,
                'reason' => "cart total {$total} exceeds cap {$cap}",
            ];
        }
        return ['hit' => false];
    }

    /**
     * R036 (max-line-item-value) — HIT iff any line subtotal exceeds cap.
     *
     * PrestaShop `Cart::getProducts()` rows expose `total_wt` = line total
     * with tax (qty already multiplied). Boundary inclusive (strict `>`).
     * Returns the first offending line (deterministic, matches catalog
     * `evaluateR036` `lineItems.find` semantics). Missing param → PASS.
     *
     * @param \Cart $cart
     * @param array<string,mixed> $params expects ['maxCents' => int]
     * @return array{hit:bool,reason?:string}
     */
    public function evaluateR036(\Cart $cart, array $params): array
    {
        if (!array_key_exists('maxCents', $params) || $params['maxCents'] === null) {
            return ['hit' => false];
        }
        $cap = (int) $params['maxCents'];
        foreach ($cart->getProducts() as $product) {
            $lineCents = (int) round(((float) ($product['total_wt'] ?? 0)) * 100);
            if ($lineCents > $cap) {
                $idProduct = $product['id_product'] ?? '?';
                return [
                    'hit' => true,
                    'reason' => "line {$idProduct} value {$lineCents} exceeds cap {$cap}",
                ];
            }
        }
        return ['hit' => false];
    }

    public static function r048DigitalGoodTypes(array $items): string
    {
        $types = [];
        foreach ($items as $item) {
            $ref  = strtolower((string) ($item['reference'] ?? ''));
            $name = strtolower((string) ($item['name'] ?? ''));
            $isGiftCard = false;
            foreach (self::GIFT_CARD_REFERENCE_NEEDLES as $needle) {
                if ($ref !== '' && strpos($ref, $needle) !== false) {
                    $isGiftCard = true;
                    break;
                }
                if ($name !== '' && strpos($name, $needle) !== false) {
                    $isGiftCard = true;
                    break;
                }
            }
            if ($isGiftCard) {
                $types['gift_card'] = true;
                continue;
            }
            $virtual = $item['is_virtual'] ?? false;
            $isVirtual = $virtual === true
                || $virtual === 1
                || $virtual === '1'
                || (is_string($virtual) && strtolower($virtual) === 'true');
            if ($isVirtual) {
                $types['downloadable'] = true;
            }
        }
        return implode(',', array_keys($types));
    }
}
