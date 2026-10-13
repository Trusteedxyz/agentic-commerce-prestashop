<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * Backend rejection when attaching the read key. It carries only a short code
 * (e.g. `read_key_rejected`, `store_unreachable`): never the key or the request
 * body, because the message ends up on screen.
 */
final class ReadKeyBackendException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $reason)
    {
        // Code characters only: whatever comes from the backend is not rendered as is.
        $this->reason = (string) preg_replace('/[^A-Za-z0-9_]/', '', substr($reason, 0, 64));
        parent::__construct('read-key: backend rejected (' . $this->reason . ')');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
