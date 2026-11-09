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
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if ($algorithm === PASSWORD_BCRYPT && strlen($password)>72) throw new InvalidArgumentException('Long passwords require Argon2id');
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) throw new RuntimeException('Password hashing failed');
        return $hash;
    }
    /** Public dummy hash, not a credential. Keep unknown-user work comparable to new hashes. */
    public static function dummyHash(): string {
        return defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$aERtaW1iT1MwbW5FbTNHYg$WT4vKHx/6a/DkL7yBxuN9BJafiMy/G1PiY90F/4tJ1E'
            : '$2y$10$abcdefghijklmnopqrstuuoQEfct.XyoNGCe.dcsSLHxA3.XehOy2';
    }
    public static function verifyPassword(string $password, string $storedHash): bool {
        // Legacy bcrypt must not silently accept an alternate suffix after byte 72.
        if (str_starts_with($storedHash, '$2') && strlen($password)>72) return false;
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
