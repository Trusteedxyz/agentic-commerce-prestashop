<?php

declare(strict_types=1);

/**
 * DispatchPaymentX402Tool — dispatches an x402 stablecoin payment for a cart.
 *
 * MCP tool name : trusteed_dispatch_payment_x402
 * Capability    : pay_with_agent
 * Backend route : POST /api/v1/embed/agentic-tools/dispatch-payment-x402
 *                 (LANE 046-F1 Option A — merchant-facing per-store S2S; replaces
 *                 the agent-key-gated /api/v1/agent/checkout/x402/verify route).
 */

namespace Trusteed\AgenticTools\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\MerchantContextGuard;
use Trusteed\AgenticTools\PermissionGuardInterface;
use Trusteed\AgenticTools\ToolToggleSettings;

final class DispatchPaymentX402Tool
{
    /** Stable catalog tool key for the FR-018b toggle (C3 defense-in-depth). */
    private const TOOL_KEY = 'dispatch-payment-x402';

    public function __construct(
        private readonly BackendApiClientInterface $apiClient,
        private readonly PermissionGuardInterface $permissionGuard,
        private readonly int $employeeId,
    ) {}

    #[McpTool(
        name: 'trusteed_dispatch_payment_x402',
        description: 'Dispatches an x402 stablecoin payment for a PrestaShop cart. '
            . 'Returns transaction hash and settlement confirmation.',
    )]
    #[Schema(
        properties: [
            'cartId' => [
                'type'        => 'string',
                'description' => 'PrestaShop cart UUID to settle',
            ],
            'idempotencyKey' => [
                'type'        => 'string',
                'description' => 'Caller-supplied idempotency key (UUID v4 recommended)',
            ],
            'merchantId' => [
                'type'        => 'string',
                'description' => 'Trusteed merchant UUID',
            ],
            'paymentPayload' => [
                'type'        => 'object',
                'description' => 'x402 EIP-712-signed payment payload object',
            ],
        ],
        required: ['cartId', 'idempotencyKey', 'merchantId', 'paymentPayload'],
    )]
    public function execute(
        string $cartId,
        string $idempotencyKey,
        string $merchantId,
        array $paymentPayload,
    ): array {
        if (!$this->permissionGuard->check('pay_with_agent', $this->employeeId)) {
            throw new McpToolException(
                McpToolException::CODE_UNAUTHORIZED,
                'Insufficient permissions: pay_with_agent requires ADMINPAYMENTCART access'
            );
        }

        if ($cartId === '' || $idempotencyKey === '' || $merchantId === '' || $paymentPayload === []) {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'cartId, idempotencyKey, merchantId, and paymentPayload are required'
            );
        }

        // H8: reject cross-shop merchantId injected via the agent payload.
        MerchantContextGuard::enforce($merchantId);

        // C3 defense-in-depth: re-assert effective enablement at call time. The
        // add-on 96617 auto-scans #[McpTool] and may register this tool bypassing
        // getAgenticTools() and its FR-018b toggle (x402 is OFF by default).
        // Stay fail-closed before dispatching any settlement.
        if (!ToolToggleSettings::isEnabled(self::TOOL_KEY)) {
            throw new McpToolException(
                McpToolException::CODE_CAPABILITY_DISABLED,
                'dispatch_payment_x402 is disabled for this store (FR-018b toggle off).'
            );
        }

        return $this->apiClient->callWithS2S(
            '/api/v1/embed/agentic-tools/dispatch-payment-x402',
            [
                'cartId'         => $cartId,
                'paymentPayload' => $paymentPayload,
            ],
            $merchantId,
            $idempotencyKey,
        );
    }
}
