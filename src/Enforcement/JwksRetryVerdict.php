<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Plan 04 Task 29c (L3) — caché negativa del reintento forzado del JWKS de
 * {@see SnapshotClient}.
 *
 * Una sola entrada por servidor: el veredicto del ÚLTIMO reintento que no
 * verificó y para qué `kid`. Un `kid` distinto (rotación real) no la hereda y
 * un atacante que sirva `kid`s inventados no puede llenar la caché. El
 * almacén y el reloj son cierres del llamador (los tests fijan el reloj).
 */
final class JwksRetryVerdict
{
    /** Ventana en la que un snapshot que ya falló tras el reintento no provoca otro. */
    public const TTL_SECONDS = 60;

    /**
     * @param \Closure():mixed             $load  Lee la entrada (cualquier cosa, incluida basura).
     * @param \Closure(array):void         $store Escribe la entrada (puede lanzar).
     * @param \Closure():int               $now
     */
    public function __construct(
        private readonly \Closure $load,
        private readonly \Closure $store,
        private readonly \Closure $now
    ) {
    }

    /** @return string|null `tampered`|`unavailable` mientras la entrada sea de este `kid` y siga vigente. */
    public function read(string $kid): ?string
    {
        $entry = ($this->load)();
        if (
            !is_array($entry)
            || ($entry['kid'] ?? null) !== $kid
            || !in_array($entry['verdict'] ?? null, [SnapshotClient::PULL_TAMPERED, SnapshotClient::PULL_UNAVAILABLE], true)
            || (int) ($entry['until'] ?? 0) <= ($this->now)()
        ) {
            return null;
        }

        return (string) $entry['verdict'];
    }

    /** Tolerante a fallos de escritura: sin caché sólo se pierde el ahorro (el siguiente checkout reintenta). */
    public function remember(string $kid, string $verdict): void
    {
        try {
            ($this->store)(['kid' => $kid, 'verdict' => $verdict, 'until' => ($this->now)() + self::TTL_SECONDS]);
        } catch (\Throwable) {
        }
    }
}
