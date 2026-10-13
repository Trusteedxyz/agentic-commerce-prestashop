<?php

declare(strict_types=1);

namespace Trusteed\Enforcement;

/**
 * Negative cache for the forced JWKS retry in {@see SnapshotClient}.
 *
 * One entry per server: the verdict of the LAST retry that failed to verify,
 * and for which `kid`. A different `kid` (a real rotation) does not inherit it,
 * and an attacker serving made-up `kid`s cannot fill the cache. The store and
 * the clock are closures supplied by the caller (tests pin the clock).
 */
final class JwksRetryVerdict
{
    /** Window in which a snapshot that already failed after the retry does not trigger another. */
    public const TTL_SECONDS = 60;

    /**
     * @param \Closure():mixed             $load  Reads the entry (anything, garbage included).
     * @param \Closure(array):void         $store Writes the entry (may throw).
     * @param \Closure():int               $now
     */
    public function __construct(
        private readonly \Closure $load,
        private readonly \Closure $store,
        private readonly \Closure $now
    ) {
    }

    /** @return string|null `tampered`|`unavailable` while the entry belongs to this `kid` and is still valid. */
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

    /** Tolerates write failures: without the cache only the saving is lost (the next checkout retries). */
    public function remember(string $kid, string $verdict): void
    {
        try {
            ($this->store)(['kid' => $kid, 'verdict' => $verdict, 'until' => ($this->now)() + self::TTL_SECONDS]);
        } catch (\Throwable) {
        }
    }
}
