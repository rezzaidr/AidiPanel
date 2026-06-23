<?php
declare(strict_types=1);
namespace Core;

/**
 * RFC 6238 Time-based One-Time Passwords (SHA1, 6 digits, 30s period).
 * Pure PHP, zero dependencies. Secrets are base32 (RFC 4648, no padding) — the
 * format authenticator apps (Google Authenticator, 1Password, …) expect.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random base32 secret (default 20 bytes = 160 bits, per RFC 4226 §5.3). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32encode(random_bytes($bytes));
    }

    /** The otpauth:// URI an authenticator app imports (rendered as a QR or typed in). */
    public static function uri(string $secret, string $account, string $issuer = 'AidiPanel'): string
    {
        $label  = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /** The time step (counter) for a unix timestamp. */
    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** The 6-digit code for a secret at a given step. */
    public static function codeAt(string $secret, int $step): string
    {
        $key     = self::base32decode($secret);
        $counter = pack('N', 0) . pack('N', $step);          // 64-bit big-endian counter
        $hash    = hash_hmac('sha1', $counter, $key, true);
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part    = ((ord($hash[$offset]) & 0x7F) << 24)
                 | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                 | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                 |  (ord($hash[$offset + 3]) & 0xFF);
        $code    = $part % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-entered code against steps [now-window … now+window].
     * Returns the matched step (so the caller can persist it for replay protection)
     * or false if nothing matches.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $time = null): int|false
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $now = self::currentStep($time);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $now + $i), $code)) {
                return $now + $i;
            }
        }
        return false;
    }

    public static function base32encode(string $bytes): string
    {
        if ($bytes === '') { return ''; }
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        if ($b32 === '') { return ''; }
        $bits = '';
        for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $b32[$i])), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
