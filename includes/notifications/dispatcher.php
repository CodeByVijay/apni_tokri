<?php

declare(strict_types=1);

/**
 * Event → queue mapping (Stage 14.4).
 *
 * Intentionally NOT queued (preserve existing behaviour):
 * - customer.login.otp (synchronous SMS via OtpSmsService)
 * - delivery.* SMS (DeliverySmsService + D3 locks)
 * - subscription/delivery in-app (SubscriptionNotificationDedupeService)
 * - direct CustomerNotificationService calls in subscription lifecycle
 */
final class NotificationDispatcher
{
    public static function orderPaymentSuccess(int $orderId): void
    {
        if (!NotificationQueueService::tableReady() || $orderId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT o.order_number, o.grand_total, c.email, c.name, c.id AS customer_id
                 FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.id = ? LIMIT 1'
            );
            $stmt->execute([$orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !Validation::email($email)) {
                return;
            }
            $payload = [
                'order_number' => (string) $row['order_number'],
                'amount' => number_format((float) $row['grand_total'], 2, '.', ''),
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            ];
            NotificationQueueService::enqueue(
                'payment.success',
                'email',
                $email,
                'customer:' . (int) $row['customer_id'],
                'payment.success.email',
                $payload,
                'payment.success:order:' . $orderId . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPaymentSuccess');
        }
    }

    public static function supportTicketCreated(int $ticketId): void
    {
        if (!NotificationQueueService::tableReady() || $ticketId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT t.ticket_number, t.customer_id, c.email, c.name
                 FROM support_tickets t INNER JOIN customers c ON c.id = t.customer_id WHERE t.id = ? LIMIT 1'
            );
            $stmt->execute([$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $payload = [
                'ticket_number' => (string) $row['ticket_number'],
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            ];
            $customerId = (int) $row['customer_id'];
            NotificationQueueService::enqueue(
                'support.ticket_created',
                'in_app',
                'customer:' . $customerId,
                'customer:' . $customerId,
                'support.ticket_created.in_app',
                $payload,
                'support.ticket_created:' . $ticketId . ':in_app'
            );
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && Validation::email($email)) {
                NotificationQueueService::enqueue(
                    'support.ticket_created',
                    'email',
                    $email,
                    'customer:' . $customerId,
                    'support.ticket_created.email',
                    $payload,
                    'support.ticket_created:' . $ticketId . ':email'
                );
            }
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::supportTicketCreated');
        }
    }

    public static function supportTicketReplied(int $ticketId): void
    {
        if (!NotificationQueueService::tableReady() || $ticketId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT t.ticket_number, t.customer_id, c.email, c.name
                 FROM support_tickets t INNER JOIN customers c ON c.id = t.customer_id WHERE t.id = ? LIMIT 1'
            );
            $stmt->execute([$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !Validation::email($email)) {
                return;
            }
            $payload = [
                'ticket_number' => (string) $row['ticket_number'],
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            ];
            NotificationQueueService::enqueue(
                'support.ticket_replied',
                'email',
                $email,
                'customer:' . (int) $row['customer_id'],
                'support.ticket_replied.email',
                $payload,
                'support.ticket_replied:' . $ticketId . ':email:' . date('Y-m-d')
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::supportTicketReplied');
        }
    }

    public static function orderPaymentFailed(string $orderNumber): void
    {
        if (!NotificationQueueService::tableReady() || trim($orderNumber) === '') {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT o.order_number, o.grand_total, c.email, c.name, c.id AS customer_id
                 FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.order_number = ? LIMIT 1'
            );
            $stmt->execute([trim($orderNumber)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !Validation::email($email)) {
                return;
            }
            $payload = [
                'order_number' => (string) $row['order_number'],
                'amount' => number_format((float) $row['grand_total'], 2, '.', ''),
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            ];
            NotificationQueueService::enqueue(
                'payment.failed',
                'email',
                $email,
                'customer:' . (int) $row['customer_id'],
                'payment.failed.email',
                $payload,
                'payment.failed:order:' . (string) $row['order_number'] . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPaymentFailed');
        }
    }

    public static function refundStatusUpdated(int $requestId, string $newStatus): void
    {
        if (!NotificationQueueService::tableReady() || $requestId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT rr.request_number, rr.status, c.email, c.name, c.id AS customer_id
                 FROM refund_requests rr INNER JOIN customers c ON c.id = rr.customer_id WHERE rr.id = ? LIMIT 1'
            );
            $stmt->execute([$requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !Validation::email($email)) {
                return;
            }
            $payload = [
                'request_number' => (string) $row['request_number'],
                'status_label' => RefundRequestService::publicStatusLabel($newStatus),
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            ];
            NotificationQueueService::enqueue(
                'refund.status_updated',
                'email',
                $email,
                'customer:' . (int) $row['customer_id'],
                'refund.status_updated.email',
                $payload,
                'refund.status_updated:' . $requestId . ':' . $newStatus . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::refundStatusUpdated');
        }
    }
}
