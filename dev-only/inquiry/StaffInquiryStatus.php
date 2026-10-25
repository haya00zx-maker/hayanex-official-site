<?php
declare(strict_types=1);
require_once __DIR__ . '/StaffPermissions.php';
/**
 * Development-only case status transition service (NOT an HTTP endpoint).
 * Caller must separately verify session, password/MFA and CSRF.
 * PDO must use ERRMODE_EXCEPTION and an isolated development DB.
 */
final class StaffInquiryStatus {
    private const STATUSES = ['new','reviewing','in_progress','completed','on_hold'];
    public function __construct(private PDO $db) {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PDO exception mode required');
        }
    }
    public static function isValidStatus(string $status): bool {
        return in_array($status, self::STATUSES, true);
    }
    public function change(int $actorId, int $inquiryId, string $to): bool {
        if ($actorId < 1 || $inquiryId < 1 || !self::isValidStatus($to)) {
            throw new InvalidArgumentException('Invalid status change arguments');
        }
        $this->db->beginTransaction();
        try {
            $who = $this->db->prepare('SELECT staff_role,is_active FROM inquiry_staff WHERE id=? FOR UPDATE');
            $who->execute([$actorId]);
            $staff = $who->fetch(PDO::FETCH_ASSOC);
            if (!$staff || (int)$staff['is_active'] !== 1) {
                throw new RuntimeException('Access denied');
            }
            $get = $this->db->prepare('SELECT i.status,a.staff_id AS assignee FROM inquiries i LEFT JOIN inquiry_assignments a ON a.inquiry_id=i.id WHERE i.id=? FOR UPDATE');
            $get->execute([$inquiryId]);
            $case = $get->fetch(PDO::FETCH_ASSOC);
            if (!$case || !StaffPermissions::can((string)$staff['staff_role'],'inquiry_update',$actorId,$case['assignee'] === null ? null : (int)$case['assignee'])) {
                throw new RuntimeException('Access denied');
            }
            $from = (string)$case['status'];
            if ($from === $to) { $this->db->commit(); return false; }
            $update = $this->db->prepare('UPDATE inquiries SET status=? WHERE id=?');
            $update->execute([$to,$inquiryId]);
            $audit = $this->db->prepare('INSERT INTO inquiry_audit_events (inquiry_id,actor_staff_id,event_type,old_value,new_value) VALUES (?,?,?,?,?)');
            $audit->execute([$inquiryId,$actorId,'status_change',$from,$to]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
