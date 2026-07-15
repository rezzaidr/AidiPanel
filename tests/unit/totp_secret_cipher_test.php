<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/panel-app/app/Core/TotpSecretCipher.php';

use Core\TotpSecretCipher;

function cipherCheck(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "ok: {$label}\n";
}

function cipherThrows(callable $callback, string $label): void
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

$tmp = sys_get_temp_dir() . '/aidipanel-totp-cipher-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
$key = $tmp . '/totp.key';
$wrong = $tmp . '/wrong.key';
$short = $tmp . '/short.key';
file_put_contents($key, random_bytes(32));
file_put_contents($wrong, random_bytes(32));
file_put_contents($short, random_bytes(31));

try {
    $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    $cipher = new TotpSecretCipher($key);
    $first = $cipher->encrypt($secret);
    $second = $cipher->encrypt($secret);

    cipherCheck(str_starts_with($first, 'v1:'), 'ciphertext has an explicit v1 marker');
    cipherCheck(!str_contains($first, $secret), 'ciphertext does not expose plaintext');
    cipherCheck($first !== $second, 'a fresh nonce changes repeated ciphertext');
    cipherCheck($cipher->decrypt($first) === $secret, 'ciphertext round-trips');
    cipherCheck($cipher->isEncrypted($first), 'v1 ciphertext is recognized');
    cipherCheck(!$cipher->isEncrypted($secret), 'legacy base32 is not mislabeled encrypted');

    $raw = base64_decode(substr($first, 3), true);
    cipherCheck(is_string($raw), 'ciphertext body uses strict base64');
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $tampered = 'v1:' . base64_encode($raw);

    cipherThrows(fn () => $cipher->decrypt($tampered), 'tampering fails authentication');
    cipherThrows(fn () => (new TotpSecretCipher($wrong))->decrypt($first), 'a wrong key fails authentication');
    cipherThrows(fn () => $cipher->decrypt('v1:not base64!'), 'malformed base64 fails closed');
    cipherThrows(fn () => $cipher->decrypt('v1:' . base64_encode('short')), 'truncated payload fails closed');
    cipherThrows(fn () => (new TotpSecretCipher($tmp . '/missing'))->encrypt($secret), 'missing key fails closed');
    cipherThrows(fn () => (new TotpSecretCipher($short))->encrypt($secret), 'wrong-sized key fails closed');
} finally {
    @unlink($key);
    @unlink($wrong);
    @unlink($short);
    @rmdir($tmp);
}

echo "TOTP secret cipher tests passed\n";
