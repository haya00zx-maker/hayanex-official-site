<?php
declare(strict_types=1);
/** Session bootstrap for future protected staff-only routes; never run in public prototype. */
final class StaffSession {
    public static function configure(bool $isHttps): void {
        if (!$isHttps) throw new RuntimeException('HTTPS required');
        if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Must configure before session_start');
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        ini_set('session.use_trans_sid','0');
        session_set_cookie_params([
            'lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict'
        ]);
    }
    public static function establish(int $staffId, string $role): void {
        if (session_status() !== PHP_SESSION_ACTIVE || $staffId <= 0 || !in_array($role,['owner','admin','staff'],true)) {
            throw new RuntimeException('Invalid authenticated session');
        }
        session_regenerate_id(true);
        $_SESSION['staff_id']=$staffId;
        $_SESSION['role']=$role;
        $_SESSION['last_active']=time();
    }
    public static function active(int $idleLimitSeconds=900): bool {
        if (session_status() !== PHP_SESSION_ACTIVE || $idleLimitSeconds <= 0) return false;
        $last=$_SESSION['last_active'] ?? null;
        if (!is_int($last) || time()-$last > $idleLimitSeconds) return false;
        if (!isset($_SESSION['staff_id'],$_SESSION['role'])) return false;
        $_SESSION['last_active']=time();
        return true;
    }
}
