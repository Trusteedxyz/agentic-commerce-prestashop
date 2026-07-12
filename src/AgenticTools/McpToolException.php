<?php

declare(strict_types=1);

/**
 * McpToolException — structured MCP tool error (spec-046 FR-014).
 *
 * FR-014 requires that errors surfaced by the AgenticTools carry a stable,
 * machine-readable `code` so the MCP server / AI app can react deterministically
 * instead of parsing free-form RuntimeException messages.
 *
 * Canonical codes (spec-046 §FR-014):
 *   - capability_disabled       → the merchant has not enabled this tool (FR-018b
 *                                 toggle OFF) OR the catalog marks the backend as
 *                                 `planned` (no deployable backend yet).
 *   - merchant_not_provisioned  → backend returned 404 / 409 indicating the
 *                                 merchant has no provisioned resource for the call.
 *   - trusteed_unreachable   → transient transport failure (curl error / 5xx)
 *                                 after the single retry.
 *   - validation_failed         → input validation failed locally OR backend
 *                                 returned 400/422.
 *   - unauthorized              → permission guard denied OR backend returned
 *                                 401 / 403.
 *
 * The exception carries the HTTP status (when applicable) so callers and tests
 * can assert the mapping without re-deriving it.
 */

namespace Trusteed\AgenticTools;

final class McpToolException extends \RuntimeException
{
    public const CODE_CAPABILITY_DISABLED      = 'capability_disabled';
    public const CODE_MERCHANT_NOT_PROVISIONED = 'merchant_not_provisioned';
    public const CODE_UNREACHABLE              = 'trusteed_unreachable';
    public const CODE_VALIDATION_FAILED        = 'validation_failed';
    public const CODE_UNAUTHORIZED             = 'unauthorized';

    /**
     * @var list<string>
     */
    private const VALID_CODES = [
        self::CODE_CAPABILITY_DISABLED,
        self::CODE_MERCHANT_NOT_PROVISIONED,
        self::CODE_UNREACHABLE,
        self::CODE_VALIDATION_FAILED,
        self::CODE_UNAUTHORIZED,
    ];

    public function __construct(
        private readonly string $mcpCode,
        string $message,
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The stable MCP error code (one of the CODE_* constants).
     */
    public function getMcpCode(): string
    {
        return $this->mcpCode;
    }

    /**
     * The originating backend HTTP status, when the error came from a response.
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * True when $code is one of the canonical FR-014 codes.
     */
    public static function isValidCode(string $code): bool
    {
        return in_array($code, self::VALID_CODES, true);
    }

    /**
     * Maps a backend HTTP status to the canonical FR-014 error code.
     *
     * 4xx are deterministic client errors (no retry); 5xx and 0 (transport)
     * map to trusteed_unreachable.
     */
    public static function codeForHttpStatus(int $httpStatus): string
    {
        return match (true) {
            $httpStatus === 401, $httpStatus === 403 => self::CODE_UNAUTHORIZED,
            $httpStatus === 400, $httpStatus === 422 => self::CODE_VALIDATION_FAILED,
            $httpStatus === 404, $httpStatus === 409 => self::CODE_MERCHANT_NOT_PROVISIONED,
            default                                  => self::CODE_UNREACHABLE,
        };
    }

    /**
     * Convenience constructor that derives the MCP code from an HTTP status.
     */
    public static function fromHttpStatus(int $httpStatus, string $message): self
    {
        return new self(self::codeForHttpStatus($httpStatus), $message, $httpStatus);
    }
}
