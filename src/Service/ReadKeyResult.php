<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Resultado inmutable de activar o revocar la clave de lectura. La pantalla
 * del módulo traduce `code()` a un mensaje; aquí no hay texto para el usuario.
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

    /** El módulo encendió el Webservice, ya no queda ninguna clave y sigue encendido. */
    public function webserviceLeftEnabled(): bool
    {
        return $this->webserviceLeftEnabled;
    }
}
