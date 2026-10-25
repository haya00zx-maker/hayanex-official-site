<?php
declare(strict_types=1);
/**
 * Development-only CSRF and submission token helper.
 * Call only from a private HTTPS route with secure session cookie settings.
 * Do not use as an HTTP endpoint by itself.
 */
final class InquiryRequestGuard {
    public static function issueToken(): string {
        return bin2hex(random_bytes(32));
    }
    public static function verifyToken(string $received, string $expected): bool {
        return (bool) preg_match('/^[a-f0-9]{64}$/D',$received)
            && (bool) preg_match('/^[a-f0-9]{64}$/D',$expected)
            && hash_equals($expected,$received);
    }
    public static function deriveRequestKey(string $opaqueToken, string $privateSecret): string {
        if (!preg_match('/^[a-f0-9]{64}$/D',$opaqueToken) || strlen($privateSecret) < 32) {
            throw new InvalidArgumentException('Invalid submission token or private secret');
        }
        return hash_hmac('sha256',$opaqueToken,$privateSecret);
    }
}
