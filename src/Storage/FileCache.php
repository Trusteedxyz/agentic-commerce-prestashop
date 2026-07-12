<?php

declare(strict_types=1);

namespace Trusteed\Storage;

/**
 * B5 — Persistent file-backed cache for cross-request state.
 *
 * Replaces silent APCu fallbacks in `TokenVerifier::isNonceNew()`,
 * `SnapshotClient::cacheGet/Set()`, and `ValidateOrderHook` R004 first-seen
 * tracking. Plain PrestaShop deployments often run without APCu, where the
 * previous code silently degraded (allowing nonce replays and missing R004
 * key-age detection on every request).
 *
 * On-disk format per entry (one file per key, name = sha256(key)):
 *   { "v": <value>, "e": <unix_expiry> }
 *
 * Writes use `LOCK_EX` to prevent torn reads. `addIfAbsent()` provides the
 * atomic primitive used for nonce dedup and key first-seen.
 */
final class FileCache
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('v', $data) || !isset($data['e'])) {
            return null;
        }
        if ((int) $data['e'] <= time()) {
            @unlink($path);
            return null;
        }
        return $data['v'];
    }

    public function getInt(string $key): int
    {
        $value = $this->get($key);
        return is_numeric($value) ? (int) $value : 0;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $path = $this->pathFor($key);
        $payload = (string) json_encode(['v' => $value, 'e' => time() + max(1, $ttlSeconds)]);
        // Atomic write: tmp file + rename, with exclusive lock on the tmp.
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            return;
        }
        try {
            if (@flock($fh, LOCK_EX)) {
                @fwrite($fh, $payload);
                @fflush($fh);
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Atomic check-and-insert. Returns true only for the first writer of a
     * non-expired key. Used for nonce dedup and R004 key first-seen.
     */
    public function addIfAbsent(string $key, mixed $value, int $ttlSeconds): bool
    {
        $path = $this->pathFor($key);
        // O_EXCL ensures atomicity — only one writer creates the file.
        $fh = @fopen($path, 'xb');
        if ($fh === false) {
            // Either exists or unwritable — check if existing entry is still live.
            if ($this->get($key) !== null) {
                return false;
            }
            // Expired or unreadable — overwrite atomically.
            $this->set($key, $value, $ttlSeconds);
            return true;
        }
        try {
            $payload = (string) json_encode(['v' => $value, 'e' => time() + max(1, $ttlSeconds)]);
            if (@flock($fh, LOCK_EX)) {
                @fwrite($fh, $payload);
                @fflush($fh);
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }
        return true;
    }

    private function pathFor(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }
}
