<?php
declare(strict_types=1);

function storageCheck(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

function storageThrows(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        echo "ok: {$label}\n";
        return;
    }
    fwrite(STDERR, "FAIL: {$label}\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/aidipanel-totp-storage-' . bin2hex(random_bytes(6));
$keyPath = $tmp . '/totp.key';
mkdir($tmp . '/storage/db', 0770, true);
file_put_contents($keyPath, random_bytes(32));

define('PANEL_DIR', $tmp);
define('APP_ROOT', $root . '/panel-app/app');
putenv('AIDIPANEL_ADMIN_HASH=' . password_hash('test-password', PASSWORD_BCRYPT));

spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Core\DB;
use Core\Totp;
use Core\TotpSecretCipher;
use Core\TwoFactor;

try {
    $db = DB::instance();
    $userId = (int) $db->value("SELECT id FROM users WHERE username = 'admin'");
    storageCheck($userId > 0, 'test admin exists');

    $cipher = new TotpSecretCipher($keyPath);
    $secret = Totp::generateSecret();
    storageCheck(strlen($secret) === 32, 'generated TOTP seed is canonical base32');

    TwoFactor::enable($userId, $secret, $cipher);
    $stored = (string) $db->value('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
    storageCheck(str_starts_with($stored, 'v1:'), 'new enrollment stores encrypted data');
    storageCheck(!str_contains($stored, $secret), 'new enrollment does not persist plaintext');

    $step = Totp::currentStep();
    $code = Totp::codeAt($secret, $step);
    storageCheck(TwoFactor::verifyTotp($userId, $code, $cipher), 'encrypted TOTP verifies');
    storageCheck(!TwoFactor::verifyTotp($userId, $code, $cipher), 'same-step replay remains rejected');

    $db->run(
        'UPDATE users SET totp_secret = ?, totp_last_step = NULL WHERE id = ?',
        [$secret, $userId]
    );
    storageCheck(TwoFactor::verifyTotp($userId, $code, $cipher), 'legacy TOTP verifies');
    $lazyStored = (string) $db->value('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
    storageCheck(str_starts_with($lazyStored, 'v1:'), 'successful legacy login upgrades storage atomically');
    storageCheck((int) $db->value('SELECT totp_last_step FROM users WHERE id = ?', [$userId]) === $step, 'legacy upgrade preserves replay marker');

    $db->run(
        'INSERT INTO users (username, password_hash, role, active) VALUES (?, ?, ?, 1)',
        ['second', password_hash('test-password', PASSWORD_BCRYPT), 'admin']
    );
    $secondId = (int) $db->value("SELECT id FROM users WHERE username = 'second'");
    $secondSecret = Totp::generateSecret();
    $db->run('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $userId]);
    $db->run('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secondSecret, $secondId]);

    storageCheck(TwoFactor::migrateLegacySecrets($cipher) === 2, 'bulk migration converts every legacy row');
    storageCheck(str_starts_with((string) $db->value('SELECT totp_secret FROM users WHERE id = ?', [$userId]), 'v1:'), 'bulk migration encrypts first row');
    storageCheck(str_starts_with((string) $db->value('SELECT totp_secret FROM users WHERE id = ?', [$secondId]), 'v1:'), 'bulk migration encrypts second row');

    $db->run('UPDATE users SET totp_secret = ? WHERE id = ?', [$secret, $userId]);
    $db->run('UPDATE users SET totp_secret = ? WHERE id = ?', ['NOT-BASE32', $secondId]);
    storageThrows(fn () => TwoFactor::migrateLegacySecrets($cipher), 'malformed legacy row aborts bulk migration');
    storageCheck((string) $db->value('SELECT totp_secret FROM users WHERE id = ?', [$userId]) === $secret, 'failed bulk migration rolls back valid-row conversion');
    storageCheck((string) $db->value('SELECT totp_secret FROM users WHERE id = ?', [$secondId]) === 'NOT-BASE32', 'failed bulk migration preserves malformed row for diagnosis');

    TwoFactor::enable($userId, $secret, $cipher);
    $recoveryCodes = TwoFactor::generateRecoveryCodes($userId);
    $missingCipher = new TotpSecretCipher($tmp . '/missing.key');
    storageCheck(!TwoFactor::verifyTotp($userId, $code, $missingCipher), 'unreadable encryption key fails TOTP closed without throwing');
    storageCheck(TwoFactor::verifyRecoveryCode($userId, $recoveryCodes[0]), 'recovery code remains usable after TOTP key failure');
} finally {
    putenv('AIDIPANEL_ADMIN_HASH');
    @unlink($keyPath);
    @unlink($tmp . '/storage/db/aidipanel.sqlite');
    @unlink($tmp . '/storage/db/aidipanel.sqlite-shm');
    @unlink($tmp . '/storage/db/aidipanel.sqlite-wal');
    @rmdir($tmp . '/storage/db');
    @rmdir($tmp . '/storage');
    @rmdir($tmp);
}

echo "TOTP secret storage tests passed\n";
