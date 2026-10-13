<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * "Allow Trusteed to read the catalog" / "Revoke", with consent.
 *
 * Why it exists: a self-registered store has no Webservice key, so Trusteed
 * cannot compare the catalog agents see with the real one (the coherence
 * checks report "no read access"). This only runs when the merchant presses
 * the button, never at install time or in the background.
 *
 * Rules:
 *  - The key is not stored in the shop beyond the Webservice account itself:
 *    locally only the id is kept. It is never logged.
 *  - If the action does not complete, it is rolled back: the key is deleted
 *    and the Webservice setting returns to how it was.
 *  - On revoke the local key is deleted first (it stops working even if the
 *    backend does not answer) and the Webservice is NOT turned off: other
 *    integrations may depend on it, so the merchant is warned instead.
 */
final class ReadKeyManager
{
    public function __construct(
        private ReadKeyEnvironment $env,
        private ReadKeyBackend $backend,
    ) {
    }

    public function enable(string $storeUrl, string $installKey): ReadKeyResult
    {
        if ($installKey === '') {
            return ReadKeyResult::failure('not_registered');
        }

        $existingId = $this->env->storedKeyId();
        if ($existingId > 0) {
            if ($this->env->keyExists($existingId)) {
                return ReadKeyResult::success('already_enabled');
            }
            // Deleted by hand from the back office: the stored id no longer means anything.
            $this->env->storeKeyId(0);
        }

        $wasEnabled = $this->env->webserviceEnabled();
        if (!$wasEnabled) {
            $this->env->enableWebservice();
        }

        $key = ReadOnlyWebserviceKey::generate();
        $id = $this->env->createKey(
            $key,
            ReadOnlyWebserviceKey::DESCRIPTION,
            ReadOnlyWebserviceKey::permissions()
        );
        if ($id <= 0) {
            $this->restoreWebservice($wasEnabled);

            return ReadKeyResult::failure('key_creation_failed');
        }

        try {
            $this->backend->attachReadKey($storeUrl, $installKey, $key);
        } catch (ReadKeyBackendException $e) {
            $this->env->deleteKey($id);
            $this->restoreWebservice($wasEnabled);

            return ReadKeyResult::failure('backend_rejected', $e->reason());
        } catch (\Throwable $e) {
            // Network, JSON, whatever the reason: a key that Trusteed does not
            // hold must not stay alive in the store.
            $this->env->deleteKey($id);
            $this->restoreWebservice($wasEnabled);

            return ReadKeyResult::failure('backend_rejected', 'network_error');
        }

        $this->env->storeKeyId($id);
        if (!$wasEnabled) {
            $this->env->setEnabledByModule(true);
        }

        return ReadKeyResult::success('enabled');
    }

    public function revoke(string $storeUrl, string $installKey): ReadKeyResult
    {
        $id = $this->env->storedKeyId();
        $deletedLocally = true;
        if ($id > 0 && $this->env->keyExists($id)) {
            $deletedLocally = $this->env->deleteKey($id);
        }

        // Notify even without a local id (lost configuration) or after a failed
        // local delete: Trusteed dropping the key does not depend on either.
        $notified = $installKey !== '' && $this->backend->revokeReadKey($storeUrl, $installKey);

        if (!$deletedLocally) {
            return ReadKeyResult::failure('key_deletion_failed', '', $notified);
        }

        $leftEnabled = $this->env->enabledByModule()
            && $this->env->webserviceEnabled()
            && $this->env->countKeys() === 0;

        $this->env->storeKeyId(0);
        $this->env->setEnabledByModule(false);

        if ($id <= 0) {
            return ReadKeyResult::success('not_enabled', $notified, $leftEnabled);
        }

        return ReadKeyResult::success(
            $notified ? 'revoked' : 'revoked_backend_pending',
            $notified,
            $leftEnabled
        );
    }

    private function restoreWebservice(bool $wasEnabled): void
    {
        if (!$wasEnabled) {
            $this->env->disableWebservice();
        }
    }
}
