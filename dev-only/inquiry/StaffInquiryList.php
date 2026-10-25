<?php
declare(strict_types=1);
require_once __DIR__ . '/StaffPermissions.php';
/**
 * Private, development-only query helper. NOT an HTTP endpoint.
 * Caller must check active staff account, password, MFA and live session.
 * Never render returned customer information without escaping it.
 */
final class StaffInquiryList {
    public function __construct(private PDO $db) {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PDO exception mode required');
        }
    }

    /** Public-facing controllers must never call this class. */
    public function listFor(string $role, int $staffId, int $limit = 25, int $offset = 0): array {
        if ($staffId < 1 || $limit < 1 || $limit > 100 || $offset < 0) {
            throw new InvalidArgumentException('Invalid pagination or identity');
        }
        // Verify server-supplied identity against current database state; do not trust a stale role.
        $identity = $this->db->prepare('SELECT staff_role, is_active FROM inquiry_staff WHERE id = :id');
        $identity->bindValue(':id', $staffId, PDO::PARAM_INT);
        $identity->execute();
        $account = $identity->fetch(PDO::FETCH_ASSOC);
        if (!$account || (int)$account['is_active'] !== 1 || !hash_equals((string)$account['staff_role'], $role)) {
            throw new RuntimeException('Access denied');
        }
        if (!StaffPermissions::can($role, 'inquiry_view', $staffId, $staffId)) {
            throw new RuntimeException('Access denied');
        }
        $base = 'SELECT i.id, i.receipt_no, i.received_at, i.inquiry_type, i.status, a.staff_id AS assigned_staff_id
                 FROM inquiries i LEFT JOIN inquiry_assignments a ON a.inquiry_id = i.id';
        // Enforce row-level restrictions in SQL, never filter after fetching.
        if ($role === 'staff') {
            $sql = $base . ' WHERE a.staff_id = :staff ORDER BY i.received_at DESC, i.id DESC LIMIT :lim OFFSET :off';
        } else {
            $sql = $base . ' ORDER BY i.received_at DESC, i.id DESC LIMIT :lim OFFSET :off';
        }
        $stmt = $this->db->prepare($sql);
        if ($role === 'staff') $stmt->bindValue(':staff', $staffId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
