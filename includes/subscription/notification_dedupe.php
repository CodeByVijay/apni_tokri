<?php

declare(strict_types=1);

final class SubscriptionNotificationDedupeService
{
    public static function notifyOnce(int $customerId, string $type, string $referenceKey, string $title, string $body, array $context = []): bool
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO subscription_notification_log (customer_id, notification_type, reference_key)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$customerId, $type, $referenceKey]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        CustomerNotificationService::notify($customerId, $title, $body, $context);

        return true;
    }
}
