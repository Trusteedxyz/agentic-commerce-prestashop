<?php

declare(strict_types=1);

/**
 * BackendApiClientSsrfTest — spec-046 F8 (SSRF parity with WP/Odoo guards).
 *
 * Validates that the BackendApiClient rejects HTTPS URLs whose host is the
 * localhost label or an IP-literal in a private / loopback / link-local /
 * reserved range (RFC1918, 127.x, 169.254.x IMDS, CGNAT). These checks are
 * deterministic: literal-IP and 'localhost' hosts are short-circuited before
 * any DNS resolution, so the test needs no network.
 *
 * NOTE: full DNS-rebinding mitigation still requires an egress-layer control
 * (same caveat as the Odoo/WordPress guards).
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\BackendApiClient;
use Trusteed\AgenticTools\McpToolException;
use PHPUnit\Framework\TestCase;

final class BackendApiClientSsrfTest extends TestCase
{
    /**
     * @dataProvider blockedUrls
     */
    public function testPrivateAndLoopbackHostsAreRejected(string $apiUrl): void
    {
        $client = new BackendApiClient($apiUrl, 'bootstrap-token');

        try {
            $client->call('/api/v1/internal/agent-identity/verify', ['a' => 1]);
            $this->fail("Expected SSRF rejection for {$apiUrl}");
        } catch (McpToolException $e) {
            $this->assertSame(
                McpToolException::CODE_VALIDATION_FAILED,
                $e->getMcpCode(),
                "URL {$apiUrl} must be rejected as a validation failure (SSRF)"
            );
            $this->assertStringContainsStringIgnoringCase('SSRF', $e->getMessage());
        }
    }

    /** @return list<array{string}> */
    public static function blockedUrls(): array
    {
        return [
            ['https://localhost'],
            ['https://localhost:8443'],
            ['https://127.0.0.1'],
            ['https://127.0.0.1:3000'],
            ['https://169.254.169.254'],            // AWS/GCP IMDS link-local
            ['https://192.168.1.10'],
            ['https://10.0.0.5'],
            ['https://172.16.4.4'],
            ['https://[::1]'],                       // IPv6 loopback
        ];
    }

    public function testNonHttpsIsStillRejectedFirst(): void
    {
        $client = new BackendApiClient('http://example.com', 'token');

        $this->expectException(McpToolException::class);
        $client->call('/x', []);
    }

    /**
     * @dataProvider privateIps
     */
    public function testIsPublicIpRejectsPrivateRanges(string $ip): void
    {
        $this->assertFalse(
            BackendApiClient::isPublicIp($ip),
            "IP {$ip} must be classified as non-public (SSRF blocked)"
        );
    }

    /** @return list<array{string}> */
    public static function privateIps(): array
    {
        return [
            ['127.0.0.1'],
            ['169.254.169.254'],
            ['192.168.0.1'],
            ['10.255.255.255'],
            ['172.31.0.1'],
            ['::1'],
            ['fc00::1'],          // IPv6 unique-local
            ['fe80::1'],          // IPv6 link-local
        ];
    }

    /**
     * @dataProvider publicIps
     */
    public function testIsPublicIpAcceptsRoutableAddresses(string $ip): void
    {
        $this->assertTrue(
            BackendApiClient::isPublicIp($ip),
            "IP {$ip} must be classified as public/routable"
        );
    }

    /** @return list<array{string}> */
    public static function publicIps(): array
    {
        return [
            ['8.8.8.8'],
            ['1.1.1.1'],
            ['2606:4700:4700::1111'], // Cloudflare IPv6
        ];
    }
}
