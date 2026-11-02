<?php

declare(strict_types=1);

final class AppSettings
{
    /** @var array<string, string|null>|null */
    private static $cache;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::loadCache();

        return self::$cache[$key] ?? $default;
    }

    public static function getSecret(string $key, ?string $default = null): ?string
    {
        if (!Install::isSchemaReady()) {
            return $default;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT setting_value, is_encrypted FROM settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $default;
            }
            $val = (string) ($row['setting_value'] ?? '');
            if ((int) ($row['is_encrypted'] ?? 0) === 1 && $val !== '') {
                return SecretStore::decrypt($val);
            }

            return $val !== '' ? $val : $default;
        } catch (Throwable $e) {
            error_log('AppSettings::getSecret: ' . $e->getMessage());

            return $default;
        }
    }

    public static function set(string $key, ?string $value, bool $encrypt = false): void
    {
        Install::ensureConnection();
        $stored = $value ?? '';
        $isEnc = 0;
        if ($encrypt && $stored !== '') {
            $stored = SecretStore::encrypt($stored);
            $isEnc = 1;
        }
        Database::pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted), updated_at = NOW()'
        )->execute([$key, $stored, $isEnc]);
        self::$cache = null;
    }

    /** @param array<string, array{value: ?string, encrypt?: bool}> $batch */
    public static function setMany(array $batch): void
    {
        foreach ($batch as $key => $spec) {
            self::set($key, $spec['value'] ?? null, !empty($spec['encrypt']));
        }
    }

    private static function loadCache(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        if (!Install::isSchemaReady()) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query('SELECT setting_key, setting_value FROM settings');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                self::$cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        } catch (Throwable $e) {
            self::$cache = [];
        }
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
