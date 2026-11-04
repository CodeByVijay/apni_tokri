<?php

declare(strict_types=1);

/**
 * Stage 11 integration hook: delivery completion consumes subscription entitlement once.
 */
final class SubscriptionEntitlementService
{
    public static function onDeliveryMarkedDelivered(int $deliveryId): void
    {
        if (!Install::isSchemaReady()) {
            return;
        }
        try {
            Database::transaction(static function () use ($deliveryId): void {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT d.id AS delivery_id, d.order_id, o.subscription_delivery_day_id, o.order_source
                     FROM deliveries d
                     INNER JOIN orders o ON o.id = d.order_id
                     WHERE d.id = ? FOR UPDATE'
                );
                $stmt->execute([$deliveryId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || (string) ($row['order_source'] ?? '') !== 'subscription') {
                    return;
                }
                $dayId = (int) ($row['subscription_delivery_day_id'] ?? 0);
                if ($dayId <= 0) {
                    return;
                }
                $idempotency = 'entitlement_delivery_' . $deliveryId;
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO subscription_delivery_entitlement_events
                     (subscription_delivery_day_id, delivery_id, idempotency_key) VALUES (?, ?, ?)'
                );
                $ins->execute([$dayId, $deliveryId, $idempotency]);
                if ($ins->rowCount() === 0) {
                    return;
                }
                $dayStmt = $pdo->prepare(
                    'SELECT customer_subscription_id, status FROM subscription_delivery_days WHERE id = ? FOR UPDATE'
                );
                $dayStmt->execute([$dayId]);
                $day = $dayStmt->fetch(PDO::FETCH_ASSOC);
                if (!$day) {
                    return;
                }
                $subId = (int) $day['customer_subscription_id'];
                $pdo->prepare(
                    'UPDATE customer_subscriptions SET deliveries_remaining = GREATEST(0, deliveries_remaining - 1), updated_at = NOW()
                     WHERE id = ? AND deliveries_remaining > 0'
                )->execute([$subId]);
                $pdo->prepare(
                    "UPDATE subscription_delivery_days SET status = 'delivered', fulfilment_status = 'delivered', updated_at = NOW() WHERE id = ?"
                )->execute([$dayId]);
                SubscriptionAudit::log(
                    $subId,
                    null,
                    'system',
                    null,
                    'delivery_entitlement_consumed',
                    ['delivery_id' => $deliveryId, 'day_id' => $dayId]
                );
                SubscriptionCreditScheduleService::maybeCompleteSubscription($subId, $pdo);
            });
        } catch (Throwable $e) {
            error_log('Subscription entitlement: ' . $e->getMessage());
        }
    }

    /** Restore entitlement if a delivered subscription delivery is reversed (failed/cancelled after mark). */
    public static function onDeliveryReversed(int $deliveryId, string $newStatus): void
    {
        if (!in_array($newStatus, ['failed', 'cancelled'], true) || !Install::isSchemaReady()) {
            return;
        }
        try {
            Database::transaction(static function () use ($deliveryId): void {
                $pdo = Database::pdo();
                $ev = $pdo->prepare(
                    'SELECT e.*, o.subscription_delivery_day_id
                     FROM subscription_delivery_entitlement_events e
                     INNER JOIN deliveries d ON d.id = e.delivery_id
                     INNER JOIN orders o ON o.id = d.order_id
                     WHERE e.delivery_id = ? FOR UPDATE'
                );
                $ev->execute([$deliveryId]);
                $row = $ev->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return;
                }
                $dayId = (int) $row['subscription_delivery_day_id'];
                $subStmt = $pdo->prepare(
                    'SELECT customer_subscription_id FROM subscription_delivery_days WHERE id = ? FOR UPDATE'
                );
                $subStmt->execute([$dayId]);
                $day = $subStmt->fetch(PDO::FETCH_ASSOC);
                if (!$day) {
                    return;
                }
                $subId = (int) $day['customer_subscription_id'];
                $del = $pdo->prepare('DELETE FROM subscription_delivery_entitlement_events WHERE delivery_id = ?');
                $del->execute([$deliveryId]);
                if ($del->rowCount() === 0) {
                    return;
                }
                $pdo->prepare(
                    "UPDATE customer_subscriptions SET deliveries_remaining = deliveries_remaining + 1,
                     status = IF(status = 'completed', 'active', status), updated_at = NOW() WHERE id = ?"
                )->execute([$subId]);
                $pdo->prepare(
                    "UPDATE subscription_delivery_days SET status = 'scheduled', fulfilment_status = 'generated', updated_at = NOW() WHERE id = ?"
                )->execute([$dayId]);
                SubscriptionAudit::log($subId, null, 'system', null, 'delivery_entitlement_restored', ['delivery_id' => $deliveryId]);
                SubscriptionCreditScheduleService::ensureScheduleCoversRemainingCredits($subId, $pdo);
            });
        } catch (Throwable $e) {
            error_log('Subscription entitlement restore: ' . $e->getMessage());
        }
    }
}
