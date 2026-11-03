<?php

declare(strict_types=1);

/**
 * export-tool-schemas.php — PrestaShop platform schema exporter.
 *
 * Standalone script: NO PrestaShop runtime required.
 * Emits the 5 canonical Trusteed tool schemas as JSON to stdout.
 *
 * OLA-1: output format mirrors packages/shared/tool-schemas.canonical.json, where
 * the legacy `mcpToolName` string was replaced by a `routing` object
 *   { kind, target, backendStatus, notes }
 * Output:
 *   { "version": "1.0.0", "tools": [{ "id", "inputSchema", "outputSchema", "routing" }] }
 *
 * Keys are sorted alphabetically at every level for stable diffs. This script
 * MUST emit byte-identical output to the TS exporter
 * (packages/shared/scripts/export-tool-schemas.ts) so cross-platform parity
 * diffs stay green. Data below is derived from the canonical CATALOG SSOT
 * (packages/shared/src/agentic-tools/catalog.ts).
 *
 * Usage:
 *   php packages/prestashop-module-agenticmcpstores/scripts/export-tool-schemas.php
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Recursively sort all object keys alphabetically.
 *
 * @param mixed $value
 * @return mixed
 */
function sortKeys(mixed $value): mixed
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map('sortKeys', $value);
        }
        ksort($value);
        return array_map('sortKeys', $value);
    }
    return $value;
}

// ---------------------------------------------------------------------------
// Canonical schema definitions (derived from packages/shared catalog SSOT).
// OLA-1 routing form. Hardcoded intentionally — no PS bootstrap required.
// ---------------------------------------------------------------------------

$CATALOG_VERSION = '1.0.0';

$tools = [
        [
            'id' => 'trusteed/sign-trust-receipt',
            'inputSchema' => [
                'additionalProperties' => false,
                'properties' => [
                    'agentId' => [
                        'description' => 'Optional agent DID or identifier; embedded in the JWS receipt claims',
                        'type' => 'string',
                    ],
                    'orderId' => [
                        'description' => 'Platform order identifier (e.g. WooCommerce order ID, PS cart reference, Odoo sale.order.id)',
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'orderId',
                ],
                'type' => 'object',
            ],
            'outputSchema' => [
                'properties' => [
                    'issuedAt' => [
                        'format' => 'date-time',
                        'type' => 'string',
                    ],
                    'jws' => [
                        'description' => 'Compact JWS (header.payload.signature) — Ed25519 signed',
                        'type' => 'string',
                    ],
                    'jws_url' => [
                        'description' => 'Public URL where the JWS can be verified out-of-band',
                        'type' => 'string',
                    ],
                    'receiptId' => [
                        'description' => 'Opaque receipt UUID',
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'receiptId',
                    'jws',
                ],
                'type' => 'object',
            ],
            'routing' => [
                'backendStatus' => 'planned',
                'kind' => 'rest',
                'notes' => 'Read surface (GET /v1/embed/trust/receipts) is live, but a standalone POST to sign an arbitrary order\'s receipt does not exist. Receipts are produced by the checkout tools (process_agent_payment / complete_checkout) per spec-040. Platform modules MUST surface this tool as planned/disabled until a write endpoint ships.',
                'target' => '/v1/embed/trust/receipts',
            ],
        ],
        [
            'id' => 'trusteed/verify-agent-signature',
            'inputSchema' => [
                'additionalProperties' => false,
                'properties' => [
                    'headers' => [
                        'additionalProperties' => [
                            'type' => 'string',
                        ],
                        'description' => 'Headers map including Signature, Signature-Input, Content-Digest',
                        'type' => 'object',
                    ],
                    'method' => [
                        'description' => 'HTTP method of the signed request',
                        'type' => 'string',
                    ],
                    'url' => [
                        'description' => 'Full URL of the signed request',
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'method',
                    'url',
                    'headers',
                ],
                'type' => 'object',
            ],
            'outputSchema' => [
                'properties' => [
                    'keyId' => [
                        'type' => 'string',
                    ],
                    'provider' => [
                        'type' => 'string',
                    ],
                    'source' => [
                        'type' => 'string',
                    ],
                    'verificationStatus' => [
                        'enum' => [
                            'verified',
                            'unverified',
                            'spoofed',
                            'skipped',
                        ],
                        'type' => 'string',
                    ],
                    'verified' => [
                        'type' => 'boolean',
                    ],
                    'verifiedAt' => [
                        'format' => 'date-time',
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'verified',
                    'verificationStatus',
                ],
                'type' => 'object',
            ],
            'routing' => [
                'backendStatus' => 'implemented',
                'kind' => 'rest',
                'notes' => 'Live spec-045 endpoint. Runtime-gated behind AGENT_IDENTITY_INTERNAL_VERIFY_ENABLED=true and internal auth; returns 503 agent_identity_internal_verify_disabled when the flag is off. This is a deployment/runtime gate, NOT a catalog-advertisement gate — the tool is always advertised (catalog `featureFlag` is reserved for advertisement gating like AP2/SD_JWT_ENABLED).',
                'target' => '/api/v1/internal/agent-identity/verify',
            ],
        ],
        [
            'id' => 'trusteed/dispatch-payment-acp',
            'inputSchema' => [
                'additionalProperties' => false,
                'properties' => [
                    'cartId' => [
                        'description' => 'Cart or order reference',
                        'type' => 'string',
                    ],
                    'idempotencyKey' => [
                        'description' => 'Caller-supplied idempotency key (required to prevent double-charge)',
                        'type' => 'string',
                    ],
                    'merchantId' => [
                        'description' => 'Trusteed merchant identifier',
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'cartId',
                    'idempotencyKey',
                    'merchantId',
                ],
                'type' => 'object',
            ],
            'outputSchema' => [
                'properties' => [
                    'jws' => [
                        'type' => 'string',
                    ],
                    'paymentId' => [
                        'type' => 'string',
                    ],
                    'receiptId' => [
                        'type' => 'string',
                    ],
                    'status' => [
                        'enum' => [
                            'succeeded',
                            'pending',
                            'failed',
                        ],
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'paymentId',
                    'status',
                ],
                'type' => 'object',
            ],
            'routing' => [
                'backendStatus' => 'implemented',
                'kind' => 'mcp-tool',
                'notes' => 'Routed through POST /:storeSlug/mcp (checkout bucket). process_agent_payment is registered in tool-bucket-registry.ts (checkout, requiresAuth + idempotency). There is no dedicated /api/v1/protocols/acp/dispatch REST endpoint — the MCP checkout bucket is the real surface.',
                'target' => 'process_agent_payment',
            ],
        ],
        [
            'id' => 'trusteed/dispatch-payment-x402',
            'inputSchema' => [
                'additionalProperties' => false,
                'properties' => [
                    'cartId' => [
                        'type' => 'string',
                    ],
                    'idempotencyKey' => [
                        'type' => 'string',
                    ],
                    'merchantId' => [
                        'type' => 'string',
                    ],
                    'paymentPayload' => [
                        'additionalProperties' => true,
                        'description' => 'x402 payment payload including signature and chain details (V1 body form)',
                        'type' => 'object',
                    ],
                ],
                'required' => [
                    'cartId',
                    'idempotencyKey',
                    'merchantId',
                    'paymentPayload',
                ],
                'type' => 'object',
            ],
            'outputSchema' => [
                'properties' => [
                    'jws' => [
                        'type' => 'string',
                    ],
                    'paymentId' => [
                        'type' => 'string',
                    ],
                    'receiptId' => [
                        'type' => 'string',
                    ],
                    'status' => [
                        'enum' => [
                            'succeeded',
                            'pending',
                            'failed',
                        ],
                        'type' => 'string',
                    ],
                    'txHash' => [
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'paymentId',
                    'status',
                ],
                'type' => 'object',
            ],
            'routing' => [
                'backendStatus' => 'implemented',
                'kind' => 'rest',
                'notes' => 'Live spec-035 endpoint. Requires agent-key auth and a PAYMENT-SIGNATURE (V2) header or a V1 paymentPayload body. Returns 202 (fire-and-forget settlement); the Trust Receipt is fetched via the trust_receipt_polling_url in the response.',
                'target' => '/api/v1/agent/checkout/x402/verify',
            ],
        ],
        [
            'id' => 'trusteed/dispatch-payment-ap2',
            'inputSchema' => [
                'additionalProperties' => false,
                'properties' => [
                    'cartId' => [
                        'type' => 'string',
                    ],
                    'idempotencyKey' => [
                        'type' => 'string',
                    ],
                    'mandateJwt' => [
                        'description' => 'AP2 SD-JWT compact mandate from the agent wallet',
                        'type' => 'string',
                    ],
                    'merchantId' => [
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'cartId',
                    'idempotencyKey',
                    'merchantId',
                    'mandateJwt',
                ],
                'type' => 'object',
            ],
            'outputSchema' => [
                'properties' => [
                    'jws' => [
                        'type' => 'string',
                    ],
                    'mandateJwt' => [
                        'description' => 'Signed checkout mandate returned by server',
                        'type' => 'string',
                    ],
                    'paymentId' => [
                        'type' => 'string',
                    ],
                    'receiptId' => [
                        'type' => 'string',
                    ],
                    'status' => [
                        'enum' => [
                            'succeeded',
                            'pending',
                            'failed',
                        ],
                        'type' => 'string',
                    ],
                ],
                'required' => [
                    'paymentId',
                    'status',
                ],
                'type' => 'object',
            ],
            'routing' => [
                'backendStatus' => 'planned',
                'kind' => 'rest',
                'notes' => 'AP2 v0.2 is dormant (spec-044, behind SD_JWT_ENABLED). The /api/v1/protocols/ap2/dispatch route does NOT exist yet — the AP2 adapter currently only exposes a dormant guard (503). Advertised as planned so platform modules register the tool disabled until spec-044 ships a dispatch route.',
                'target' => '/api/v1/protocols/ap2/dispatch',
            ],
        ],
    ]
;

// ---------------------------------------------------------------------------
// Build and emit canonical output
// ---------------------------------------------------------------------------

$canonical = sortKeys([
    'tools'   => array_map('sortKeys', $tools),
    'version' => $CATALOG_VERSION,
]);

echo json_encode($canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
