<?php
declare(strict_types=1);
namespace Core;

/** Authenticated encryption for persisted TOTP seeds. */
final class TotpSecretCipher
{
    public const KEY_PATH = '/etc/aidipanel/totp.key';
    private const PREFIX = 'v1:';

    public function __construct(private readonly string $keyPath = self::KEY_PATH)
    {
    }

    public static function system(): self
    {
        return new self(self::KEY_PATH);
    }

    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \RuntimeException('TOTP secret is empty.');
        }

        $key = $this->readKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode(
            $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key)
        );
    }

    public function decrypt(string $stored): string
    {
        if (!$this->isEncrypted($stored)) {
            throw new \RuntimeException('Unsupported TOTP secret format.');
        }

        $key = $this->readKey();
        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (!is_string($payload) || strlen($payload) <= $minimum) {
            throw new \RuntimeException('Malformed encrypted TOTP secret.');
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false || $plaintext === '') {
            throw new \RuntimeException('TOTP secret authentication failed.');
        }
        return $plaintext;
    }

    private function readKey(): string
    {
        if (!extension_loaded('sodium')
            || !function_exists('sodium_crypto_secretbox')
            || !function_exists('sodium_crypto_secretbox_open')
            || !defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')) {
            throw new \RuntimeException('PHP Sodium is unavailable.');
        }
        if ($this->keyPath === '' || is_link($this->keyPath)
            || !is_file($this->keyPath) || !is_readable($this->keyPath)) {
            throw new \RuntimeException('TOTP encryption key is unavailable.');
        }

        $key = @file_get_contents($this->keyPath);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('TOTP encryption key has an invalid size.');
        }
        return $key;
    }
}
