<?php
declare(strict_types=1);
/**
 * Development-only server-side PHP session adapter; NOT an HTTP endpoint.
 * Application must enforce HTTPS, authentication/MFA, account status, rate
 * limits, CSRF and assignment lookup before using this class.
 * Keep under private dev-only directory outside the web document root.
 */
require_once __DIR__.'/StaffAuth.php';
require_once __DIR__.'/StaffPermissions.php';
require_once __DIR__.'/StaffSessionPolicy.php';

final class StaffSessionStorage {
    private const SESSION_KEY = 'hayanex_staff_v1';

    /** Initialize a secure server-side session before any response output. */
    public static function start(bool $https): void {
        if (!$https) throw new RuntimeException('HTTPS required');
        if (headers_sent()) throw new RuntimeException('Headers already sent');
        if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('Session already active');
        if (!ini_get('session.use_cookies')) throw new RuntimeException('Cookie sessions required');
        if (!ini_get('session.use_strict_mode')) {
            ini_set('session.use_strict_mode', '1');
        }
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_strict_mode', '1');
        session_name('HAYANEX_STAFF');
        session_set_cookie_params([
            'lifetime'=>0, 'path'=>'/', 'secure'=>true,
            'httponly'=>true, 'samesite'=>'Strict',
        ]);
        if (!session_start()) throw new RuntimeException('Cannot start session');
    }

    /** Only call after verification against the DB and required MFA. */
    public static function login(int $staffId, string $role, bool $active, bool $passwordOk, bool $mfaOk, int $now): void {
        self::ensureActive();
        $identity = StaffSessionPolicy::issue($staffId,$role,$active,$passwordOk,$mfaOk,$now);
        // Rotate after success; never elevate an existing session identifier.
        if (!session_regenerate_id(true)) throw new RuntimeException('Could not rotate session identifier');
        $_SESSION = [self::SESSION_KEY=>$identity];
    }

    /** Returns validated identity only. Controller must recheck DB role/active/assignment. */
    public static function current(int $now): ?array {
        self::ensureActive();
        $record = $_SESSION[self::SESSION_KEY]??null;
        if (!is_array($record) || !StaffSessionPolicy::valid($record,$now)) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        return $record;
    }

    /** Every authorized request must check current account state and role via DB. */
    public static function authorize(int $now, bool $active, string $dbRole, string $action, ?int $assignedId=null): bool {
        $record=self::current($now);
        if ($record===null) return false;
        if (!StaffSessionPolicy::authorize($record,$now,$active,$dbRole,$action,$assignedId)) return false;
        $_SESSION[self::SESSION_KEY]=StaffSessionPolicy::touched($record,$now);
        return true;
    }

    public static function logout(): void {
        self::ensureActive();
        $_SESSION=[];
        $params=session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(),'',[
                'expires'=>time()-3600,
                'path'=>$params['path'], 'domain'=>$params['domain'],
                'secure'=>$params['secure'], 'httponly'=>$params['httponly'],
                'samesite'=>$params['samesite']??'Strict',
            ]);
        }
        session_destroy();
    }

    private static function ensureActive(): void {
        if (session_status()!==PHP_SESSION_ACTIVE) throw new RuntimeException('Start secure session first');
    }
}
