<?php

declare(strict_types=1);

/**
 * PermissionGuard — maps abstract AgenticTool capabilities to PrestaShop
 * back-office permissions and verifies that the acting employee holds them.
 *
 * Abstract capabilities → PS core permission names:
 *
 *   sign_trust_receipt    → ADMINORDERS   (order read access)
 *   verify_agent_signature → ADMINORDERS
 *   pay_with_agent        → ADMINORDERS (UPDATE: authorising a payment is a write;
 *                           PrestaShop core has no ADMINPAYMENTCART tab, BUG-307)
 *
 * Unknown capabilities are denied by default (fail-closed).
 */

namespace Trusteed\AgenticTools;

final class PermissionGuard implements PermissionGuardInterface
{
    /**
     * Map abstract capability → [PS core tab class, access level].
     *
     * @var array<string,array{string,string}>
     */
    private const CAPABILITY_MAP = [
        'sign_trust_receipt'     => ['ADMINORDERS', 'READ'],
        'verify_agent_signature' => ['ADMINORDERS', 'READ'],
        'pay_with_agent'         => ['ADMINORDERS', 'UPDATE'],
    ];

    /**
     * Returns true when $employeeId holds the permission required by
     * $abstractCapability; false on any unknown capability or missing perm.
     */
    public function check(string $abstractCapability, int $employeeId): bool
    {
        $entry = self::CAPABILITY_MAP[$abstractCapability] ?? null;

        if ($entry === null) {
            // Unknown capability — deny by default (fail-closed).
            return false;
        }

        if ($employeeId <= 0) {
            return false;
        }

        return $this->hasAccess($entry[0], $entry[1], $employeeId);
    }

    /**
     * Verifies the employee holds the READ role of the given PS core tab.
     *
     * Real signature (PS 1.7.8 / 8): `Access::isGranted(string $role, int $idProfile)`
     * with a `ROLE_MOD_TAB_<CLASS>_READ` slug (BUG-307). Any failure denies.
     */
    private function hasAccess(string $psPermission, string $level, int $employeeId): bool
    {
        if (class_exists('\\Access')) {
            try {
                $employee = new \Employee($employeeId);
                if ($employee->id_profile !== null) {
                    $role = 'ROLE_MOD_TAB_' . strtoupper($psPermission) . '_' . $level;
                    return (bool) \Access::isGranted($role, (int) $employee->id_profile);
                }
            } catch (\Throwable) {
                return false;
            }
        }

        // Fallback: retrieve permission list via Employee helper.
        if (method_exists('\\Employee', 'getPermissionsForCore')) {
            /** @var array<string,mixed>|false $perms */
            $perms = \Employee::getPermissionsForCore($psPermission, $employeeId);
            $key = $level === 'READ' ? 'view' : strtolower($level);
            if (is_array($perms) && isset($perms[$key])) {
                return (bool) $perms[$key];
            }
        }

        // Cannot determine permissions — deny (fail-closed).
        return false;
    }
}
