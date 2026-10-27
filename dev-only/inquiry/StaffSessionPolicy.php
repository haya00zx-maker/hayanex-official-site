<?php
declare(strict_types=1);
/**
 * Private development-only session policy. Not an HTTP endpoint.
 * Only the integrating controller may call these methods after verifying
 * account status, password, MFA, TLS, and session storage configuration.
 */
final class StaffSessionPolicy {
    public const IDLE_TIMEOUT = 900; // 15 minutes
    public const ABSOLUTE_TIMEOUT = 28800; // 8 hours

    /** Data to store in a server-side session after session_regenerate_id(true). */
    public static function issue(int $staffId, string $role, bool $active, bool $passwordOk, bool $mfaOk, int $now): array {
        if ($staffId <= 0 || $now < 0 ||
            !StaffAuth::canStartSession($role, $active, $passwordOk, $mfaOk)) {
            throw new RuntimeException('Authentication incomplete');
        }
        return [
            'staff_id'=>$staffId, 'role'=>$role,
            'created_at'=>$now, 'last_seen_at'=>$now,
            'mfa_verified'=>$mfaOk,
        ];
    }

    /** Return false for expired, malformed or tampered session data. */
    public static function valid(array $session, int $now): bool {
        $id=$session['staff_id']??null;
        $role=$session['role']??null;
        $created=$session['created_at']??null;
        $last=$session['last_seen_at']??null;
        $mfa=$session['mfa_verified']??null;
        if (!is_int($id)||$id<=0||!is_string($role)||
            !in_array($role,['owner','admin','staff'],true)||
            !is_int($created)||!is_int($last)||!is_bool($mfa)||$now<0||
            $created<0||$last<$created||$last>$now||$created>$now) return false;
        if ($now-$last>=self::IDLE_TIMEOUT || $now-$created>=self::ABSOLUTE_TIMEOUT) return false;
        return !StaffAuth::requiresMfa($role) || $mfa;
    }

    /** Caller must also recheck current active status and role against database. */
    public static function authorize(array $session, int $now, bool $accountActive, string $dbRole, string $action, ?int $assignedId=null): bool {
        if (!self::valid($session,$now) || !$accountActive || $dbRole!==$session['role']) return false;
        return StaffPermissions::can($dbRole,$action,$session['staff_id'],$assignedId);
    }

    /** On authorized requests only; persist return value to the server-side session. */
    public static function touched(array $session, int $now): array {
        if (!self::valid($session,$now)) throw new RuntimeException('Expired session');
        $session['last_seen_at']=$now;
        return $session;
    }
}
