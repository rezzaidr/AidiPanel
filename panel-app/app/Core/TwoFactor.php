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
    public static function enable(
        int $userId,
        string $secret,
        ?TotpSecretCipher $cipher = null
    ): void
    {
        if (!self::isLegacySecret($secret)) {
            throw new \RuntimeException('TOTP secret is not canonical base32.');
        }
        $encrypted = ($cipher ?? TotpSecretCipher::system())->encrypt($secret);
        DB::instance()->run(
            'UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_confirmed_at = ?, totp_last_step = NULL WHERE id = ?',
            [$encrypted, gmdate('Y-m-d H:i:s'), $userId]
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
    public static function verifyTotp(
        int $userId,
        string $code,
        ?TotpSecretCipher $cipher = null
    ): bool
    {
        $db = DB::instance();
        $cipher ??= TotpSecretCipher::system();
        return $db->immediateTransaction(static function (DB $db) use ($userId, $code, $cipher): bool {
            $row = $db->row('SELECT totp_secret, totp_last_step FROM users WHERE id = ?', [$userId]);
            if ($row === null || empty($row['totp_secret'])) {
                return false;
            }

            $stored = (string) $row['totp_secret'];
            $legacy = !$cipher->isEncrypted($stored);
            if ($legacy && !self::isLegacySecret($stored)) {
                error_log("AidiPanel could not read the encrypted TOTP secret for user ID {$userId}.");
                return false;
            }
            try {
                $secret = $legacy ? $stored : $cipher->decrypt($stored);
            } catch (\RuntimeException) {
                error_log("AidiPanel could not read the encrypted TOTP secret for user ID {$userId}.");
                return false;
            }
            if (!self::isLegacySecret($secret)) {
                error_log("AidiPanel could not read the encrypted TOTP secret for user ID {$userId}.");
                return false;
            }

            $step = Totp::verify($secret, $code);
            if ($step === false) {
                return false;
            }
            if ($row['totp_last_step'] !== null && $step <= (int) $row['totp_last_step']) {
                return false;
            }

            if ($legacy) {
                try {
                    $encrypted = $cipher->encrypt($secret);
                } catch (\RuntimeException) {
                    error_log("AidiPanel could not read the encrypted TOTP secret for user ID {$userId}.");
                    return false;
                }
                $db->run(
                    'UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?',
                    [$encrypted, $step, $userId]
                );
            } else {
                $db->run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $userId]);
            }
            return true;
        });
    }

    /** Encrypt every legacy seed atomically and validate existing ciphertext. */
    public static function migrateLegacySecrets(?TotpSecretCipher $cipher = null): int
    {
        $db = DB::instance();
        $cipher ??= TotpSecretCipher::system();
        return $db->immediateTransaction(static function (DB $db) use ($cipher): int {
            $rows = $db->rows(
                "SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL AND totp_secret <> ''"
            );
            $migrated = 0;
            foreach ($rows as $row) {
                $stored = (string) $row['totp_secret'];
                if ($cipher->isEncrypted($stored)) {
                    $secret = $cipher->decrypt($stored);
                    if (!self::isLegacySecret($secret)) {
                        throw new \RuntimeException('Encrypted TOTP secret is not canonical base32.');
                    }
                    continue;
                }
                if (!self::isLegacySecret($stored)) {
                    throw new \RuntimeException('Legacy TOTP secret is not canonical base32.');
                }
                $db->run(
                    'UPDATE users SET totp_secret = ? WHERE id = ?',
                    [$cipher->encrypt($stored), (int) $row['id']]
                );
                $migrated++;
            }
            return $migrated;
        });
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
                [$userId, self::hashRecovery($plain)]
            );
        }
        return $codes;
    }

    /** Redeem a recovery code (single-use). True if it matched an unused code. */
    public static function verifyRecoveryCode(int $userId, string $code): bool
    {
        $normalized = self::normalize($code);
        if ($normalized === '') {
            return false;
        }
        $db = DB::instance();
        return $db->immediateTransaction(static function (DB $db) use ($userId, $normalized): bool {
            // bcrypt is non-deterministic, so look-up can't be by hash. Fetch the
            // (few, <= RECOVERY_COUNT) unused codes for this user and verify each.
            // A legacy single-pass SHA-256 hash (from before this hardening) is
            // accepted once and upgraded to bcrypt on its successful use, so no
            // existing recovery code is silently invalidated.
            $rows = $db->rows(
                'SELECT id, code_hash FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                [$userId]
            );
            foreach ($rows as $row) {
                $stored = (string) $row['code_hash'];
                $matched = str_starts_with($stored, '$2y$')
                    ? password_verify($normalized, $stored)
                    : hash_equals($stored, hash('sha256', $normalized));
                if (!$matched) {
                    continue;
                }
                $now = gmdate('Y-m-d H:i:s');
                $db->run(
                    'UPDATE user_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL',
                    [$now, (int) $row['id']]
                );
                if (!str_starts_with($stored, '$2y$')) {
                    $db->run(
                        'UPDATE user_recovery_codes SET code_hash = ? WHERE id = ?',
                        [password_hash($normalized, PASSWORD_BCRYPT, ['cost' => 12]), (int) $row['id']]
                    );
                }
                return true;
            }
            return false;
        });
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

    /** Normalise a recovery code: lowercase + strip non-alphanumerics (dashes/spaces). */
    private static function normalize(string $code): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));
    }

    private static function isLegacySecret(string $secret): bool
    {
        return preg_match('/^[A-Z2-7]{32}$/D', $secret) === 1;
    }

    /** Hash a recovery code for storage with bcrypt cost 12 (same as user
     *  passwords). Unlike the old single-pass SHA-256, a leaked DB cannot
     *  brute-force these offline. Legacy SHA-256 hashes are migrated on first
     *  successful verify (see verifyRecoveryCode). */
    private static function hashRecovery(string $code): string
    {
        return password_hash(self::normalize($code), PASSWORD_BCRYPT, ['cost' => 12]);
    }
}
