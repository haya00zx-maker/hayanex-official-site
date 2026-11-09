<?php
declare(strict_types=1);
/** Transactional local notification sink ONLY. No mail/network transport is implemented. */
final class DevNotificationWorker {
    public function __construct(private PDO $db) {
        if ($db->query('SELECT DATABASE()')->fetchColumn()!=='hayanex_http_test') throw new RuntimeException('Disposable DB only');
    }
    public function once(bool $simulateFailure=false): bool {
        $this->db->beginTransaction();
        try {
            $row=$this->db->query("SELECT o.id,o.notification_type,i.receipt_no,i.email FROM notification_outbox o JOIN inquiries i ON i.id=o.inquiry_id WHERE o.status IN ('pending','failed') AND o.attempts<5 ORDER BY o.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch(PDO::FETCH_ASSOC);
            if (!$row) {$this->db->commit();return false;}
            if ($simulateFailure) {
                $this->db->prepare("UPDATE notification_outbox SET status='failed',attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
            } else {
                if (!str_ends_with($row['email'],'@example.invalid')) throw new RuntimeException('Synthetic recipient required');
                $payload=json_encode(['receipt'=>$row['receipt_no'],'type'=>$row['notification_type'],'recipient'=>$row['notification_type']==='staff_alert'?'staff@example.invalid':$row['email']],JSON_THROW_ON_ERROR);
                $this->db->prepare('INSERT INTO dev_notification_deliveries (outbox_id,payload) VALUES (?,?)')->execute([$row['id'],$payload]);
                $this->db->prepare("UPDATE notification_outbox SET status='simulated',attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
            }
            $this->db->commit();return true;
        } catch (Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
