<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Immutable result of enabling or revoking the read key. The module screen
 * translates `code()` into a message; there is no user-facing text here.
 */
final class ReadKeyResult
{
    private function __construct(
        private bool $ok,
        private string $code,
        private string $detail = '',
        private bool $backendNotified = false,
        private bool $webserviceLeftEnabled = false,
    ) {
    }

    public static function success(string $code, bool $backendNotified = true, bool $webserviceLeftEnabled = false): self
    {
        return new self(true, $code, '', $backendNotified, $webserviceLeftEnabled);
    }

    public static function failure(string $code, string $detail = '', bool $backendNotified = false): self
    {
        return new self(false, $code, $detail, $backendNotified);
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function backendNotified(): bool
    {
        return $this->backendNotified;
    }

    /** The module turned the Webservice on, no keys remain, and it is still on. */
    public function webserviceLeftEnabled(): bool
    {
        return $this->webserviceLeftEnabled;
    }
}
