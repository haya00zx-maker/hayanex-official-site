<?php
declare(strict_types=1);
/**
 * Private development-only DB password verification service.
 * This is NOT a login endpoint: controller must enforce TLS, CSRF,
 * IP/account rate limits, and MFA before any session is issued.
 * Never reveal whether a login name exists.
 */
require_once __DIR__.'/StaffAuth.php';

final class StaffCredentialRepository {
    private const MAX_LOGIN_BYTES = 100;
    private const MAX_PASSWORD_BYTES = 1024;
    /** Never use this development class to create an HTTP route. */
    public function __construct(private PDO $db) {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PDO exception mode required');
        }
    }

    /** Returns verified identity, or null. It does NOT create a session. */
    public function verify(string $login, string $password): ?array {
        if ($login === '' || strlen($login) > self::MAX_LOGIN_BYTES ||
            strlen($password) > self::MAX_PASSWORD_BYTES || $password === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, staff_role, is_active, password_hash FROM inquiry_staff WHERE staff_login=? LIMIT 1'
        );
        $stmt->execute([$login]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Equalize common unknown-user and invalid-password paths without
        // generating an expensive hash on every request.
        $dummy = '$2y$10$abcdefghijklmnopqrstuuoQEfct.XyoNGCe.dcsSLHxA3.XehOy2';
        $verified = StaffAuth::verifyPassword($password, $row ? (string)$row['password_hash'] : $dummy);
        if (!$row || !$verified || (int)$row['is_active'] !== 1 ||
            !in_array($row['staff_role'], ['owner','admin','staff'], true)) {
            return null;
        }
        return [
            'staff_id'=>(int)$row['id'],
            'role'=>(string)$row['staff_role'],
            'mfa_required'=>StaffAuth::requiresMfa((string)$row['staff_role']),
        ];
    }
}
