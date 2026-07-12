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
 *   pay_with_agent        → ADMINPAYMENTCART  (cart/payment access)
 *
 * Unknown capabilities are denied by default (fail-closed).
 */

namespace Trusteed\AgenticTools;

final class PermissionGuard implements PermissionGuardInterface
{
    /**
     * Map abstract capability → PS core permission token.
     *
     * @var array<string,string>
     */
    private const CAPABILITY_MAP = [
        'sign_trust_receipt'     => 'ADMINORDERS',
        'verify_agent_signature' => 'ADMINORDERS',
        'pay_with_agent'         => 'ADMINPAYMENTCART',
    ];

    /**
     * Returns true when $employeeId holds the permission required by
     * $abstractCapability; false on any unknown capability or missing perm.
     */
    public function check(string $abstractCapability, int $employeeId): bool
    {
        $psPermission = self::CAPABILITY_MAP[$abstractCapability] ?? null;

        if ($psPermission === null) {
            // Unknown capability — deny by default (fail-closed).
            return false;
        }

        if ($employeeId <= 0) {
            return false;
        }

        return $this->hasAccess($psPermission, $employeeId);
    }

    /**
     * Verifies the employee has 'view' access to the given PS core tab/module.
     *
     * Uses `Access::isGranted()` when available (PS 8+), falling back to a
     * direct employee profile check for older environments.
     */
    private function hasAccess(string $psPermission, int $employeeId): bool
    {
        // PS 8+ — preferred API.
        if (class_exists('\\Access')) {
            /** @var int|false $tabId */
            $tabId = \Tab::getIdFromClassName($psPermission);
            if ($tabId !== false && $tabId > 0) {
                /** @var \Employee|null $employee */
                $employee = new \Employee($employeeId);
                if ($employee->id_profile !== null) {
                    return (bool) \Access::isGranted('view', (int) $tabId, (int) $employee->id_profile);
                }
            }
        }

        // Fallback: retrieve permission list via Employee helper.
        if (method_exists('\\Employee', 'getPermissionsForCore')) {
            /** @var array<string,mixed>|false $perms */
            $perms = \Employee::getPermissionsForCore($psPermission, $employeeId);
            if (is_array($perms) && isset($perms['view'])) {
                return (bool) $perms['view'];
            }
        }

        // Cannot determine permissions — deny (fail-closed).
        return false;
    }
}
