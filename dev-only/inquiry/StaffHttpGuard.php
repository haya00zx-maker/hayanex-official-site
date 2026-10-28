<?php
declare(strict_types=1);
/**
 * Private development-only HTTP request primitives. Not a routed endpoint.
 * Caller must use a hardened server-side session and HTTPS before rendering
 * or validating any actual form. Reverse-proxy TLS must be checked by trusted
 * server configuration, never by arbitrary client headers.
 */
final class StaffHttpGuard {
    public static function newCsrfToken(): string {
        return bin2hex(random_bytes(32));
    }
    public static function validCsrf(string $expected, ?string $submitted): bool {
        return strlen($expected)===64
            && ctype_xdigit($expected)
            && is_string($submitted)
            && strlen($submitted)===64
            && ctype_xdigit($submitted)
            && hash_equals($expected,$submitted);
    }
    public static function canProcessPost(
        string $method,
        bool $httpsVerified,
        string $expectedCsrf,
        ?string $submittedCsrf,
        ?string $contentType,
        int $contentBytes
    ): bool {
        if ($method!=='POST' || !$httpsVerified || $contentBytes<0 || $contentBytes>8192) return false;
        if (!is_string($contentType) || !preg_match(
            '~^application/x-www-form-urlencoded(?:\s*;\s*charset=(?:utf-8|UTF-8))?$~D',
            trim($contentType)
        )) return false;
        return self::validCsrf($expectedCsrf,$submittedCsrf);
    }
}
