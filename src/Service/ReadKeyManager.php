<?php

declare(strict_types=1);

namespace Trusteed\Service;

/**
 * «Permitir a Trusteed leer el catálogo» / «Revocar», con consentimiento.
 *
 * Por qué existe: la tienda auto-registrada no tiene clave del Webservice, así
 * que Trusteed no puede contrastar el catálogo que ven los agentes con el real
 * (C3/C4/C5 salen «sin acceso de lectura»). Esto sólo corre cuando el
 * comerciante pulsa el botón; nunca en la instalación ni en segundo plano.
 *
 * Reglas:
 *  - La clave no se guarda en la tienda más allá de la propia cuenta de
 *    Webservice: localmente sólo el id. Nunca se registra.
 *  - Si el acto no se completa, se deshace: la clave se borra y el Webservice
 *    vuelve a como estaba.
 *  - Al revocar se borra primero la clave local (deja de servir aunque el
 *    backend no conteste) y NO se apaga el Webservice: otras integraciones
 *    pueden depender de él; se avisa al comerciante.
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
            // Borrada a mano desde el back office: el id ya no significa nada.
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
            // Red, JSON… da igual el motivo: una clave que Trusteed no tiene
            // no debe quedarse viva en la tienda.
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

        // Se avisa aunque no haya id local (configuración perdida) o el borrado
        // local haya fallado: que Trusteed deje de usarla no depende de eso.
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
