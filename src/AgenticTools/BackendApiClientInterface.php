<?php

declare(strict_types=1);

/**
 * BackendApiClientInterface — seam for the Trusteed backend HTTP client.
 *
 * Introduced (spec-046 H1) so the AgenticTools can be unit-tested against the
 * REAL tool classes while injecting a deterministic fake transport, instead of
 * the previous tautological tests that exercised inline mock tool re-implementations.
 *
 * The production implementation is BackendApiClient (final, cURL-backed).
 */

namespace Trusteed\AgenticTools;

interface BackendApiClientInterface
{
    /**
     * POST JSON $payload to $endpoint and return the decoded response array.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws McpToolException on non-2xx, transport failure, or invalid input
     */
    public function call(string $endpoint, array $payload, ?string $idempotencyKey = null): array;

    /**
     * POST JSON $payload using per-store S2S authentication (LANE 046-F1
     * Option A). Sends the `X-Trusteed-S2S-Secret` header (the per-store secret
     * provisioned at onboarding) and injects `merchant_id` into the body — for
     * the merchant-facing `/api/v1/embed/agentic-tools/*` routes which are NOT
     * agent-key-gated. The bootstrap Bearer is NOT sent for these routes.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     *
     * @throws McpToolException on non-2xx, transport failure, or invalid input
     */
    public function callWithS2S(
        string $endpoint,
        array $payload,
        string $merchantId,
        ?string $idempotencyKey = null
    ): array;
}
