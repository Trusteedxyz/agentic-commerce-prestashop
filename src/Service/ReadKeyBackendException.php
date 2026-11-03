<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Rechazo del backend al adjuntar la clave de lectura. Lleva sólo un código
 * corto (p. ej. `read_key_rejected`, `store_unreachable`): nunca la clave ni
 * el cuerpo de la petición, porque el mensaje acaba en pantalla.
 */
final class ReadKeyBackendException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason)
    {
        // Sólo caracteres de código: lo que venga del backend no se pinta tal cual.
        $this->reason = (string) preg_replace('/[^A-Za-z0-9_]/', '', substr($reason, 0, 64));
        parent::__construct('read-key: backend rejected (' . $this->reason . ')');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
