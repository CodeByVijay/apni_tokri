<?php

declare(strict_types=1);

final class NotificationTemplateRepository
{
    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notification_templates'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public static function findByKey(string $templateKey): ?array
    {
        if (!self::tableReady() || $templateKey === '') {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM notification_templates WHERE template_key = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$templateKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
