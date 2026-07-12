<?php

declare(strict_types=1);

/**
 * VerifyAgentSignatureTool — verifies an RFC 9421 HTTP Message Signature
 * produced by an agentic caller.
 *
 * MCP tool name : trusteed_verify_agent_signature
 * Capability    : verify_agent_signature
 * Backend route : POST /api/v1/embed/agentic-tools/verify-agent-signature
 *                 (LANE 046-F1 Option A — merchant-facing per-store S2S; replaces
 *                 the staging-only /api/v1/internal/agent-identity/verify route).
 */

namespace Trusteed\AgenticTools\Tools;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\McpToolException;
use Trusteed\AgenticTools\MerchantContextGuard;
use Trusteed\AgenticTools\PermissionGuardInterface;
use Trusteed\AgenticTools\ToolToggleSettings;

final class VerifyAgentSignatureTool
{
    /** Stable catalog tool key for the FR-018b toggle (C3 defense-in-depth). */
    private const TOOL_KEY = 'verify-agent-signature';

    public function __construct(
        private readonly BackendApiClientInterface $apiClient,
        private readonly PermissionGuardInterface $permissionGuard,
        private readonly int $employeeId,
    ) {}

    #[McpTool(
        name: 'trusteed_verify_agent_signature',
        description: 'Verifies an RFC 9421 HTTP Message Signature for an agentic request. '
            . 'Returns verification status, agent identity, and optional trust evidence.',
    )]
    #[Schema(
        properties: [
            'method' => [
                'type'        => 'string',
                'description' => 'HTTP method of the signed request (e.g. "POST")',
                'enum'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            ],
            'url' => [
                'type'        => 'string',
                'description' => 'Full URL of the signed request',
            ],
            'headers' => [
                'type'                 => 'object',
                'description'          => 'Key-value map of HTTP headers from the signed request',
                'additionalProperties' => ['type' => 'string'],
            ],
        ],
        required: ['method', 'url', 'headers'],
    )]
    public function execute(string $method, string $url, array $headers): array
    {
        if (!$this->permissionGuard->check('verify_agent_signature', $this->employeeId)) {
            throw new McpToolException(
                McpToolException::CODE_UNAUTHORIZED,
                'Insufficient permissions: verify_agent_signature requires ADMINORDERS access'
            );
        }

        if ($method === '' || $url === '' || $headers === []) {
            throw new McpToolException(
                McpToolException::CODE_VALIDATION_FAILED,
                'method, url, and headers are required'
            );
        }

        // C3 defense-in-depth: re-assert effective enablement at call time. The
        // add-on 96617 auto-scans #[McpTool] and may register this tool bypassing
        // getAgenticTools() and its FR-018b toggle. Stay fail-closed if disabled.
        if (!ToolToggleSettings::isEnabled(self::TOOL_KEY)) {
            throw new McpToolException(
                McpToolException::CODE_CAPABILITY_DISABLED,
                'verify_agent_signature is disabled for this store (FR-018b toggle off).'
            );
        }

        // LANE 046-F1 — authoritative per-store merchant id (from PS shop
        // context, never caller input) authenticates the merchant-facing S2S route.
        $merchantId = MerchantContextGuard::authoritativeMerchantId();

        return $this->apiClient->callWithS2S(
            '/api/v1/embed/agentic-tools/verify-agent-signature',
            [
                'method'  => strtoupper($method),
                'url'     => $url,
                'headers' => $headers,
            ],
            $merchantId,
        );
    }
}
