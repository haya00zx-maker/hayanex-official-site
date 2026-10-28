<?php
declare(strict_types=1);
/**
 * Private development-only login attempt policy.
 * An HTTP controller must store counters atomically in a shared server-side
 * database for BOTH account and source address, including unknown usernames.
 * This helper only calculates decisions; it does not enforce throttling alone.
 */
final class StaffLoginRatePolicy {
    public const WINDOW_SECONDS = 900;
    public const MAX_FAILURES = 5;

    /** True if attempt is allowed, based on fresh server-side state. */
    public static function allowed(int $failures, int $windowStarted, int $now): bool {
        if ($failures < 0 || $windowStarted < 0 || $now < $windowStarted) return false;
        if ($now - $windowStarted >= self::WINDOW_SECONDS) return true;
        return $failures < self::MAX_FAILURES;
    }

    /**
     * Record a failed authentication attempt.
     * Return [failures, window_started] to persist atomically under a DB lock.
     */
    public static function failure(int $failures, int $windowStarted, int $now): array {
        if ($failures < 0 || $windowStarted < 0 || $now < $windowStarted || $now < 0) {
            throw new InvalidArgumentException('Invalid attempt state');
        }
        if ($now - $windowStarted >= self::WINDOW_SECONDS) return [1,$now];
        return [$failures + 1,$windowStarted];
    }

    /** Returns seconds remaining in lockout window, 0 if not locked. */
    public static function retryAfter(int $failures, int $windowStarted, int $now): int {
        if ($failures < 0 || $windowStarted < 0 || $now < $windowStarted) {
            return self::WINDOW_SECONDS;
        }
        if ($failures < self::MAX_FAILURES) return 0;
        return max(0,self::WINDOW_SECONDS - ($now-$windowStarted));
    }
}
