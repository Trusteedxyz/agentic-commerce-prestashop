<?php
/**
 * TokenBrokerTest — PHPUnit unit tests for the TokenBroker opaque-token relay.
 *
 * Tests pure PHP logic that does NOT hit the network: constructor invariants,
 * api_base SSRF validation, expires_at → expires_in conversion, and the
 * execWithRetry signature. The network relay (exchange()) is exercised by the
 * backend integration suite (apps/api embed-ps routes), not here.
 *
 * Run: vendor/bin/phpunit packages/ps-module-agenticmcpstores/tests/unit/TokenBrokerTest.php
 */

namespace AgenticMcpStores\Tests\Unit;

use PHPUnit\Framework\TestCase;
use AgenticMcpStores\Mvp\TokenBroker;

class TokenBrokerTest extends TestCase
{
    private TokenBroker $broker;
    private string $secret = 'test-secret-hex-32bytes-placeholder';

    protected function setUp(): void
    {
        $this->broker = new TokenBroker(
            merchantId:   'merchant-uuid-test',
            secret:       $this->secret,
            apiBase:      'https://api.trusteed.xyz',
            shopDomain:   'test.prestashop.local',
            idShop:       1,
            allowedShops: [1, 2],
            employeeId:   42
        );
    }

    // --- Constructor invariants ------------------------------------------------

    public function testApiBaseLackingTrailingSlash(): void
    {
        // apiBase is stored without a trailing slash so endpoint URLs are clean.
        $broker = new TokenBroker(
            merchantId:   'mid',
            secret:       $this->secret,
            apiBase:      'https://api.trusteed.xyz/',  // trailing slash
            shopDomain:   'shop.local',
            idShop:       1,
            allowedShops: [1],
            employeeId:   1
        );

        $ref  = new \ReflectionClass($broker);
        $prop = $ref->getProperty('apiBase');
        $prop->setAccessible(true);

        $this->assertStringEndsNotWith('/', $prop->getValue($broker));
    }

    public function testConstructorStoresMerchantAndSecret(): void
    {
        $ref = new \ReflectionClass($this->broker);

        $merchant = $ref->getProperty('merchantId');
        $merchant->setAccessible(true);
        $this->assertSame('merchant-uuid-test', $merchant->getValue($this->broker));

        $secret = $ref->getProperty('secret');
        $secret->setAccessible(true);
        $this->assertSame($this->secret, $secret->getValue($this->broker));
    }

    public function testConstructorRejectsInvalidApiBase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenBroker(
            merchantId:   'mid',
            secret:       'secret-32bytes-placeholder-here',
            apiBase:      'http://internal.corp/api',  // HTTP rejected
            shopDomain:   'shop.local',
            idShop:       1,
            allowedShops: [1],
            employeeId:   1
        );
    }

    // --- exchange(): exposes only the public relay surface ---------------------

    public function testExchangeIsPublicAndTakesNoArgs(): void
    {
        $ref    = new \ReflectionClass($this->broker);
        $method = $ref->getMethod('exchange');

        $this->assertTrue($method->isPublic(), 'exchange() must be public');
        $this->assertCount(0, $method->getParameters(), 'exchange() takes no arguments');
    }

    public function testLegacyJwtSigningHelpersAreRemoved(): void
    {
        // The opaque-relay flow must NOT carry the legacy HS256 JWT signer.
        $ref = new \ReflectionClass($this->broker);
        $this->assertFalse(
            $ref->hasMethod('signBootstrap'),
            'signBootstrap (legacy JWT signer) must be removed — PS uses the opaque token'
        );
    }

    // --- buildRequestBody: multishop scope claim contract (#2) ----------------

    /** @return array<string,mixed> */
    private function invokeBuildRequestBody(TokenBroker $broker): array
    {
        $ref    = new \ReflectionClass($broker);
        $method = $ref->getMethod('buildRequestBody');
        $method->setAccessible(true);
        /** @var array<string,mixed> $body */
        $body = $method->invoke($broker);
        return $body;
    }

    public function testSuperAdminBodyEmitsAllShopsAndOmitsAllowedShops(): void
    {
        // A super-admin must authorize ALL shops via an explicit `all_shops: true`
        // claim, never via a (potentially 64-truncated) allowed_shops list.
        $shops  = range(1, 200); // > 64 → would be silently truncated if listed
        $broker = new TokenBroker(
            merchantId:   'mid',
            secret:       $this->secret,
            apiBase:      'https://api.trusteed.xyz',
            shopDomain:   'shop.local',
            idShop:       1,
            allowedShops: $shops,
            employeeId:   7,
            allShops:     true
        );

        $body = $this->invokeBuildRequestBody($broker);

        $this->assertArrayHasKey('all_shops', $body);
        $this->assertTrue($body['all_shops']);
        $this->assertArrayNotHasKey(
            'allowed_shops',
            $body,
            'super-admin body must omit the truncatable allowed_shops list'
        );
        $this->assertSame('admin_trusteed', $body['capability_attestation']);
        $this->assertSame('7', $body['ps_employee_id']);
    }

    public function testScopedEmployeeBodyEmitsAllowedShopsAndNoAllShops(): void
    {
        // A scoped (non-super-admin) employee must carry an explicit shop id list
        // and must NOT carry an all_shops escalation claim.
        $broker = new TokenBroker(
            merchantId:   'mid',
            secret:       $this->secret,
            apiBase:      'https://api.trusteed.xyz',
            shopDomain:   'shop.local',
            idShop:       3,
            allowedShops: [3, 5, 9],
            employeeId:   11,
            allShops:     false
        );

        $body = $this->invokeBuildRequestBody($broker);

        $this->assertArrayNotHasKey('all_shops', $body);
        $this->assertArrayHasKey('allowed_shops', $body);
        $this->assertSame([3, 5, 9], $body['allowed_shops']);
    }

    public function testScopedEmployeeBodyTruncatesAllowedShopsTo64(): void
    {
        // The scoped path bounds allowed_shops to 64 entries (backend Zod ceiling).
        $broker = new TokenBroker(
            merchantId:   'mid',
            secret:       $this->secret,
            apiBase:      'https://api.trusteed.xyz',
            shopDomain:   'shop.local',
            idShop:       1,
            allowedShops: range(1, 100),
            employeeId:   1,
            allShops:     false
        );

        $body = $this->invokeBuildRequestBody($broker);

        $this->assertCount(64, $body['allowed_shops']);
        $this->assertSame(range(1, 64), $body['allowed_shops']);
    }

    public function testScopedEmployeeWithNoShopsOmitsBothScopeClaims(): void
    {
        $broker = new TokenBroker(
            merchantId:   'mid',
            secret:       $this->secret,
            apiBase:      'https://api.trusteed.xyz',
            shopDomain:   'shop.local',
            idShop:       1,
            allowedShops: [],
            employeeId:   1,
            allShops:     false
        );

        $body = $this->invokeBuildRequestBody($broker);

        $this->assertArrayNotHasKey('all_shops', $body);
        $this->assertArrayNotHasKey('allowed_shops', $body);
    }

    // --- execWithRetry: signature for the relay POST --------------------------

    public function testExecWithRetryAcceptsUrlAndPayload(): void
    {
        $ref    = new \ReflectionClass($this->broker);
        $method = $ref->getMethod('execWithRetry');
        $params = $method->getParameters();

        $this->assertCount(2, $params, 'execWithRetry must have exactly 2 parameters');
        $this->assertSame('url', $params[0]->getName());
        $this->assertSame('payload', $params[1]->getName());
        $this->assertSame('string', $params[0]->getType()?->getName());
        $this->assertSame('string', $params[1]->getType()?->getName());
    }

    // --- resolveExpiresIn: expires_at → relative TTL --------------------------

    public function testResolveExpiresInFromUnixSeconds(): void
    {
        $ttl = $this->invokeResolveExpiresIn(time() + 600);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    public function testResolveExpiresInFromIso8601(): void
    {
        $iso = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $ttl = $this->invokeResolveExpiresIn($iso);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }

    public function testResolveExpiresInFallsBackOnNull(): void
    {
        $this->assertSame(300, $this->invokeResolveExpiresIn(null));
    }

    public function testResolveExpiresInFallsBackOnGarbage(): void
    {
        $this->assertSame(300, $this->invokeResolveExpiresIn('not-a-date'));
    }

    public function testResolveExpiresInNeverNegative(): void
    {
        // A past expiry must clamp to 0, never go negative.
        $this->assertSame(0, $this->invokeResolveExpiresIn(time() - 120));
    }

    /** @param mixed $expiresAt */
    private function invokeResolveExpiresIn($expiresAt): int
    {
        $ref    = new \ReflectionClass($this->broker);
        $method = $ref->getMethod('resolveExpiresIn');
        $method->setAccessible(true);
        return (int) $method->invoke(null, $expiresAt);
    }

    // --- T042-075: validateApiBasePublic (SSRF guard, unchanged) --------------

    public function testValidApiBasePassesValidation(): void
    {
        // Must not throw for trusteed.xyz HTTPS URLs (the only allowed host family).
        TokenBroker::validateApiBasePublic('https://api.trusteed.xyz');
        TokenBroker::validateApiBasePublic('https://api.trusteed.xyz/v1');
        TokenBroker::validateApiBasePublic('https://embed.trusteed.xyz');
        $this->assertTrue(true); // reached here = no exception
    }

    public function testNonTrusteedHostRejected(): void
    {
        // S042-004: api_base host is restricted to the trusteed.xyz family to
        // mitigate DNS rebinding. An arbitrary external host must be rejected even
        // though it is a public HTTPS URL — this guards against SSRF via a hostname
        // that resolves to a private IP at request time.
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://sub.domain.example.com');
    }

    public function testLookalikeTrusteedHostRejected(): void
    {
        // The (?:^|\.)trusteed\.xyz$ anchor must reject suffix-spoofing hosts such
        // as a domain that merely ends in "...trusteed.xyz" without a dot boundary.
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://eviltrusteed.xyz');
    }

    public function testHttpSchemeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('http://api.trusteed.xyz');
    }

    public function testFtpSchemeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('ftp://api.trusteed.xyz');
    }

    public function testLocalhostRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://localhost/api');
    }

    public function testLoopbackIpRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://127.0.0.1/api');
    }

    public function testPrivateRfc1918_10Rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://10.0.0.1/api');
    }

    public function testPrivateRfc1918_172Rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://172.16.0.1/api');
    }

    public function testPrivateRfc1918_192Rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://192.168.1.1/api');
    }

    public function testLinkLocalMetadataRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://169.254.169.254/latest/meta-data');
    }

    // --- T042-075 round 2: IPv6 + IPv4-mapped IPv6 blocked --------------------

    public function testIpv6LoopbackRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://[::1]/api');
    }

    public function testIpv6UlaPrivateRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://[fd00::1]/api');
    }

    public function testIpv6LinkLocalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://[fe80::1]/api');
    }

    public function testIpv4MappedIpv6LoopbackRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://[::ffff:127.0.0.1]/api');
    }

    public function testIpv4MappedIpv6PrivateRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https://[::ffff:192.168.1.1]/api');
    }

    public function testEmptyHostRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenBroker::validateApiBasePublic('https:///api');
    }
}
