<?php

declare(strict_types=1);

namespace Trusteed\Service\PayPalSync;

/**
 * Credenciales REST de una tienda. El secreto no sale por `describe()`,
 * `print_r`, `var_dump` ni `json_encode`.
 */
final class PayPalCredentials
{
    public const STATE_OK = 'ok';
    /** El comercio no tiene PayPal configurado en NINGÚN modo (o lo vació). */
    public const STATE_ABSENT = 'absent';
    /** Hay algo configurado pero no es utilizable (no descifra, fuera de cota). */
    public const STATE_INVALID = 'invalid';

    private function __construct(
        private readonly string $state,
        private readonly string $mode,
        private readonly string $clientId,
        private readonly string $secret
    ) {
    }

    public static function ok(string $mode, string $clientId, string $secret): self
    {
        return new self(self::STATE_OK, $mode, $clientId, $secret);
    }

    public static function absent(string $mode): self
    {
        return new self(self::STATE_ABSENT, $mode, '', '');
    }

    public static function invalid(string $mode): self
    {
        return new self(self::STATE_INVALID, $mode, '', '');
    }

    public function state(): string
    {
        return $this->state;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /** sha256(client_id|secret|modo): no reversible, no contiene el secreto. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->clientId . '|' . $this->secret . '|' . $this->mode);
    }

    /** @return array{mode:string,last4:string} */
    public function describe(): array
    {
        return ['mode' => $this->mode, 'last4' => substr($this->clientId, -4)];
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['state' => $this->state, 'mode' => $this->mode];
    }
}
