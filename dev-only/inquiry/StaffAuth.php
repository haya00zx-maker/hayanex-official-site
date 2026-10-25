<?php
declare(strict_types=1);
/**
 * Development-only authentication primitives; no HTTP login endpoint.
 * A successful password verification does not grant access until MFA and
 * account status have also been verified by the integrating controller.
 */
final class StaffAuth {
    public static function hashPassword(string $password): string {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new InvalidArgumentException('Password must be 12-1024 bytes');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) throw new RuntimeException('Password hashing failed');
        return $hash;
    }
    public static function verifyPassword(string $password, string $storedHash): bool {
        return $storedHash !== '' && password_verify($password, $storedHash);
    }
    public static function requiresMfa(string $role): bool {
        return in_array($role, ['owner', 'admin'], true);
    }
    public static function canStartSession(string $role, bool $active, bool $passwordVerified, bool $mfaVerified): bool {
        if (!in_array($role, ['owner','admin','staff'], true) || !$active || !$passwordVerified) return false;
        return !self::requiresMfa($role) || $mfaVerified;
    }
}
