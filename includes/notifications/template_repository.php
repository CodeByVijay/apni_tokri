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

    /** @return array<string, mixed>|null */
    public static function findByKeyAny(string $templateKey): ?array
    {
        if (!self::tableReady() || $templateKey === '') {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM notification_templates WHERE template_key = ? LIMIT 1'
        );
        $stmt->execute([$templateKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function updateEmailTemplate(
        string $templateKey,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        bool $isActive
    ): bool {
        if (!self::tableReady() || $templateKey === '') {
            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_templates SET subject = ?, body_html = ?, body_text = ?, is_active = ?, updated_at = NOW()
             WHERE template_key = ? AND channel = ?'
        );
        $stmt->execute([$subject, $bodyHtml, $bodyText, $isActive ? 1 : 0, $templateKey, 'email']);

        return $stmt->rowCount() > 0;
    }
}
