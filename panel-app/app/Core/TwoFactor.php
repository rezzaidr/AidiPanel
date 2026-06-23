<?php
declare(strict_types=1);
namespace Core;

/**
 * Two-factor state for a panel user: enable/disable, TOTP verification with a
 * replay guard, and one-time recovery codes. Pure orchestration over Core\Totp +
 * the panel SQLite DB. See _private/specs/2026-06-23-panel-2fa-totp-design.md.
 */
final class TwoFactor
{
    public const RECOVERY_COUNT = 10;

    public static function isEnabled(int $userId): bool
    {
        $row = DB::instance()->row('SELECT totp_enabled FROM users WHERE id = ?', [$userId]);
        return $row !== null && (int) $row['totp_enabled'] === 1;
    }

    /** Persist a confirmed secret and turn 2FA on (clears any stale replay marker). */
    public static function enable(int $userId, string $secret): void
    {
        DB::instance()->run(
            'UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_confirmed_at = ?, totp_last_step = NULL WHERE id = ?',
            [$secret, gmdate('Y-m-d H:i:s'), $userId]
        );
    }

    /** Turn 2FA off and erase all of its state, including recovery codes. */
    public static function disable(int $userId): void
    {
        $db = DB::instance();
        $db->run(
            'UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_confirmed_at = NULL, totp_last_step = NULL WHERE id = ?',
            [$userId]
        );
        $db->run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
    }

    /**
     * Verify a TOTP code for an enabled user. Enforces single-use within the 30s
     * window via totp_last_step (a code from a step <= the last accepted step is
     * rejected). Returns true on success.
     */
    public static function verifyTotp(int $userId, string $code): bool
    {
        $row = DB::instance()->row('SELECT totp_secret, totp_last_step FROM users WHERE id = ?', [$userId]);
        if ($row === null || empty($row['totp_secret'])) {
            return false;
        }
        $step = Totp::verify((string) $row['totp_secret'], $code);
        if ($step === false) {
            return false;
        }
        if ($row['totp_last_step'] !== null && $step <= (int) $row['totp_last_step']) {
            return false;   // replay of an already-used (or older) code
        }
        DB::instance()->run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $userId]);
        return true;
    }

    /**
     * Generate a fresh set of recovery codes, replace any existing set with their
     * hashes, and return the plaintext codes to show the user exactly once.
     */
    public static function generateRecoveryCodes(int $userId): array
    {
        $db = DB::instance();
        $db->run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $plain   = self::randomRecoveryCode();
            $codes[] = $plain;
            $db->run(
                'INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)',
                [$userId, self::hashCode($plain)]
            );
        }
        return $codes;
    }

    /** Redeem a recovery code (single-use). True if it matched an unused code. */
    public static function verifyRecoveryCode(int $userId, string $code): bool
    {
        $db  = DB::instance();
        $row = $db->row(
            'SELECT id FROM user_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1',
            [$userId, self::hashCode($code)]
        );
        if ($row === null) {
            return false;
        }
        $db->run('UPDATE user_recovery_codes SET used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), (int) $row['id']]);
        return true;
    }

    public static function remainingRecoveryCodes(int $userId): int
    {
        return (int) DB::instance()->value(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }

    /** A recovery code: 10 lowercase base32 chars shown grouped (abcde-fghij). */
    private static function randomRecoveryCode(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $raw = '';
        for ($i = 0; $i < 10; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }

    /** Normalise (lowercase, strip non-alphanumerics) then SHA-256 for storage/compare. */
    public static function hashCode(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code)));
    }
}
