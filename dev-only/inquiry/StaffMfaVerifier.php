<?php
declare(strict_types=1);
require_once __DIR__.'/StaffMfaCrypto.php';
require_once __DIR__.'/StaffTotp.php';
require_once __DIR__.'/StaffLoginFlow.php';
/** Caller holds login limiter transaction; consume TOTP counter before issuing session. */
final class StaffMfaVerifier {
    public function __construct(private PDO $db, private string $key) {}
    public function verify(array $pending, string $code, int $now): ?string {
        if (!$this->db->inTransaction()) throw new RuntimeException('Transaction required');
        if (!StaffLoginFlow::pendingValid($pending,$now)) return null;
        $q=$this->db->prepare('SELECT s.staff_role,s.is_active,s.password_hash,m.encrypted_totp_secret,m.last_accepted_counter FROM inquiry_staff s JOIN inquiry_staff_mfa m ON m.staff_id=s.id WHERE s.id=? FOR UPDATE');
        $q->execute([$pending['staff_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active']!==1 || $row['staff_role']!==$pending['role'] || !isset($pending['password_stamp']) || !hash_equals($pending['password_stamp'],hash('sha256',$row['password_hash']))) return null;
        $secret=StaffMfaCrypto::decrypt($row['encrypted_totp_secret'],$this->key,$pending['staff_id']);
        $accepted=StaffTotp::verify($secret,$code,$now,(int)$row['last_accepted_counter']);
        sodium_memzero($secret);
        if ($accepted===null) return null;
        $q=$this->db->prepare('UPDATE inquiry_staff_mfa SET last_accepted_counter=? WHERE staff_id=? AND last_accepted_counter<?');
        $q->execute([$accepted,$pending['staff_id'],$accepted]);return $q->rowCount()===1 ? hash('sha256',$row['password_hash'].'|'.$row['encrypted_totp_secret']) : null;
    }
}
