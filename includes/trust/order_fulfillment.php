<?php

declare(strict_types=1);

final class OrderFulfillmentService
{
    /** Order is eligible for verified reviews / post-delivery refunds when physically delivered. */
    public static function isOrderDelivered(int $orderId): bool
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return false;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $status = (string) ($stmt->fetchColumn() ?: '');
        if ($status === OrderStatus::DELIVERED || $status === OrderStatus::PARTIALLY_REFUNDED) {
            return true;
        }
        $d = $pdo->prepare(
            "SELECT COUNT(*) FROM deliveries WHERE order_id = ? AND status = 'delivered'"
        );
        $d->execute([$orderId]);

        return (int) $d->fetchColumn() > 0;
    }

    /** @return array<string, mixed>|null */
    public static function orderRowForCustomer(string $orderNumber, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
