<?php
declare(strict_types=1);
/**
 * Development-only RFC6238 TOTP primitive. Not a login endpoint.
 * Keep enrolled secrets encrypted outside public storage; never log them.
 * Controller MUST enforce attempt limits, TLS, active account, verified password,
 * and persist last accepted counter atomically to reject replay.
 */
final class StaffTotp {
    public static function generateSecret(): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        $result = '';
        foreach (str_split($bits, 5) as $chunk) $result .= $alphabet[bindec($chunk)];
        return $result;
    }
    private static function decode(string $secret): string {
        $secret = strtoupper($secret);
        if ($secret === '' || preg_match('/[^A-Z2-7]/', $secret)) throw new InvalidArgumentException('Invalid Base32');
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0; $bits = 0; $output = '';
        foreach (str_split($secret) as $char) {
            $index = strpos($alphabet, $char);
            $buffer = ($buffer << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }
        if (strlen($output) < 10 || $bits > 0 && $buffer !== 0) {
            throw new InvalidArgumentException('Invalid secret');
        }
        return $output;
    }
    public static function code(string $secret, int $counter): string {
        if ($counter < 0) throw new InvalidArgumentException('Invalid counter');
        $key = self::decode($secret);
        $binary = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 15;
        $num = ((ord($hash[$offset]) & 127) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
    }
    /** Return accepted counter for caller to store and compare atomically, else null. */
    public static function verify(string $secret, string $submitted, int $unixTime, int $lastAccepted = -1): ?int {
        if (!preg_match('/^[0-9]{6}$/D', $submitted) || $unixTime < 0) return null;
        $current = intdiv($unixTime, 30);
        for ($delta = -1; $delta <= 1; $delta++) {
            $counter = $current + $delta;
            if ($counter <= $lastAccepted || $counter < 0) continue;
            if (hash_equals(self::code($secret, $counter), $submitted)) return $counter;
        }
        return null;
    }
}
