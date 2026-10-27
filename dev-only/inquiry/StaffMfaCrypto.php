<?php
declare(strict_types=1);
/**
 * Development-only MFA secret encryption component.
 * Keys MUST come from a private server-side secret manager / config outside Git.
 * This class is not an HTTP endpoint and does not enroll or authenticate users.
 */
final class StaffMfaCrypto {
    private const PREFIX = 'sodium-v1:';

    public static function encrypt(string $base32Secret, string $rawKey, int $staffId): string {
        self::validateKeyAndStaff($rawKey, $staffId);
        if (!preg_match('/^[A-Z2-7]{16,128}$/D', $base32Secret)) {
            throw new InvalidArgumentException('Invalid TOTP secret format');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = sodium_crypto_secretbox(
            $base32Secret . ':' . $staffId,
            $nonce,
            $rawKey
        );
        return self::PREFIX . base64_encode($nonce . $payload);
    }

    public static function decrypt(string $encoded, string $rawKey, int $staffId): string {
        self::validateKeyAndStaff($rawKey, $staffId);
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new RuntimeException('Unknown MFA ciphertext version');
        }
        $payload = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if (!is_string($payload) ||
            strlen($payload) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Invalid MFA ciphertext');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $rawKey);
        if (!is_string($plain)) {
            throw new RuntimeException('MFA ciphertext authentication failed');
        }
        $suffix = ':' . $staffId;
        if (!str_ends_with($plain, $suffix)) {
            throw new RuntimeException('MFA secret assigned to another staff account');
        }
        return substr($plain, 0, -strlen($suffix));
    }

    private static function validateKeyAndStaff(string $key, int $staffId): void {
        if (!extension_loaded('sodium')) throw new RuntimeException('Sodium required');
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || $staffId <= 0) {
            throw new InvalidArgumentException('Invalid MFA encryption key or staff ID');
        }
    }
}
