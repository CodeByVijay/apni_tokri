<?php

declare(strict_types=1);

final class SubscriptionRestockService
{
    /**
     * Restore inventory once when a subscription fulfilment order is cancelled/failed before delivery.
     */
    public static function restockOrderOnce(int $orderId, string $reason): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            return (bool) Database::transaction(static function () use ($orderId, $reason): bool {
                $pdo = Database::pdo();
                $ord = $pdo->prepare(
                    'SELECT id, order_source, subscription_delivery_day_id FROM orders WHERE id = ? FOR UPDATE'
                );
                $ord->execute([$orderId]);
                $order = $ord->fetch(PDO::FETCH_ASSOC);
                if (!$order || (string) ($order['order_source'] ?? '') !== 'subscription') {
                    return false;
                }
                $idempotency = 'restock_order_' . $orderId;
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO subscription_stock_restock_events (order_id, idempotency_key, reason) VALUES (?, ?, ?)'
                );
                $ins->execute([$orderId, $idempotency, $reason]);
                if ($ins->rowCount() === 0) {
                    return true;
                }
                $items = $pdo->prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = ?');
                $items->execute([$orderId]);
                foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $vid = (int) $it['variant_id'];
                    $qty = (int) $it['quantity'];
                    if ($vid > 0 && $qty > 0) {
                        InventoryService::adjust($vid, $qty, $reason . ' (order ' . $orderId . ')');
                    }
                }
                $dayId = (int) ($order['subscription_delivery_day_id'] ?? 0);
                if ($dayId > 0) {
                    SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                }

                return true;
            });
        } catch (Throwable $e) {
            error_log('Subscription restock: ' . $e->getMessage());

            return false;
        }
    }
}
