<?php
declare(strict_types=1);
/**
 * Internal development-only receipt persistence service.
 * This is NOT an HTTP endpoint. Never expose this folder as executable web routes.
 * Database credentials must be configured privately outside the public repository.
 */
require_once __DIR__ . '/InquiryValidator.php';
final class InquiryRepository {
    public function __construct(private PDO $db) {}
    /**
     * Atomic idempotent registration. Caller must enforce HTTP-boundary validation,
     * enforce CSRF and rate limits, and obtain consent before calling.
     * $key must be a 64-character lowercase hex HMAC/sha256 of a cryptographically
     * random opaque request token; do not use an IP address or personal data as key.
     * Date grouping uses the configured server timezone (Asia/Tokyo).
     */
    public function register(array $data, string $key, bool $privacyConsent): string {
        if (!$privacyConsent) throw new InvalidArgumentException('Consent required');
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new InvalidArgumentException('Invalid request key');
        }
        $normalized = InquiryValidator::normalize($data);
        $digest = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->db->beginTransaction();
        try {
            // Serialize attempts using an advisory transaction row. This table row is
            // created with a provisional record? No: lock the day sequence first.
            $day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            $this->db->prepare('INSERT INTO inquiry_sequences (received_day,last_number) VALUES (?,0) ON DUPLICATE KEY UPDATE last_number=last_number')->execute([$day]);
            $lock = $this->db->prepare('SELECT last_number FROM inquiry_sequences WHERE received_day=? FOR UPDATE');
            $lock->execute([$day]);
            $num = (int)$lock->fetchColumn();
            $existing = $this->db->prepare('SELECT r.payload_hash,i.receipt_no FROM inquiry_requests r JOIN inquiries i ON i.id=r.inquiry_id WHERE r.request_key=?');
            $existing->execute([$key]);
            $previous = $existing->fetch(PDO::FETCH_ASSOC);
            if ($previous) {
                if (!hash_equals($previous['payload_hash'], $digest)) throw new InvalidArgumentException('Request key reused with changed fields');
                $this->db->commit();
                return $previous['receipt_no'];
            }
            $num++;
            $receipt = sprintf('HX-%s-%04d', str_replace('-', '', $day), $num);
            $this->db->prepare('UPDATE inquiry_sequences SET last_number=? WHERE received_day=?')->execute([$num,$day]);
            $stmt=$this->db->prepare('INSERT INTO inquiries (receipt_no,inquiry_type,client_type,person_name,organization_name,email,phone,service,message,budget,desired_deadline,consent_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(6))');
            $stmt->execute([$receipt,$normalized['inquiry_type'],$normalized['client_type'],$normalized['person_name'],$normalized['organization_name'],$normalized['email'],$normalized['phone'],$normalized['service'],$normalized['message'],$normalized['budget'],$normalized['desired_deadline']]);
            $id=(int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO inquiry_requests (request_key,payload_hash,inquiry_id) VALUES (?,?,?)')->execute([$key,$digest,$id]);
            $notify=$this->db->prepare('INSERT INTO notification_outbox (inquiry_id,notification_type) VALUES (?,?)');
            $notify->execute([$id,'customer_confirmation']);
            $notify->execute([$id,'staff_alert']);
            $this->db->commit();
            return $receipt;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
