<?php

declare(strict_types=1);

final class PushTemplateRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public static function findActive(string $templateKey): ?array
    {
        if (!PushSchema::tableReady('push_notification_templates')) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM push_notification_templates WHERE template_key = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$templateKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

  /**
     * @param array<string, string|int|float|null> $vars
     */
    public static function renderField(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $val) {
            $out = str_replace('{' . $key . '}', (string) $val, $out);
        }

        return trim(preg_replace('/\{[a-z0-9_]+\}/i', '', $out) ?? $out);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listAll(): array
    {
        if (!PushSchema::tableReady('push_notification_templates')) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT id, template_key, event_type, title_template, body_template,
                    click_url_template, image_url_template, is_active, updated_at
             FROM push_notification_templates ORDER BY event_type, template_key'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function templateKeyForOrderStatus(string $status): ?string
    {
        $map = [
            'confirmed' => 'order.confirmed.push',
            'processing' => 'order.processing.push',
            'dispatched' => 'order.dispatched.push',
            'out_for_delivery' => 'order.out_for_delivery.push',
            'delivered' => 'order.delivered.push',
            'cancelled' => 'order.cancelled.push',
        ];

        return $map[$status] ?? 'order.processing.push';
    }
}
