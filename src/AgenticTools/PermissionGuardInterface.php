<?php

declare(strict_types=1);

/**
 * PermissionGuardInterface — seam for the PS permission check.
 *
 * Introduced (spec-046 H1) so AgenticTools unit tests can drive the REAL tool
 * classes with a deterministic permission decision without a live PrestaShop
 * back-office (Access / Tab / Employee). The production implementation is
 * PermissionGuard (final).
 */

namespace Trusteed\AgenticTools;

interface PermissionGuardInterface
{
    /**
     * Returns true when $employeeId holds the PS permission required by
     * $abstractCapability; false on any unknown capability or missing perm.
     */
    public function check(string $abstractCapability, int $employeeId): bool;
}
