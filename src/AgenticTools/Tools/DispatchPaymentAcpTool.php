<?php

declare(strict_types=1);

/**
 * DispatchPaymentAcpTool — delegates ACP checkout to the per-store MCP gateway
 * checkout bucket.
 *
 * OLA-1 honesty contract: the canonical catalog routes this tool to the MCP tool
 * `process_agent_payment` (backendStatus: implemented, registered in
 * tool-bucket-registry.ts) reached via `POST /:storeSlug/mcp`. The previous
 * `checkout_with_acp` tool name + `/mcp/checkout` path were never registered.
 *
 * The module does not embed a full MCP JSON-RPC client; it proxies a
 * `process_agent_payment` invocation through the BackendApiClient. The store slug
 * is resolved from the active shop context at call time.
 *
 * MCP tool name : trusteed_dispatch_payment_acp
 * Capability    : pay_with_agent
 * Backend route : POST /:storeSlug/mcp (mcp tool: process_agent_payment)
 */

namespace Trusteed\AgenticTools\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\MerchantContextGuard;
use Trusteed\AgenticTools\PermissionGuardInterface;
use Trusteed\AgenticTools\ToolToggleSettings;

final class DispatchPaymentAcpTool
{
    /** Stable catalog tool key for the FR-018b toggle (C3 defense-in-depth). */
    private const TOOL_KEY = 'dispatch-payment-acp';

    public function __construct(
        private readonly BackendApiClientInterface $apiClient,
        private readonly PermissionGuardInterface $permissionGuard,
        private readonly int $employeeId,
    ) {}

    #[McpTool(
        name: 'trusteed_dispatch_payment_acp',
        description: 'Dispatches an ACP (Agent Commerce Protocol) checkout request '
            . 'via the MCP checkout bucket. Returns session ID and redirect URL.',
    )]
    #[Schema(
        properties: [
            'cartId' => [
                'type'        => 'string',
                'description' => 'PrestaShop cart UUID to check out',
            ],
            'idempotencyKey' => [
                'type'        => 'string',
                'description' => 'Caller-supplied idempotency key (UUID v4 recommended)',
            ],
            'merchantId' => [
                'type'        => 'string',
                'description' => 'Trusteed merchant UUID',
            ],
        ],
        required: ['cartId', 'idempotencyKey', 'merchantId'],
    )]
    public function execute(string $cartId, string $idempotencyKey, string $merchantId): array
    {
        if (!$this->permissionGuard->check('pay_with_agent', $this->employeeId)) {
            throw new McpToolException(
                McpToolException::CODE_UNAUTHORIZED,
                'Insufficient permissions: pay_with_agent requires ADMINPAYMENTCART access'
            );
        }

        if ($cartId === '' || $idempotencyKey === '' || $merchantId === '') {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'cartId, idempotencyKey, and merchantId are required'
            );
        }

        // H8: reject cross-shop merchantId injected via the agent payload.
        MerchantContextGuard::enforce($merchantId);

        // C3 defense-in-depth: re-assert effective enablement at call time. The
        // add-on 96617 auto-scans #[McpTool] and may register this tool bypassing
        // getAgenticTools(); ACP is not module-fulfillable from PS (and OFF by
        // default), so stay fail-closed regardless of the registration path.
        if (!ToolToggleSettings::isEnabled(self::TOOL_KEY)) {
            throw new McpToolException(
                McpToolException::CODE_CAPABILITY_DISABLED,
                'dispatch_payment_acp is disabled for this store (FR-018b toggle off '
                . 'or not fulfillable from PrestaShop).'
            );
        }

        // OLA-1/OLA-2 honesty contract: the canonical ACP backend
        // (`process_agent_payment`) is an MCP checkout-bucket tool reached via
        // `POST /:storeSlug/mcp`. It takes NO cartId — its ownership model is
        // store_id + Stripe Connect, not an AcpCheckoutSession (see
        // apps/api .../x402-payment-guard.wire.ts). This PS module does not have
        // the store slug provisioned nor an MCP JSON-RPC client, so it cannot
        // fulfil the ACP rail today. The tool is therefore registered DISABLED
        // (ToolToggleSettings::moduleFulfillable() == false) and surfaces a
        // structured capability_disabled error if reached.
        throw new McpToolException(
            McpToolException::CODE_CAPABILITY_DISABLED,
            'ACP dispatch is not fulfillable from the PrestaShop module: the '
            . 'process_agent_payment MCP tool requires a store-slug MCP gateway '
            . 'invocation that the module does not provision. Use the x402 rail or '
            . 'the per-store MCP checkout bucket directly.'
        );
    }
}
