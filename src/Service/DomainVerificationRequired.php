<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * El backend pide probar propiedad del dominio antes de entregar la tienda —
 * auditoría de onboarding 2026-09-09, hueco A3.
 *
 * No es un error del comerciante ni un fallo de red: es un paso más del alta.
 * Se distingue del resto con un tipo propio para que `handleAutoRegister()`
 * pueda publicar el token y reintentar en vez de enseñar una excepción, que es
 * lo que ocurriría si esto fuera un `RuntimeException` como los demás.
 */
final class DomainVerificationRequired extends \RuntimeException
{
    private string $challengeToken;

    public function __construct(string $challengeToken, string $message)
    {
        parent::__construct($message);
        $this->challengeToken = $challengeToken;
    }

    public function challengeToken(): string
    {
        return $this->challengeToken;
    }
}
