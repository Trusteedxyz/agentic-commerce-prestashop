<?php

declare(strict_types=1);

/**
 * Fakes.php — deterministic test doubles implementing the production seams
 * (BackendApiClientInterface / PermissionGuardInterface).
 *
 * These are REAL implementations of the production interfaces — NOT
 * re-implementations of the tool classes. The tests instantiate the REAL tool
 * classes (SignTrustReceiptTool, etc.) and inject these fakes for the external
 * dependencies (HTTP transport + PS permission check). This is the H1 fix: the
 * previous tests defined `MockSignTrustReceiptTool` and tested THAT, never the
 * real tool.
 */

namespace Trusteed\Tests\AgenticTools\Tools;

use Trusteed\AgenticTools\BackendApiClientInterface;
use Trusteed\AgenticTools\PermissionGuardInterface;

/**
 * Records calls and returns a canned response (or throws a preset exception).
 */
final class FakeBackendApiClient implements BackendApiClientInterface
{
    /** @var list<array{endpoint:string,payload:array<string,mixed>,idempotencyKey:?string}> */
    public array $calls = [];

    /** @var array<string,mixed> */
    private array $response;

    private ?\Throwable $throwable = null;

    /**
     * @param array<string,mixed> $response
     */
    public function __construct(array $response = ['ok' => true])
    {
        $this->response = $response;
    }

    public function willThrow(\Throwable $t): void
    {
        $this->throwable = $t;
    }

    public function call(string $endpoint, array $payload, ?string $idempotencyKey = null): array
    {
        $this->calls[] = [
            'endpoint'       => $endpoint,
            'payload'        => $payload,
            'idempotencyKey' => $idempotencyKey,
        ];

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->response;
    }

    public function callWithS2S(
        string $endpoint,
        array $payload,
        string $merchantId,
        ?string $idempotencyKey = null
    ): array {
        // Mirror production: inject merchant_id; record in the same buffer so
        // existing endpoint/payload assertions still apply (plus an s2s marker).
        $payload['merchant_id'] = $merchantId;
        $this->calls[] = [
            'endpoint'       => $endpoint,
            'payload'        => $payload,
            'idempotencyKey' => $idempotencyKey,
            's2s'            => true,
            'merchantId'     => $merchantId,
        ];

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->response;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return array{endpoint:string,payload:array<string,mixed>,idempotencyKey:?string}|null */
    public function lastCall(): ?array
    {
        return $this->calls[count($this->calls) - 1] ?? null;
    }
}

/**
 * Permission decision driven by a fixed boolean; records the capabilities checked.
 */
final class FakePermissionGuard implements PermissionGuardInterface
{
    /** @var list<array{capability:string,employeeId:int}> */
    public array $checks = [];

    public function __construct(private bool $grant = true) {}

    public function check(string $abstractCapability, int $employeeId): bool
    {
        $this->checks[] = ['capability' => $abstractCapability, 'employeeId' => $employeeId];
        return $this->grant;
    }
}
