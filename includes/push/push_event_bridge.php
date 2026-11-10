<?php

declare(strict_types=1);

/**
 * Event-driven push hooks — failures must not affect checkout/payment.
 */
final class PushEventBridge
{
    public static function orderPaymentSuccess(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            if (!OrderEmailContext::isOrderPaidAndCaptured($orderId)) {
                return;
            }
            $ctx = self::orderContext($orderId);
            if ($ctx === null) {
                return;
            }
            $customerId = (int) $ctx['customer_id'];
            $vars = [
                'customer_name' => (string) $ctx['customer_name'],
                'order_number' => (string) $ctx['order_number'],
                'order_total' => (string) $ctx['order_total'],
                'order_id' => (string) $orderId,
            ];
            PushNotificationService::sendToCustomer(
                $customerId,
                'order.confirmed.push',
                $vars,
                'push:payment.success:order:' . $orderId,
                'payment.success',
                ['order_id' => (string) $orderId]
            );
        } catch (Throwable $e) {
            error_log('PushEventBridge::orderPaymentSuccess');
        }
    }

    public static function orderStatusChanged(int $orderId, string $fromStatus, string $toStatus): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            $fromStatus = OrderEmailContext::normalizeOrderStatus($fromStatus);
            $toStatus = OrderEmailContext::normalizeOrderStatus($toStatus);
            if (!OrderEmailContext::shouldNotifyStatusTransition($fromStatus, $toStatus)) {
                return;
            }
            if ($toStatus === 'confirmed') {
                return;
            }
            $ctx = self::orderContext($orderId);
            if ($ctx === null) {
                return;
            }
            $templateKey = PushTemplateRepository::templateKeyForOrderStatus($toStatus);
            $customerId = (int) $ctx['customer_id'];
            $vars = [
                'customer_name' => (string) $ctx['customer_name'],
                'order_number' => (string) $ctx['order_number'],
                'order_total' => (string) $ctx['order_total'],
                'order_id' => (string) $orderId,
                'status_label' => ucwords(str_replace('_', ' ', $toStatus)),
            ];
            PushNotificationService::sendToCustomer(
                $customerId,
                $templateKey,
                $vars,
                'push:order.status:order:' . $orderId . ':' . $toStatus,
                'order.status',
                ['order_id' => (string) $orderId, 'status' => $toStatus]
            );
        } catch (Throwable $e) {
            error_log('PushEventBridge::orderStatusChanged');
        }
    }

    public static function subscriptionActivated(int $subscriptionId): void
    {
        if ($subscriptionId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT cs.id, cs.customer_id, c.name
                 FROM customer_subscriptions cs
                 INNER JOIN customers c ON c.id = cs.customer_id
                 WHERE cs.id = ? AND cs.status = ? LIMIT 1'
            );
            $stmt->execute([$subscriptionId, 'active']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $customerId = (int) $row['customer_id'];
            PushNotificationService::sendToCustomer(
                $customerId,
                'subscription.activated.push',
                [
                    'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
                ],
                'push:subscription.activated:' . $subscriptionId,
                'subscription.activated',
                ['subscription_id' => (string) $subscriptionId]
            );
        } catch (Throwable $e) {
            error_log('PushEventBridge::subscriptionActivated');
        }
    }

    public static function subscriptionPaused(int $subscriptionId): void
    {
        self::subscriptionLifecycleNotify($subscriptionId, 'subscription.paused.push', 'subscription.paused', 'push:subscription.paused:');
    }

    public static function subscriptionResumed(int $subscriptionId): void
    {
        self::subscriptionLifecycleNotify($subscriptionId, 'subscription.resumed.push', 'subscription.resumed', 'push:subscription.resumed:');
    }

    private static function subscriptionLifecycleNotify(
        int $subscriptionId,
        string $templateKey,
        string $eventType,
        string $idempotencyPrefix
    ): void {
        if ($subscriptionId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT cs.id, cs.customer_id, c.name FROM customer_subscriptions cs
                 INNER JOIN customers c ON c.id = cs.customer_id WHERE cs.id = ? LIMIT 1'
            );
            $stmt->execute([$subscriptionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            PushNotificationService::sendToCustomer(
                (int) $row['customer_id'],
                $templateKey,
                [
                    'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
                ],
                $idempotencyPrefix . $subscriptionId,
                $eventType,
                ['subscription_id' => (string) $subscriptionId]
            );
        } catch (Throwable $e) {
            error_log('PushEventBridge::subscriptionLifecycleNotify');
        }
    }

    public static function refundStatusUpdated(int $requestId, string $newStatus): void
    {
        if ($requestId <= 0 || !PushSchema::ready()) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT rr.request_number, rr.status, c.id AS customer_id, c.name
                 FROM refund_requests rr
                 INNER JOIN customers c ON c.id = rr.customer_id
                 WHERE rr.id = ? LIMIT 1'
            );
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $customerId = (int) $row['customer_id'];
            PushNotificationService::sendToCustomer(
                $customerId,
                'refund.status.push',
                [
                    'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
                    'request_number' => (string) $row['request_number'],
                    'status_label' => RefundRequestService::publicStatusLabel($newStatus),
                ],
                'push:refund.status:' . $requestId . ':' . $newStatus,
                'refund.status_updated',
                ['refund_request_id' => (string) $requestId]
            );
        } catch (Throwable $e) {
            error_log('PushEventBridge::refundStatusUpdated');
        }
    }

    /**
     * @return array{customer_id: int, customer_name: string, order_number: string, order_total: string}|null
     */
    private static function orderContext(int $orderId): ?array
    {
        if (!Install::isSchemaReady() || $orderId <= 0) {
            return null;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT o.order_number, o.grand_total, c.id AS customer_id, c.name
                 FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.id = ? LIMIT 1'
            );
            $stmt->execute([$orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return [
                'customer_id' => (int) $row['customer_id'],
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
                'order_number' => (string) $row['order_number'],
                'order_total' => number_format((float) $row['grand_total'], 2, '.', ''),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}
