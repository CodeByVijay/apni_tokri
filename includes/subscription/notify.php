<?php

declare(strict_types=1);

final class CustomerNotificationService
{
    /** @param array<string, mixed> $context */
    public static function notify(int $customerId, string $title, string $body, array $context = []): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'INSERT INTO customer_notifications (customer_id, title, body, context_json) VALUES (?, ?, ?, ?)'
        )->execute([
            $customerId,
            $title,
            $body,
            $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function unreadForCustomer(int $customerId, int $limit = 20): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM customer_notifications WHERE customer_id = ? AND read_at IS NULL
             ORDER BY created_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
