<?php

declare(strict_types=1);

final class Install
{
    public const SCHEMA_VERSION = '1.20.0-invoices';

    /** @var list<string> */
    private static $markerTables = ['settings', 'admin_users', 'customers', 'products', 'orders'];

    public static function hasLocalConfig(): bool
    {
        return is_readable(APNI_TOKRI_ROOT . '/includes/config.local.php');
    }

    public static function canConnect(): bool
    {
        if (!self::hasLocalConfig()) {
            return false;
        }
        try {
            return Database::ping(App::config()['database']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function ensureConnection(): void
    {
        if (!self::hasLocalConfig()) {
            throw new RuntimeException('Missing config.local.php');
        }
        Database::connect(App::config()['database']);
    }

    /** @return list<string> */
    public static function missingTables(): array
    {
        if (!self::canConnect()) {
            return self::$markerTables;
        }
        try {
            self::ensureConnection();
            $pdo = Database::pdo();
            $missing = [];
            foreach (self::$markerTables as $table) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $stmt->execute([$table]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $missing[] = $table;
                }
            }

            return $missing;
        } catch (Throwable $e) {
            return self::$markerTables;
        }
    }

    public static function isSchemaReady(): bool
    {
        return self::missingTables() === [];
    }

    public static function installedSchemaVersion(): ?string
    {
        if (!self::isSchemaReady()) {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute(['schema_version']);

            return $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function isVersionCurrent(): bool
    {
        $v = self::installedSchemaVersion();

        return $v === self::SCHEMA_VERSION;
    }

    /** @return array<string, mixed> */
    public static function statusReport(): array
    {
        return [
            'config' => self::hasLocalConfig(),
            'connect' => self::canConnect(),
            'schema_ready' => self::isSchemaReady(),
            'schema_version' => self::installedSchemaVersion(),
            'schema_current' => self::isVersionCurrent(),
            'missing_tables' => self::missingTables(),
        ];
    }
}
