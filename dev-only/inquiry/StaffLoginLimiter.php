<?php
declare(strict_types=1);
require_once __DIR__.'/StaffLoginRatePolicy.php';
/** Atomic account AND source failure window. Callback runs under a DB transaction. */
final class StaffLoginLimiter {
    public function __construct(private PDO $db, private string $secret) {
        if (strlen($secret)<32) throw new InvalidArgumentException('Private limiter key required');
    }
    public function attempt(string $login, string $source, callable $verify): array {
        // Match the DB's login collation: case/accent variants of a real account share its ID.
        $lookup=$this->db->prepare('SELECT id FROM inquiry_staff WHERE staff_login=? LIMIT 1');
        $lookup->execute([$login]);$id=$lookup->fetchColumn();
        $canonical=$id===false?'unknown:'.mb_strtolower($login,'UTF-8'):'id:'.(int)$id;
        $keys=[hash_hmac('sha256','account:'.$canonical,$this->secret),hash_hmac('sha256','source:'.$source,$this->secret)];
        sort($keys,SORT_STRING); $now=time();
        $this->db->beginTransaction();
        try {
            $rows=[];
            foreach ($keys as $key) {
                $this->db->prepare('INSERT INTO staff_login_windows (key_hash,failures,window_started) VALUES (?,0,?) ON DUPLICATE KEY UPDATE key_hash=key_hash')->execute([$key,$now]);
                $q=$this->db->prepare('SELECT failures,window_started FROM staff_login_windows WHERE key_hash=? FOR UPDATE');$q->execute([$key]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException('Limiter unavailable');
                $rows[$key]=$row;
                if (!StaffLoginRatePolicy::allowed((int)$row['failures'],(int)$row['window_started'],$now)) {
                    $this->db->rollBack();return ['limited'=>true,'ok'=>false];
                }
            }
            $result=$verify();
            if (!is_array($result)||!isset($result['ok'])||!is_bool($result['ok'])) throw new RuntimeException('Invalid auth result');
            if (!$result['ok']) foreach ($rows as $key=>$row) {
                [$failures,$start]=StaffLoginRatePolicy::failure((int)$row['failures'],(int)$row['window_started'],$now);
                $this->db->prepare('UPDATE staff_login_windows SET failures=?,window_started=? WHERE key_hash=?')->execute([$failures,$start,$key]);
            }
            $this->db->commit();return $result+['limited'=>false];
        } catch (Throwable $e) {if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
