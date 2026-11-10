<?php

declare(strict_types=1);

final class PushSchema
{
    public static function tableReady(string $table): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $stmt->execute([$table]);
            $cache[$table] = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    public static function ready(): bool
    {
        return self::tableReady('firebase_projects')
            && self::tableReady('device_push_registrations')
            && self::tableReady('push_notification_templates')
            && self::tableReady('push_notification_logs');
    }
}
