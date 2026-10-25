<?php
declare(strict_types=1);
/**
 * Development-only MFA secret encryption. NOT an HTTP endpoint.
 * Key is a 32-byte value injected from server-side secrets, NEVER committed.
 * No fallback plaintext storage.
 */
final class StaffMfaSecretBox {
    public static function encrypt(string $base32Secret, string $key): string {
        self::checkKey($key);
        if (!preg_match('/^[A-Z2-7]{32}$/D', $base32Secret)) throw new InvalidArgumentException('Invalid TOTP secret');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($base32Secret, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }
    public static function decrypt(string $encoded, string $key): string {
        self::checkKey($key);
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new InvalidArgumentException('Invalid encrypted secret');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plaintext === false) throw new RuntimeException('Could not authenticate encrypted secret');
        return $plaintext;
    }
    private static function checkKey(string $key): void {
        if (!function_exists('sodium_crypto_secretbox') || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('A server-side 32-byte sodium key is required');
        }
    }
}
