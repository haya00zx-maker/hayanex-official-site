<?php
declare(strict_types=1);
/**
 * Development-only staff login flow state machine.
 * NOT an HTTP endpoint. A controller must still enforce TLS, CSRF,
 * account/IP attempt limits, hardened server-side PHP sessions and secure
 * cookie settings. Never persist or log passwords, TOTP secrets or codes.
 */
require_once __DIR__.'/StaffAuth.php';
require_once __DIR__.'/StaffSessionPolicy.php';

final class StaffLoginFlow {
    /**
     * Called only with an identity returned by StaffCredentialRepository::verify.
     * This step cannot create an authenticated session.
     */
    public static function afterPassword(?array $identity, int $now): ?array {
        if ($identity === null || $now < 0) return null;
        $id=$identity['staff_id']??null;
        $role=$identity['role']??null;
        $required=$identity['mfa_required']??null;
        if (!is_int($id)||$id<=0||!is_string($role)||!is_bool($required)
            || !in_array($role,['owner','admin','staff'],true)
            || $required!==StaffAuth::requiresMfa($role)) return null;
        return [
            'staff_id'=>$id,'role'=>$role,
            'password_verified_at'=>$now,
            'mfa_required'=>$required
        ];
    }

    /** Pending password verification is valid for at most five minutes. */
    public static function pendingValid(array $pending,int $now): bool {
        $at=$pending['password_verified_at']??null;
        $id=$pending['staff_id']??null;
        $role=$pending['role']??null;
        $required=$pending['mfa_required']??null;
        return is_int($at)&&$at>=0&&is_int($id)&&$id>0
            &&is_string($role)&&in_array($role,['owner','admin','staff'],true)
            &&is_bool($required)&&$required===StaffAuth::requiresMfa($role)
            &&$now >= $at && $now-$at < 300;
    }

    /**
     * Called AFTER independent fresh DB account check and, if required,
     * successful MFA verification with atomically persisted TOTP counter.
     * Controller MUST call session_regenerate_id(true) before storing result.
     */
    public static function complete(array $pending, int $now, bool $active,
        string $currentDbRole, bool $mfaVerified): array {
        if (!self::pendingValid($pending,$now)
            || !$active || $currentDbRole!==$pending['role']
            || ($pending['mfa_required'] && !$mfaVerified)) {
            throw new RuntimeException('Authentication incomplete');
        }
        return StaffSessionPolicy::issue(
            $pending['staff_id'],$currentDbRole,$active,true,$mfaVerified,$now
        );
    }
}
