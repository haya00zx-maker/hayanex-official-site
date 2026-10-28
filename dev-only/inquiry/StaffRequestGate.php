<?php
declare(strict_types=1);
/**
 * Private-development request gate, NOT a public HTTP endpoint.
 * The caller supplies an active session and a fresh DB account record.
 * Assignment must also be looked up in the DB, never supplied by the client.
 */
require_once __DIR__.'/StaffAuth.php';
require_once __DIR__.'/StaffPermissions.php';
require_once __DIR__.'/StaffSessionPolicy.php';

final class StaffRequestGate {
    public static function permitted(
        ?array $session,
        ?array $freshAccount,
        string $action,
        int $now,
        ?int $assignedStaffId = null
    ): bool {
        if ($session === null || $freshAccount === null) return false;
        $id=$freshAccount['id']??null;
        $role=$freshAccount['role']??null;
        $active=$freshAccount['active']??null;
        if (!is_int($id) || $id<=0 || !is_string($role) ||
            !is_bool($active) || !isset($session['staff_id']) ||
            !is_int($session['staff_id']) || $session['staff_id']!==$id) return false;
        return StaffSessionPolicy::authorize(
            $session,$now,$active,$role,$action,$assignedStaffId
        );
    }
}
