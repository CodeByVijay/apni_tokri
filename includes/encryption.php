<?php

declare(strict_types=1);

final class SecretStore
{
    /** @var string|null */
    private static $masterKey;

    private static function masterKey(): string
    {
        if (self::$masterKey !== null) {
            return self::$masterKey;
        }
        $path = (string) (App::config()['encryption']['master_key_path'] ?? '');
        if ($path === '') {
            $path = APNI_TOKRI_ROOT . '/storage/secrets/master.key';
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_readable($path)) {
            if (!is_file($path)) {
                $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                file_put_contents($path, $key, LOCK_EX);
                @chmod($path, 0600);
            }
        }
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Encryption master key unavailable.');
        }
        self::$masterKey = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        return self::$masterKey;
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = self::masterKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        $key = self::masterKey();
        $bin = base64_decode($encoded, true);
        if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            throw new RuntimeException('Invalid encrypted value.');
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new RuntimeException('Could not decrypt value.');
        }

        return $plain;
    }
}
