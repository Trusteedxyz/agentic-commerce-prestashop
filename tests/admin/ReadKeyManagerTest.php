<?php

declare(strict_types=1);

namespace Trusteed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Trusteed\Service\ReadKeyBackend;
use Trusteed\Service\ReadKeyBackendException;
use Trusteed\Service\ReadKeyEnvironment;
use Trusteed\Service\ReadKeyManager;
use Trusteed\Service\ReadOnlyWebserviceKey;

/**
 * Orchestration of the consent-based "Allow read access" / "Revoke" actions.
 *
 * Runs against PrestaShop and backend doubles, as `SetupStatus` does: what
 * matters (what is created, what is undone on failure, what is left as it
 * was) is plain logic and does not need a PrestaShop runtime.
 */
final class ReadKeyManagerTest extends TestCase
{
    private const STORE_URL = 'https://tienda.example.com';
    private const INSTALL_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testActivarCreaClaveGetOnlyLaEntregaYGuardaSoloElId(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: false);
        $backend = new FakeBackend();

        $result = (new ReadKeyManager($env, $backend))->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertTrue($result->ok());
        $this->assertSame('enabled', $result->code());
        $this->assertCount(1, $env->created);
        [$key, $description, $permissions] = $env->created[0];
        $this->assertTrue(ReadOnlyWebserviceKey::isValidFormat($key));
        $this->assertSame(ReadOnlyWebserviceKey::DESCRIPTION, $description);
        $this->assertSame(ReadOnlyWebserviceKey::permissions(), $permissions);

        // The same key reaches the backend, through the sign-up channel.
        $this->assertSame([[self::STORE_URL, self::INSTALL_KEY, $key]], $backend->attached);

        // Locally only the id is stored; the key is not kept in any configuration value.
        $this->assertSame(7, $env->storedKeyId());
        $this->assertNotContains($key, $env->configValues());

        // The Webservice was off: it is switched on as part of the consented
        // action, and the module remembers that it was the one that enabled it.
        $this->assertTrue($env->webserviceEnabled());
        $this->assertTrue($env->enabledByModule());
    }

    public function testConElWebserviceYaEncendidoNoLoTocaNiSeAtribuyeElEncendido(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true);

        $result = (new ReadKeyManager($env, new FakeBackend()))->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertTrue($result->ok());
        $this->assertSame(0, $env->enableCalls);
        $this->assertFalse($env->enabledByModule());
    }

    public function testSiElBackendRechazaSeBorraLaClaveYSeRestauraElWebservice(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: false);
        $backend = new FakeBackend(attachFailure: 'read_key_rejected');

        $result = (new ReadKeyManager($env, $backend))->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertFalse($result->ok());
        $this->assertSame('backend_rejected', $result->code());
        $this->assertSame('read_key_rejected', $result->detail());
        // A key nobody will use is not left alive in the shop.
        $this->assertSame([7], $env->deleted);
        $this->assertSame(0, $env->storedKeyId());
        // The consented action did not complete: everything is as it was.
        $this->assertFalse($env->webserviceEnabled());
        $this->assertFalse($env->enabledByModule());
    }

    public function testSiElBackendRechazaConElWebserviceYaEncendidoNoLoApaga(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true);

        (new ReadKeyManager($env, new FakeBackend(attachFailure: 'store_unreachable')))
            ->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertSame(0, $env->disableCalls);
        $this->assertTrue($env->webserviceEnabled());
    }

    public function testSiNoSePuedeCrearLaClaveNiSeLlamaAlBackend(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: false, createReturns: 0);
        $backend = new FakeBackend();

        $result = (new ReadKeyManager($env, $backend))->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertSame('key_creation_failed', $result->code());
        $this->assertSame([], $backend->attached);
        $this->assertFalse($env->webserviceEnabled());
    }

    public function testActivarDosVecesNoCreaUnaSegundaClave(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 3, existingKeyIds: [3]);
        $backend = new FakeBackend();

        $result = (new ReadKeyManager($env, $backend))->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertTrue($result->ok());
        $this->assertSame('already_enabled', $result->code());
        $this->assertSame([], $env->created);
        $this->assertSame([], $backend->attached);
    }

    public function testUnIdHuerfanoSeDescartaYSeCreaUnaClaveNueva(): void
    {
        // The merchant deleted the key by hand from Advanced Parameters.
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 3, existingKeyIds: []);

        $result = (new ReadKeyManager($env, new FakeBackend()))->enable(self::STORE_URL, self::INSTALL_KEY);

        $this->assertSame('enabled', $result->code());
        $this->assertSame(7, $env->storedKeyId());
    }

    public function testSinAltaNoHaceNada(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: false);
        $backend = new FakeBackend();

        $result = (new ReadKeyManager($env, $backend))->enable(self::STORE_URL, '');

        $this->assertSame('not_registered', $result->code());
        $this->assertSame([], $env->created);
        $this->assertSame(0, $env->enableCalls);
    }

    public function testRevocarBorraLocalAntesDeAvisarYNoApagaElWebservice(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 7, existingKeyIds: [7], enabledByModule: true);
        $backend = new FakeBackend(log: $env->log);

        $result = (new ReadKeyManager($env, $backend))->revoke(self::STORE_URL, self::INSTALL_KEY);

        $this->assertTrue($result->ok());
        $this->assertSame('revoked', $result->code());
        // Delete first: if the backend does not answer, the key is already useless.
        $this->assertSame(['delete:7', 'backend:revoke'], $env->log->entries);
        $this->assertTrue($result->backendNotified());
        $this->assertSame(0, $env->storedKeyId());
        // Other integrations may depend on the Webservice: it is not switched
        // off silently, the merchant is told.
        $this->assertSame(0, $env->disableCalls);
        $this->assertTrue($result->webserviceLeftEnabled());
        $this->assertFalse($env->enabledByModule());
    }

    public function testRevocarConOtrasClavesNoAvisaDelWebservice(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 7, existingKeyIds: [7, 9], enabledByModule: true);

        $result = (new ReadKeyManager($env, new FakeBackend()))->revoke(self::STORE_URL, self::INSTALL_KEY);

        $this->assertFalse($result->webserviceLeftEnabled());
    }

    public function testRevocarSinQueElModuloEncendieraElWebserviceNoAvisa(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 7, existingKeyIds: [7], enabledByModule: false);

        $result = (new ReadKeyManager($env, new FakeBackend()))->revoke(self::STORE_URL, self::INSTALL_KEY);

        $this->assertFalse($result->webserviceLeftEnabled());
    }

    public function testSiElBackendNoContestaLaClaveLocalYaEstaBorrada(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 7, existingKeyIds: [7]);

        $result = (new ReadKeyManager($env, new FakeBackend(revokeSucceeds: false)))
            ->revoke(self::STORE_URL, self::INSTALL_KEY);

        $this->assertTrue($result->ok());
        $this->assertSame('revoked_backend_pending', $result->code());
        $this->assertFalse($result->backendNotified());
        $this->assertSame([7], $env->deleted);
        $this->assertSame(0, $env->storedKeyId());
    }

    public function testSiNoSePuedeBorrarLocalAvisaIgualYConservaElId(): void
    {
        $env = new FakeEnvironment(webserviceEnabled: true, storedKeyId: 7, existingKeyIds: [7], deleteSucceeds: false);
        $backend = new FakeBackend();

        $result = (new ReadKeyManager($env, $backend))->revoke(self::STORE_URL, self::INSTALL_KEY);

        $this->assertFalse($result->ok());
        $this->assertSame('key_deletion_failed', $result->code());
        // The backend stops using it even though it is still alive here.
        $this->assertSame(1, $backend->revokeCalls);
        $this->assertSame(7, $env->storedKeyId());
    }

    public function testLasImplementacionesRealesCumplenLosContratos(): void
    {
        // Without this the doubles could follow an interface the production
        // classes no longer implement, and the tests would pass vacuously.
        $this->assertTrue(
            (new \ReflectionClass(\Trusteed\Service\AutoRegister::class))
                ->implementsInterface(ReadKeyBackend::class)
        );
        $this->assertTrue(
            (new \ReflectionClass(\Trusteed\Service\PrestaShopReadKeyEnvironment::class))
                ->implementsInterface(ReadKeyEnvironment::class)
        );
    }

    public function testRevocarSinIdLocalAunAsiPideAlBackendQueOlvide(): void
    {
        // Configuration deleted or restored from a backup: the backend may still
        // hold a key that can no longer be located from here.
        $env = new FakeEnvironment(webserviceEnabled: true);
        $backend = new FakeBackend();

        $result = (new ReadKeyManager($env, $backend))->revoke(self::STORE_URL, self::INSTALL_KEY);

        $this->assertSame('not_enabled', $result->code());
        $this->assertSame(1, $backend->revokeCalls);
        $this->assertSame([], $env->deleted);
    }
}

/** Shared log used to check the ORDER of calls between environment and backend. */
final class CallLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class FakeEnvironment implements ReadKeyEnvironment
{
    /** @var list<array{0:string,1:string,2:array<string,array<string,int>>}> */
    public array $created = [];
    /** @var list<int> */
    public array $deleted = [];
    public int $enableCalls = 0;
    public int $disableCalls = 0;
    public CallLog $log;

    /** @param list<int> $existingKeyIds */
    public function __construct(
        private bool $webserviceEnabled,
        private int $storedKeyId = 0,
        private array $existingKeyIds = [],
        private bool $enabledByModule = false,
        private int $createReturns = 7,
        private bool $deleteSucceeds = true,
    ) {
        $this->log = new CallLog();
    }

    public function webserviceEnabled(): bool
    {
        return $this->webserviceEnabled;
    }

    public function enableWebservice(): void
    {
        $this->enableCalls++;
        $this->webserviceEnabled = true;
    }

    public function disableWebservice(): void
    {
        $this->disableCalls++;
        $this->webserviceEnabled = false;
    }

    public function createKey(string $key, string $description, array $permissions): int
    {
        $this->created[] = [$key, $description, $permissions];
        if ($this->createReturns > 0) {
            $this->existingKeyIds[] = $this->createReturns;
        }

        return $this->createReturns;
    }

    public function deleteKey(int $id): bool
    {
        $this->log->entries[] = 'delete:' . $id;
        if (!$this->deleteSucceeds) {
            return false;
        }
        $this->deleted[] = $id;
        $this->existingKeyIds = array_values(array_diff($this->existingKeyIds, [$id]));

        return true;
    }

    public function keyExists(int $id): bool
    {
        return in_array($id, $this->existingKeyIds, true);
    }

    public function countKeys(): int
    {
        return count($this->existingKeyIds);
    }

    public function storedKeyId(): int
    {
        return $this->storedKeyId;
    }

    public function storeKeyId(int $id): void
    {
        $this->storedKeyId = $id;
    }

    public function enabledByModule(): bool
    {
        return $this->enabledByModule;
    }

    public function setEnabledByModule(bool $value): void
    {
        $this->enabledByModule = $value;
    }

    /** @return list<string> every value this environment persists as configuration */
    public function configValues(): array
    {
        return [(string) $this->storedKeyId, $this->enabledByModule ? '1' : '0'];
    }
}

final class FakeBackend implements ReadKeyBackend
{
    /** @var list<array{0:string,1:string,2:string}> */
    public array $attached = [];
    public int $revokeCalls = 0;

    public function __construct(
        private ?string $attachFailure = null,
        private bool $revokeSucceeds = true,
        private ?CallLog $log = null,
    ) {
    }

    public function attachReadKey(string $storeUrl, string $installKey, string $apiKey): void
    {
        if ($this->attachFailure !== null) {
            throw new ReadKeyBackendException($this->attachFailure);
        }
        $this->attached[] = [$storeUrl, $installKey, $apiKey];
    }

    public function revokeReadKey(string $storeUrl, string $installKey): bool
    {
        $this->revokeCalls++;
        if ($this->log !== null) {
            $this->log->entries[] = 'backend:revoke';
        }

        return $this->revokeSucceeds;
    }
}
