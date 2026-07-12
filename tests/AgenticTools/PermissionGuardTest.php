<?php

declare(strict_types=1);

/**
 * PermissionGuardTest — tests the REAL PermissionGuard (spec-046 H1 + T033).
 *
 * Previously this suite tested an inline MockPermissionGuardImpl that
 * re-implemented the capability→permission mapping, so it never exercised the
 * production class. This version drives the REAL Trusteed\AgenticTools\
 * PermissionGuard, using configurable PS stubs (Access / Tab / Employee) for the
 * back-office permission lookup.
 *
 * Canonical mapping (PermissionGuard::CAPABILITY_MAP):
 *   sign_trust_receipt     → ADMINORDERS
 *   verify_agent_signature → ADMINORDERS
 *   pay_with_agent         → ADMINPAYMENTCART
 */

namespace Trusteed\Tests\AgenticTools;

use Trusteed\AgenticTools\PermissionGuard;
use PHPUnit\Framework\TestCase;

final class PermissionGuardTest extends TestCase
{
    private const EMPLOYEE_ID = 5;

    protected function setUp(): void
    {
        \TestPsPermissions::reset();
    }

    private function guardWith(string ...$tokens): PermissionGuard
    {
        foreach ($tokens as $t) {
            \TestPsPermissions::grant($t);
        }
        return new PermissionGuard();
    }

    // ─── Fail-closed paths (no PS lookup needed) ───────────────────────────────

    public function testUnknownCapabilityIsDenied(): void
    {
        $guard = $this->guardWith('ADMINORDERS', 'ADMINPAYMENTCART');
        $this->assertFalse($guard->check('unknown_capability', self::EMPLOYEE_ID));
    }

    public function testEmptyCapabilityIsDenied(): void
    {
        $guard = $this->guardWith('ADMINORDERS');
        $this->assertFalse($guard->check('', self::EMPLOYEE_ID));
    }

    public function testNonPositiveEmployeeIdIsDenied(): void
    {
        $guard = $this->guardWith('ADMINORDERS');
        $this->assertFalse($guard->check('sign_trust_receipt', 0));
        $this->assertFalse($guard->check('sign_trust_receipt', -1));
    }

    // ─── sign_trust_receipt / verify_agent_signature → ADMINORDERS ─────────────

    public function testSignTrustReceiptGrantedByAdminOrders(): void
    {
        $guard = $this->guardWith('ADMINORDERS');
        $this->assertTrue($guard->check('sign_trust_receipt', self::EMPLOYEE_ID));
    }

    public function testSignTrustReceiptDeniedWithoutAdminOrders(): void
    {
        $guard = $this->guardWith('ADMINPAYMENTCART'); // wrong permission
        $this->assertFalse($guard->check('sign_trust_receipt', self::EMPLOYEE_ID));
    }

    public function testVerifyAgentSignatureGrantedByAdminOrders(): void
    {
        $guard = $this->guardWith('ADMINORDERS');
        $this->assertTrue($guard->check('verify_agent_signature', self::EMPLOYEE_ID));
    }

    public function testVerifyAgentSignatureDeniedWithoutAdminOrders(): void
    {
        $guard = $this->guardWith('ADMINPAYMENTCART');
        $this->assertFalse($guard->check('verify_agent_signature', self::EMPLOYEE_ID));
    }

    // ─── pay_with_agent → ADMINPAYMENTCART ─────────────────────────────────────

    public function testPayWithAgentGrantedByPaymentPermission(): void
    {
        $guard = $this->guardWith('ADMINPAYMENTCART');
        $this->assertTrue($guard->check('pay_with_agent', self::EMPLOYEE_ID));
    }

    public function testPayWithAgentDeniedByAdminOrdersAlone(): void
    {
        $guard = $this->guardWith('ADMINORDERS');
        $this->assertFalse($guard->check('pay_with_agent', self::EMPLOYEE_ID));
    }

    public function testPayWithAgentDeniedForEmployeeWithNoPermissions(): void
    {
        $guard = $this->guardWith();
        $this->assertFalse($guard->check('pay_with_agent', self::EMPLOYEE_ID));
    }

    // ─── Super-admin & mapping contract ────────────────────────────────────────

    public function testSuperAdminPassesAllChecks(): void
    {
        $guard = $this->guardWith('ADMINORDERS', 'ADMINPAYMENTCART');
        $this->assertTrue($guard->check('sign_trust_receipt', self::EMPLOYEE_ID));
        $this->assertTrue($guard->check('verify_agent_signature', self::EMPLOYEE_ID));
        $this->assertTrue($guard->check('pay_with_agent', self::EMPLOYEE_ID));
    }

    public function testMappingContractAndComplementaryDenial(): void
    {
        $mapping = [
            'sign_trust_receipt'     => 'ADMINORDERS',
            'verify_agent_signature' => 'ADMINORDERS',
            'pay_with_agent'         => 'ADMINPAYMENTCART',
        ];

        foreach ($mapping as $capability => $token) {
            \TestPsPermissions::reset();
            $granted = $this->guardWith($token);
            $this->assertTrue(
                $granted->check($capability, self::EMPLOYEE_ID),
                "'$capability' must be granted by '$token'"
            );

            \TestPsPermissions::reset();
            $other = $token === 'ADMINORDERS' ? 'ADMINPAYMENTCART' : 'ADMINORDERS';
            $denied = $this->guardWith($other);
            if ($capability === 'pay_with_agent') {
                $this->assertFalse(
                    $denied->check($capability, self::EMPLOYEE_ID),
                    "'$capability' must NOT be granted by '$other'"
                );
            }
        }
    }
}
