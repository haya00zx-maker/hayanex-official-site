<?php
declare(strict_types=1);
/**
 * HAYANEX development-only staff permission policy.
 * Call only after verified authentication/MFA and DB-backed assignment lookup.
 * This file alone is NOT an authentication or HTTP endpoint.
 */
final class StaffPermissions {
    private const ROLES = ['owner', 'admin', 'staff'];
    public static function can(string $role, string $action, ?int $staffId = null, ?int $assignedStaffId = null): bool {
        if (!in_array($role, self::ROLES, true)) return false;
        if ($role === 'owner') return in_array($action, [
            'inquiry_view','inquiry_reply','inquiry_update','assign','staff_manage','system_config','audit_view'
        ], true);
        if ($role === 'admin') return in_array($action, [
            'inquiry_view','inquiry_reply','inquiry_update','assign','audit_view'
        ], true);
        if (!in_array($action,['inquiry_view','inquiry_reply','inquiry_update'],true)) return false;
        return $staffId !== null && $assignedStaffId !== null
            && $staffId > 0 && $staffId === $assignedStaffId;
    }
}
