<?php

declare(strict_types=1);

/**
 * DispatchPaymentAp2Tool — dispatches an AP2 (Agent Payment Protocol v0.2)
 * mandate-based payment for a PrestaShop cart.
 *
 * STATUS: experimental — AP2 is FIDO Alliance draft v0.2; the backend endpoint
 * is feature-gated and may return 501 in production environments.
 *
 * MCP tool name : trusteed_dispatch_payment_ap2
 * Capability    : pay_with_agent
 * Backend route : (PLANNED) /api/v1/protocols/ap2/dispatch — does NOT exist yet.
 *                 AP2 v0.2 is dormant (spec-044, gated behind SD_JWT_ENABLED); the
 *                 adapter only exposes a dormant 503 guard. Catalog marks this
 *                 backendStatus:"planned" → tool registered DISABLED and execute()
 *                 returns a structured capability_disabled error.
 *
 * @experimental
 */

namespace Trusteed\AgenticTools\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\MerchantContextGuard;
use Trusteed\AgenticTools\PermissionGuardInterface;
use Trusteed\AgenticTools\ToolToggleSettings;

final class DispatchPaymentAp2Tool
{
    /** Stable catalog tool key for the FR-018b toggle (C3 defense-in-depth). */
    private const TOOL_KEY = 'dispatch-payment-ap2';

    public function __construct(
        private readonly BackendApiClientInterface $apiClient,
        private readonly PermissionGuardInterface $permissionGuard,
        private readonly int $employeeId,
    ) {}

    #[McpTool(
        name: 'trusteed_dispatch_payment_ap2',
        description: '[EXPERIMENTAL] Dispatches an AP2 mandate-based agentic payment '
            . 'for a PrestaShop cart using a FIDO Alliance AP2 v0.2 mandateJwt. '
            . 'Subject to breaking changes; do not use in production without explicit enablement.',
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
            'mandateJwt' => [
                'type'        => 'string',
                'description' => 'AP2 mandate JWT signed by the agent wallet',
            ],
        ],
        required: ['cartId', 'idempotencyKey', 'merchantId', 'mandateJwt'],
    )]
    public function execute(
        string $cartId,
        string $idempotencyKey,
        string $merchantId,
        string $mandateJwt,
    ): array {
        if (!$this->permissionGuard->check('pay_with_agent', $this->employeeId)) {
            throw new McpToolException(
                McpToolException::CODE_UNAUTHORIZED,
                'Insufficient permissions: pay_with_agent requires ADMINPAYMENTCART access'
            );
        }

        if ($cartId === '' || $idempotencyKey === '' || $merchantId === '' || $mandateJwt === '') {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'cartId, idempotencyKey, merchantId, and mandateJwt are required'
            );
        }

        // H8: reject cross-shop merchantId injected via the agent payload.
        MerchantContextGuard::enforce($merchantId);

        // C3 defense-in-depth: re-assert effective enablement at call time. The
        // add-on 96617 auto-scans #[McpTool] and may register this tool bypassing
        // getAgenticTools(); AP2 is "planned" + OFF by default, so stay
        // fail-closed regardless of the registration path.
        if (!ToolToggleSettings::isEnabled(self::TOOL_KEY)) {
            throw new McpToolException(
                McpToolException::CODE_CAPABILITY_DISABLED,
                'dispatch_payment_ap2 is disabled for this store (FR-018b toggle off '
                . 'or no deployable backend).'
            );
        }

        // OLA-1 honesty contract: AP2 v0.2 is DORMANT (spec-044, behind
        // SD_JWT_ENABLED). The /api/v1/protocols/ap2/dispatch route does NOT exist
        // — the adapter only exposes a dormant 503 guard. Catalog marks this
        // backendStatus:"planned"; the tool is registered DISABLED and returns a
        // structured capability_disabled error instead of POSTing to a non-route.
        throw new McpToolException(
            McpToolException::CODE_CAPABILITY_DISABLED,
            'AP2 v0.2 dispatch is dormant (spec-044, gated behind SD_JWT_ENABLED): '
            . 'no dispatch endpoint is deployed yet. This tool is advertised for '
            . 'forward-compatibility only.'
        );
    }
}
