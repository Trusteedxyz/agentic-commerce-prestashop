<?php

declare(strict_types=1);

/**
 * TokenVerifierMalformedSignatureTest — GHSA-2j2x-5q52-g48m issue 1.
 *
 * `sodium_crypto_sign_verify_detached()` requires its signature argument to be
 * exactly SODIUM_CRYPTO_SIGN_BYTES (64) bytes and THROWS SodiumException for
 * any other length — it does not return false. TokenVerifier::verify() passed
 * the decoded `_trusteed_agent_token` signature segment straight into it with
 * no length guard, so a request-controlled, unauthenticated parameter could
 * make verify() throw instead of returning null. The exception then escaped
 * into PaymentModule::validateOrder()'s catch(\Throwable) branch, which
 * fail-opened (see PaymentModuleOverrideUnexpectedErrorTest.php).
 *
 * `verify()` must return null for a malformed-length signature — same
 * contract as any other invalid token — never throw.
 *
 * Run:
 *   docker run --rm -v $(pwd):/app -w /app php:8.3-cli \
 *     php vendor/bin/phpunit tests/enforcement/TokenVerifierMalformedSignatureTest.php
 */

namespace Trusteed\Tests\Enforcement;

use Trusteed\Enforcement\SnapshotClient;
use Trusteed\Enforcement\TokenVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PsEnforcementStubs.php';
require_once __DIR__ . '/../../src/Enforcement/SnapshotClient.php';
require_once __DIR__ . '/../../src/Enforcement/TokenVerifier.php';

class TokenVerifierMalformedSignatureTest extends TestCase
{
    private const MERCHANT_ID = 'merch-test-sig-len';
    private const AGENT_DID   = 'did:key:zQ3shP6YKjqsb9MmbRMSQvmCq5N3a97RXb1ZV8NLKhBWYdrQS';
    private const CART_HASH   = 'aabbccdd0011223344556677889900aabbccdd0011223344556677889900aabb';

    private static string $agentPubKey;

    public static function setUpBeforeClass(): void
    {
        $kp = sodium_crypto_sign_keypair();
        self::$agentPubKey = sodium_crypto_sign_publickey($kp);
    }

    /**
     * Builds a syntactically well-formed `_trusteed_agent_token` JWS whose
     * signature segment decodes to exactly `$sigLength` bytes of arbitrary
     * data, instead of a real Ed25519 signature. Header/payload are otherwise
     * valid so the code path reaches the sodium call.
     */
    private function buildTokenWithSignatureLength(int $sigLength): string
    {
        $now = time();
        $headerB64 = SnapshotClient::base64UrlEncode(json_encode([
            'alg' => 'EdDSA',
            'typ' => 'trusteed-agent-token+jwt',
            'kid' => self::AGENT_DID . '#key-1',
        ], JSON_THROW_ON_ERROR));
        $payloadB64 = SnapshotClient::base64UrlEncode(json_encode([
            'iss'                => self::AGENT_DID,
            'aud'                => 'trusteed',
            'merchantId'         => self::MERCHANT_ID,
            'checkoutIntentHash' => self::CART_HASH,
            'nonce'              => bin2hex(random_bytes(16)),
            'iat'                => $now,
            'exp'                => $now + 300,
        ], JSON_THROW_ON_ERROR));

        $malformedSig = str_repeat("\x01", $sigLength);

        return $headerB64 . '.' . $payloadB64 . '.' . SnapshotClient::base64UrlEncode($malformedSig);
    }

    private function verifier(): TokenVerifier
    {
        return new TokenVerifier(
            [self::AGENT_DID => ['x' => SnapshotClient::base64UrlEncode(self::$agentPubKey)]],
            self::MERCHANT_ID
        );
    }

    public function testZeroByteSignatureReturnsNullInsteadOfThrowing(): void
    {
        $result = $this->verifier()->verify($this->buildTokenWithSignatureLength(0), self::CART_HASH);
        $this->assertNull($result);
    }

    public function testOneByteSignatureReturnsNullInsteadOfThrowing(): void
    {
        // The exact shape the external report demonstrated against a live
        // FileCache/SnapshotClient/TokenVerifier stack with a real keypair.
        $result = $this->verifier()->verify($this->buildTokenWithSignatureLength(1), self::CART_HASH);
        $this->assertNull($result);
    }

    public function testSixtyThreeByteSignatureReturnsNullInsteadOfThrowing(): void
    {
        $result = $this->verifier()->verify(
            $this->buildTokenWithSignatureLength(SODIUM_CRYPTO_SIGN_BYTES - 1),
            self::CART_HASH
        );
        $this->assertNull($result);
    }

    public function testSixtyFiveByteSignatureReturnsNullInsteadOfThrowing(): void
    {
        $result = $this->verifier()->verify(
            $this->buildTokenWithSignatureLength(SODIUM_CRYPTO_SIGN_BYTES + 1),
            self::CART_HASH
        );
        $this->assertNull($result);
    }

    /**
     * Control: a signature that IS the correct length (64 bytes) but signed
     * by a different keypair must still return null via the ordinary
     * signature-mismatch path (not an exception) — proves the length guard
     * does not swallow real signature failures, and that the token genuinely
     * reaches the verification line.
     */
    public function testControlWrongButCorrectLengthSignatureReturnsNull(): void
    {
        $now = time();
        $headerB64 = SnapshotClient::base64UrlEncode(json_encode([
            'alg' => 'EdDSA',
            'typ' => 'trusteed-agent-token+jwt',
            'kid' => self::AGENT_DID . '#key-1',
        ], JSON_THROW_ON_ERROR));
        $payloadB64 = SnapshotClient::base64UrlEncode(json_encode([
            'iss'                => self::AGENT_DID,
            'aud'                => 'trusteed',
            'merchantId'         => self::MERCHANT_ID,
            'checkoutIntentHash' => self::CART_HASH,
            'nonce'              => bin2hex(random_bytes(16)),
            'iat'                => $now,
            'exp'                => $now + 300,
        ], JSON_THROW_ON_ERROR));

        // Real 64-byte Ed25519 signature — but from an UNRELATED keypair, so
        // it will not verify against self::$agentPubKey.
        $foreignKp  = sodium_crypto_sign_keypair();
        $foreignSig = sodium_crypto_sign_detached(
            $headerB64 . '.' . $payloadB64,
            sodium_crypto_sign_secretkey($foreignKp)
        );
        $this->assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($foreignSig));

        $jws = $headerB64 . '.' . $payloadB64 . '.' . SnapshotClient::base64UrlEncode($foreignSig);

        $result = $this->verifier()->verify($jws, self::CART_HASH);
        $this->assertNull($result);
    }
}
