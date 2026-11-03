<?php

declare(strict_types=1);

/**
 * SignTrustReceiptTool — issues a JWS Ed25519 Trust Receipt for a PS order.
 *
 * MCP tool name : trusteed_sign_trust_receipt
 * Capability    : sign_trust_receipt
 * Backend route : (PLANNED) — no standalone "sign a receipt for an order" write
 *                 endpoint exists. The canonical catalog marks this tool
 *                 backendStatus:"planned" (read surface GET /v1/embed/trust/receipts
 *                 is live, but receipts are emitted as a side effect of the
 *                 checkout pipeline per spec-040). The tool is therefore
 *                 registered DISABLED and execute() returns a structured
 *                 capability_disabled error until a write endpoint ships.
 */

namespace Trusteed\AgenticTools\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\PermissionGuardInterface;
use Trusteed\AgenticTools\ToolToggleSettings;

final class SignTrustReceiptTool
{
    /** Stable catalog tool key for the FR-018b toggle (C3 defense-in-depth). */
    private const TOOL_KEY = 'sign-trust-receipt';

    public function __construct(
        private readonly BackendApiClientInterface $apiClient,
        private readonly PermissionGuardInterface $permissionGuard,
        private readonly int $employeeId,
    ) {}

    #[McpTool(
        name: 'trusteed_sign_trust_receipt',
        description: 'Issues a JWS Ed25519 Trust Receipt for a PrestaShop order. '
            . 'Returns the signed compact-serialisation receipt and its metadata.',
    )]
    #[Schema(
        properties: [
            'orderId' => [
                'type'        => 'string',
                'description' => 'PrestaShop numeric order ID (e.g. "42")',
            ],
            'agentId' => [
                'type'        => 'string',
                'description' => 'Optional DID or identifier of the acting agent',
            ],
        ],
        required: ['orderId'],
    )]
    public function execute(string $orderId, ?string $agentId = null): array
    {
        if (!$this->permissionGuard->check('sign_trust_receipt', $this->employeeId)) {
            throw new McpToolException(
                McpToolException::CODE_UNAUTHORIZED,
                'Insufficient permissions: sign_trust_receipt requires ADMINORDERS access'
            );
        }

        if ($orderId === '') {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'orderId must not be empty'
            );
        }

        // C3 defense-in-depth: re-assert effective enablement at call time. The
        // add-on 96617 auto-scans #[McpTool] and may register this tool bypassing
        // getAgenticTools(); if the FR-018b toggle is OFF or the tool is not
        // module-fulfillable / backendStatus "planned", stay fail-closed.
        if (!ToolToggleSettings::isEnabled(self::TOOL_KEY)) {
            throw new McpToolException(
                McpToolException::CODE_CAPABILITY_DISABLED,
                'sign_trust_receipt is disabled for this store (FR-018b toggle off '
                . 'or no deployable backend).'
            );
        }

        // OLA-1 honesty contract: no standalone receipt-sign WRITE endpoint exists
        // (catalog backendStatus:"planned"). Receipts are emitted by the checkout
        // pipeline (spec-040) and read via GET /v1/embed/trust/receipts. Return a
        // structured capability_disabled error rather than POSTing to a route that
        // does not exist.
        throw new McpToolException(
            McpToolException::CODE_CAPABILITY_DISABLED,
            'sign_trust_receipt has no deployable backend yet: Trust Receipts are '
            . 'produced by the checkout pipeline (spec-040) and read via '
            . 'GET /v1/embed/trust/receipts. Standalone receipt signing is planned.'
        );
    }
}
