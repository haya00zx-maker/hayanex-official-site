<?php
declare(strict_types=1);
/** Development-only database-backed fixed-window limiter; not an HTTP endpoint. */
final class InquiryRateLimiter {
    public function __construct(private PDO $db) {
        if ($db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PDO exception error mode is required');
        }
    }
    /** Count an attempt before processing the request. Fail closed if DB is unavailable. */
    public function allow(string $identifier, string $secret, int $maxAttempts = 5, int $windowSeconds = 600): bool {
        if ($identifier === '' || strlen($secret) < 32 || $maxAttempts < 1 || $windowSeconds < 60) {
            throw new InvalidArgumentException('Invalid rate limit configuration');
        }
        $key = hash_hmac('sha256', $identifier, $secret);
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        $stmt = $this->db->prepare('INSERT INTO inquiry_rate_limits (key_hash,window_start,attempts) VALUES (?,?,1) ON DUPLICATE KEY UPDATE attempts=attempts+1');
        $stmt->execute([$key,$windowStart]);
        $check = $this->db->prepare('SELECT attempts FROM inquiry_rate_limits WHERE key_hash=? AND window_start=?');
        $check->execute([$key,$windowStart]);
        $attempts = $check->fetchColumn();
        if ($attempts === false) throw new RuntimeException('Rate limit state unavailable');
        return (int)$attempts <= $maxAttempts;
    }
}
