<?php

declare(strict_types=1);

/**
 * Event → queue mapping (Stage 14.4 + customer order/payment emails).
 *
 * Intentionally NOT queued (preserve existing behaviour):
 * - customer.login.otp (synchronous SMS via OtpSmsService)
 * - delivery.* SMS (DeliverySmsService + D3 locks)
 * - subscription/delivery in-app (SubscriptionNotificationDedupeService)
 * - direct CustomerNotificationService calls in subscription lifecycle
 */
final class NotificationDispatcher
{
    public static function orderPlacedPendingPayment(int $orderId): void
    {
        if (!NotificationQueueService::tableReady() || $orderId <= 0) {
            return;
        }
        if (!OrderEmailContext::shouldSendPendingPaymentEmail($orderId)) {
            return;
        }
        try {
            $recipient = OrderEmailContext::resolveRecipientForOrderId($orderId);
            if ($recipient === null) {
                return;
            }
            $payload = OrderEmailContext::enrichOrderPayload(
                $orderId,
                [
                    'order_number' => $recipient['order_number'],
                    'amount' => $recipient['amount'],
                    'customer_name' => $recipient['customer_name'],
                ],
                OrderEmailContext::latestPaymentForOrder($orderId)
            );
            NotificationQueueService::enqueue(
                'order.placed',
                'email',
                $recipient['email'],
                $recipient['recipient_ref'],
                'order.placed.email',
                $payload,
                'order.placed:order:' . $orderId . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPlacedPendingPayment');
        }
    }

    public static function orderPaymentSuccess(int $orderId): void
    {
        if (!NotificationQueueService::tableReady() || $orderId <= 0) {
            return;
        }
        if (!OrderEmailContext::isOrderPaidAndCaptured($orderId)) {
            return;
        }
        try {
            PushEventBridge::orderPaymentSuccess($orderId);
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPaymentSuccess push');
        }
        NotificationQueueService::cancelPendingByIdempotencyKey(
            'order.placed:order:' . $orderId . ':email',
            'payment_captured'
        );
        $idempotencyKey = 'payment.success:order:' . $orderId . ':email';
        try {
            $recipient = OrderEmailContext::resolveRecipientForOrderId($orderId);
            if ($recipient === null) {
                if (DeliveryOrderEmailService::tableReady()) {
                    DeliveryOrderEmailService::recordOrderConfirmationAttempt(
                        $orderId,
                        'skipped',
                        $idempotencyKey,
                        [],
                        true,
                        'No valid customer email'
                    );
                }

                return;
            }
            $payment = OrderEmailContext::capturedPaymentForOrder($orderId);
            if ($payment === null) {
                return;
            }
            $payload = OrderEmailContext::enrichOrderPayload(
                $orderId,
                [
                    'order_number' => $recipient['order_number'],
                    'amount' => $recipient['amount'],
                    'customer_name' => $recipient['customer_name'],
                ],
                $payment
            );
            $enq = NotificationQueueService::enqueue(
                'payment.success',
                'email',
                $recipient['email'],
                $recipient['recipient_ref'],
                'payment.success.email',
                $payload,
                $idempotencyKey
            );
            $send = ['ok' => false];
            if (!empty($enq['ok']) && !empty($enq['created'])) {
                $send = NotificationQueueService::claimAndProcessByIdempotencyKey('email', $idempotencyKey);
            } elseif (!empty($enq['duplicate'])) {
                $send = ['ok' => true, 'duplicate' => true];
            }
            if (DeliveryOrderEmailService::tableReady()) {
                DeliveryOrderEmailService::recordOrderConfirmationAttempt(
                    $orderId,
                    $recipient['email'],
                    $idempotencyKey,
                    $send
                );
            }
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPaymentSuccess');
        }
    }

    public static function resendOrderConfirmationEmail(int $orderId, int $retryLogId): bool
    {
        if (!NotificationQueueService::tableReady() || $orderId <= 0 || !OrderEmailContext::isOrderPaidAndCaptured($orderId)) {
            return false;
        }
        $recipient = OrderEmailContext::resolveRecipientForOrderId($orderId);
        if ($recipient === null) {
            return false;
        }
        $payment = OrderEmailContext::capturedPaymentForOrder($orderId);
        if ($payment === null) {
            return false;
        }
        $idempotencyKey = 'payment.success:order:' . $orderId . ':email:retry:' . $retryLogId;
        try {
            $payload = OrderEmailContext::enrichOrderPayload(
                $orderId,
                [
                    'order_number' => $recipient['order_number'],
                    'amount' => $recipient['amount'],
                    'customer_name' => $recipient['customer_name'],
                ],
                $payment
            );
            $enq = NotificationQueueService::enqueue(
                'payment.success',
                'email',
                $recipient['email'],
                $recipient['recipient_ref'],
                'payment.success.email',
                $payload,
                $idempotencyKey
            );
            if (empty($enq['ok'])) {
                return false;
            }
            $send = NotificationQueueService::claimAndProcessByIdempotencyKey('email', $idempotencyKey);
            if (DeliveryOrderEmailService::tableReady()) {
                DeliveryOrderEmailService::recordOrderConfirmationAttempt(
                    $orderId,
                    $recipient['email'],
                    $idempotencyKey,
                    $send
                );
            }

            return !empty($send['ok']);
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::resendOrderConfirmationEmail');

            return false;
        }
    }

    public static function orderStatusChanged(int $orderId, string $fromStatus, string $toStatus): void
    {
        if (!NotificationQueueService::tableReady() || $orderId <= 0) {
            return;
        }
        $fromStatus = OrderEmailContext::normalizeOrderStatus($fromStatus);
        $toStatus = OrderEmailContext::normalizeOrderStatus($toStatus);
        if (!OrderEmailContext::shouldNotifyStatusTransition($fromStatus, $toStatus)) {
            return;
        }
        try {
            PushEventBridge::orderStatusChanged($orderId, $fromStatus, $toStatus);
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderStatusChanged push');
        }
        $templateKey = 'order.status.email';
        if (!NotificationTemplateRepository::ensureActiveEmailTemplate($templateKey)) {
            error_log('NotificationDispatcher::orderStatusChanged missing template ' . $templateKey);

            return;
        }
        try {
            $recipient = OrderEmailContext::resolveRecipientForOrderId($orderId);
            if ($recipient === null) {
                error_log('NotificationDispatcher::orderStatusChanged no valid recipient for order_id=' . $orderId);

                return;
            }
            $payload = OrderEmailContext::enrichOrderPayload(
                $orderId,
                [
                    'order_number' => $recipient['order_number'],
                    'amount' => $recipient['amount'],
                    'customer_name' => $recipient['customer_name'],
                    'status_label' => ucwords(str_replace('_', ' ', $toStatus)),
                    'status_from' => ucwords(str_replace('_', ' ', $fromStatus)),
                ],
                OrderEmailContext::latestPaymentForOrder($orderId)
            );
            $idempotencyKey = 'order.status:order:' . $orderId . ':' . $toStatus . ':email';
            $enq = NotificationQueueService::enqueue(
                'order.status',
                'email',
                $recipient['email'],
                $recipient['recipient_ref'],
                $templateKey,
                $payload,
                $idempotencyKey
            );
            if (empty($enq['ok'])) {
                error_log('NotificationDispatcher::orderStatusChanged enqueue failed order_id=' . $orderId);

                return;
            }
            if (!empty($enq['created'])) {
                $send = NotificationQueueService::claimAndProcessByIdempotencyKey('email', $idempotencyKey);
                if (empty($send['ok'])) {
                    error_log(
                        'NotificationDispatcher::orderStatusChanged delivery not completed order_id='
                        . $orderId . ' error=' . (string) ($send['error'] ?? 'unknown')
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderStatusChanged');
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
            $recipient = OrderEmailContext::resolveRecipientForOrderNumber($orderNumber);
            if ($recipient === null) {
                return;
            }
            $orderId = (int) ($recipient['order_id'] ?? 0);
            $payload = OrderEmailContext::enrichOrderPayload(
                $orderId,
                [
                    'order_number' => $recipient['order_number'],
                    'amount' => $recipient['amount'],
                    'customer_name' => $recipient['customer_name'],
                ],
                OrderEmailContext::latestPaymentForOrder($orderId)
            );
            NotificationQueueService::enqueue(
                'payment.failed',
                'email',
                $recipient['email'],
                $recipient['recipient_ref'],
                'payment.failed.email',
                $payload,
                'payment.failed:order:' . (string) $recipient['order_number'] . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPaymentFailed');
        }
    }

    public static function refundCompletedForRequest(int $requestId): void
    {
        if (!NotificationQueueService::tableReady() || $requestId <= 0) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT rr.request_number, rr.approved_amount_inr, rr.updated_at,
                        o.order_number, o.id AS order_id, c.email, c.name, c.id AS customer_id,
                        pr.gateway, pr.gateway_refund_id, pr.idempotency_key, pr.amount_inr, pr.completed_at
                 FROM refund_requests rr
                 INNER JOIN customers c ON c.id = rr.customer_id
                 INNER JOIN orders o ON o.id = rr.order_id
                 LEFT JOIN payment_refunds pr ON pr.refund_request_id = rr.id AND pr.id = (
                     SELECT id FROM payment_refunds WHERE refund_request_id = rr.id ORDER BY id DESC LIMIT 1
                 )
                 WHERE rr.id = ? AND rr.status = ? LIMIT 1'
            );
            $stmt->execute([$requestId, RefundStatus::REFUNDED]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $email = trim((string) ($row['email'] ?? ''));
            if ($email === '' || !Validation::email($email)) {
                return;
            }
            $amountInr = (float) ($row['amount_inr'] ?? $row['approved_amount_inr'] ?? 0);
            $amount = $amountInr > 0 ? number_format($amountInr, 2, '.', '') : '';
            $amountLine = $amount !== '' ? '₹' . $amount : '';
            $gateway = (string) ($row['gateway'] ?? '');
            $gatewayName = $gateway === 'cashfree' ? 'Cashfree' : ($gateway === 'razorpay' ? 'Razorpay' : ucfirst($gateway));
            $refRef = trim((string) ($row['gateway_refund_id'] ?? ''));
            if ($refRef === '' && $gateway === 'cashfree') {
                $refRef = CashfreeGateway::merchantRefundIdFromIdempotency((string) ($row['idempotency_key'] ?? ''));
            }
            if ($refRef === '') {
                $refRef = (string) ($row['request_number'] ?? '');
            }
            $completedAt = trim((string) ($row['completed_at'] ?? $row['updated_at'] ?? ''));
            $completionDate = $completedAt !== '' ? customer_format_datetime($completedAt) : date('d M Y, h:i A');
            $orderUrl = SeoUrl::storefrontAbsolute('/order-details.php?id=' . rawurlencode((string) $row['order_number']));
            $payload = [
                'request_number' => (string) $row['request_number'],
                'order_number' => (string) $row['order_number'],
                'amount' => $amount,
                'amount_line' => $amountLine,
                'status_label' => RefundRequestService::publicStatusLabel(RefundStatus::REFUNDED),
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
                'gateway_name' => $gatewayName,
                'refund_reference' => $refRef,
                'completion_date' => $completionDate,
                'order_url' => $orderUrl,
                'event_date' => $completionDate,
            ];
            $idempotencyKey = 'refund.completed:' . $requestId . ':email';
            $enq = NotificationQueueService::enqueue(
                'refund.completed',
                'email',
                $email,
                'customer:' . (int) $row['customer_id'],
                'refund.completed.email',
                $payload,
                $idempotencyKey
            );
            if (!empty($enq['ok']) && !empty($enq['created'])) {
                NotificationQueueService::claimAndProcessByIdempotencyKey('email', $idempotencyKey);
            }
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::refundCompletedForRequest');
        }
    }

    public static function refundStatusUpdated(int $requestId, string $newStatus): void
    {
        if ($requestId <= 0) {
            return;
        }
        try {
            PushEventBridge::refundStatusUpdated($requestId, $newStatus);
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::refundStatusUpdated push');
        }
        if (!NotificationQueueService::tableReady()) {
            return;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT rr.request_number, rr.status, rr.approved_amount_inr, o.order_number, c.email, c.name, c.id AS customer_id
                 FROM refund_requests rr
                 INNER JOIN customers c ON c.id = rr.customer_id
                 INNER JOIN orders o ON o.id = rr.order_id
                 WHERE rr.id = ? LIMIT 1'
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
            $amount = $row['approved_amount_inr'] !== null
                ? number_format((float) $row['approved_amount_inr'], 2, '.', '')
                : '';
            $amountLine = $amount !== '' ? ' of ₹' . $amount : '';
            $payload = [
                'request_number' => (string) $row['request_number'],
                'order_number' => (string) $row['order_number'],
                'amount' => $amount,
                'amount_line' => $amountLine,
                'status_label' => RefundRequestService::publicStatusLabel($newStatus),
                'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
                'event_date' => date('d M Y, h:i A'),
            ];
            $recipientRef = 'customer:' . (int) $row['customer_id'];

            if ($newStatus === RefundStatus::REFUND_INITIATED) {
                NotificationQueueService::enqueue(
                    'refund.initiated',
                    'email',
                    $email,
                    $recipientRef,
                    'refund.initiated.email',
                    $payload,
                    'refund.initiated:' . $requestId . ':email'
                );

                return;
            }
            if ($newStatus === RefundStatus::REFUNDED) {
                self::refundCompletedForRequest($requestId);

                return;
            }

            NotificationQueueService::enqueue(
                'refund.status_updated',
                'email',
                $email,
                $recipientRef,
                'refund.status_updated.email',
                $payload,
                'refund.status_updated:' . $requestId . ':' . $newStatus . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::refundStatusUpdated');
        }
    }

    public static function orderPaymentTimeoutCancelled(int $orderId): void
    {
        if (!NotificationQueueService::tableReady() || $orderId <= 0) {
            return;
        }
        try {
            $recipient = OrderEmailContext::resolveRecipientForOrderId($orderId);
            if ($recipient === null) {
                return;
            }
            $payload = OrderEmailContext::enrichOrderPayload(
                $orderId,
                [
                    'order_number' => $recipient['order_number'],
                    'amount' => $recipient['amount'],
                    'customer_name' => $recipient['customer_name'],
                    'cancellation_reason' => OrderCancellationService::reasonLabel(OrderCancellationService::REASON_PAYMENT_TIMEOUT),
                ],
                OrderEmailContext::latestPaymentForOrder($orderId)
            );
            NotificationQueueService::enqueue(
                'order.payment_timeout_cancelled',
                'email',
                $recipient['email'],
                $recipient['recipient_ref'],
                'order.payment_timeout_cancelled.email',
                $payload,
                'order.payment_timeout_cancelled:order:' . $orderId . ':email'
            );
        } catch (Throwable $e) {
            error_log('NotificationDispatcher::orderPaymentTimeoutCancelled');
        }
    }
}
