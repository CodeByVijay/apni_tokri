<?php

declare(strict_types=1);

/**
 * Central order cancellation (customer eligibility; payment-timeout system path).
 */
final class OrderCancellationService
{
    /**
     * Customer storefront: cancel only while the order is still before kitchen prep.
     * Canonical DB values (OrderStatus), not display labels.
     *
     * @var list<string>
     */
    private const CUSTOMER_CANCELABLE_STATUSES = [
        OrderStatus::PENDING_PAYMENT,
        OrderStatus::PAYMENT_FAILED,
        OrderStatus::CONFIRMED,
        OrderStatus::ACCEPTED,
        OrderStatus::DELIVERY_EXCEPTION,
    ];

    /** @var list<string> */
    private const CUSTOMER_NO_CANCEL_STATUSES = [
        OrderStatus::PREPARING,
        OrderStatus::READY,
        OrderStatus::OUT_FOR_DELIVERY,
        OrderStatus::DELIVERED,
        OrderStatus::REFUNDED,
        OrderStatus::PARTIALLY_REFUNDED,
    ];

    /** @var array<string, string> */
    public const CUSTOMER_REASONS = [
        'ordered_by_mistake' => 'Ordered by mistake',
        'wrong_product_quantity' => 'Ordered wrong product or quantity',
        'no_longer_needed' => 'No longer needed',
        'delivery_too_slow' => 'Delivery taking too long',
        'other' => 'Other',
    ];

    public const REASON_PAYMENT_TIMEOUT = 'payment_timeout';

    public const SYSTEM_REASONS = [
        self::REASON_PAYMENT_TIMEOUT => 'Payment not completed within the allowed time',
    ];

    public static function reasonLabel(string $code): string
    {
        return self::CUSTOMER_REASONS[$code]
            ?? self::SYSTEM_REASONS[$code]
            ?? $code;
    }

    public static function eventsTableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'order_cancellation_events'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function restockTableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $n = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'order_stock_restock_events'"
            )->fetchColumn();

            return $n > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $orderRow orders.* row
     * @param array<string, mixed>|null $deliveryRow deliveries.* or null
     * @return array{eligible: bool, code?: string, message?: string}
     */
    public static function eligibility(array $orderRow, ?array $deliveryRow = null): array
    {
        $status = (string) ($orderRow['status'] ?? '');
        if ($status === OrderStatus::CANCELLED) {
            return ['eligible' => false, 'code' => 'already_cancelled', 'message' => 'This order is already cancelled.'];
        }

        if (in_array($status, self::CUSTOMER_NO_CANCEL_STATUSES, true)) {
            $message = $status === OrderStatus::PREPARING
                ? 'This order is being prepared and can no longer be cancelled.'
                : 'This order can no longer be cancelled.';
            $code = $status === OrderStatus::PREPARING ? 'preparing_started' : 'status_locked';

            return ['eligible' => false, 'code' => $code, 'message' => $message];
        }

        $delStatus = $deliveryRow !== null ? (string) ($deliveryRow['status'] ?? '') : '';
        if ($delStatus !== '') {
            if (in_array($delStatus, [DeliveryStatus::READY, DeliveryStatus::DISPATCHED, DeliveryStatus::DELIVERED], true)) {
                return ['eligible' => false, 'code' => 'fulfilment_started', 'message' => 'Packing or delivery has already started.'];
            }
        }

        if (!in_array($status, self::CUSTOMER_CANCELABLE_STATUSES, true)) {
            return ['eligible' => false, 'code' => 'status_locked', 'message' => 'This order cannot be cancelled.'];
        }

        return ['eligible' => true];
    }

    /** @return array{eligible: bool, message?: string, reasons?: array<string, string>} */
    public static function customerCancelState(int $customerId, string $orderNumber): array
    {
        $order = OrderFulfillmentService::orderRowForCustomer($orderNumber, $customerId);
        if ($order === null) {
            return ['eligible' => false, 'message' => 'Order not found.'];
        }
        $delivery = self::deliveryRowForOrder((int) $order['id']);
        $elig = self::eligibility($order, $delivery);
        if (!$elig['eligible']) {
            return ['eligible' => false, 'message' => $elig['message'] ?? 'Not eligible.'];
        }

        return ['eligible' => true, 'reasons' => self::CUSTOMER_REASONS];
    }

    /**
     * Automatic expiry cancellation (cron); idempotent per order.
     *
     * @return array{ok: bool, message?: string, idempotent?: bool, order_number?: string}
     */
    public static function cancelForPaymentTimeout(int $orderId): array
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        return self::applyCancellation(
            $order,
            isset($order['customer_id']) ? (int) $order['customer_id'] : null,
            null,
            self::REASON_PAYMENT_TIMEOUT,
            '',
            'system',
            'payment_timeout:order:' . $orderId
        );
    }

    public static function cancelByCustomer(
        int $customerId,
        string $orderNumber,
        string $reasonCode,
        string $reasonDetail,
        string $idempotencyKey
    ): array {
        $labels = self::CUSTOMER_REASONS;
        if (!isset($labels[$reasonCode])) {
            return ['ok' => false, 'message' => 'Choose a valid cancellation reason.'];
        }
        $reasonDetail = trim($reasonDetail);
        if ($reasonCode === 'other' && $reasonDetail === '') {
            return ['ok' => false, 'message' => 'Please briefly describe why you are cancelling.'];
        }
        if (mb_strlen($reasonDetail) > 500) {
            return ['ok' => false, 'message' => 'Description must be 500 characters or fewer.'];
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return ['ok' => false, 'message' => 'Invalid request. Refresh and try again.'];
        }

        $order = OrderFulfillmentService::orderRowForCustomer($orderNumber, $customerId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        return self::applyCancellation(
            $order,
            $customerId,
            null,
            $reasonCode,
            $reasonDetail,
            'customer',
            $idempotencyKey
        );
    }

    /**
     * @param array<string, mixed> $orderRow
     * @return array{ok: bool, message?: string, idempotent?: bool, order_number?: string}
     */
    private static function applyCancellation(
        array $orderRow,
        ?int $customerId,
        ?int $adminUserId,
        string $reasonCode,
        string $reasonDetail,
        string $requestedBy,
        string $idempotencyKey
    ): array {
        $orderId = (int) $orderRow['id'];
        $orderNumber = (string) ($orderRow['order_number'] ?? '');

        try {
            $result = Database::transaction(static function () use (
                $orderRow,
                $orderId,
                $orderNumber,
                $customerId,
                $adminUserId,
                $reasonCode,
                $reasonDetail,
                $requestedBy,
                $idempotencyKey
            ): array {
                $pdo = Database::pdo();
                $lock = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $lock->execute([$orderId]);
                $fresh = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$fresh) {
                    return ['ok' => false, 'message' => 'Order not found.'];
                }
                if ((string) ($fresh['status'] ?? '') === OrderStatus::CANCELLED) {
                    return ['ok' => true, 'idempotent' => true, 'order_number' => $orderNumber, 'message' => 'Order already cancelled.'];
                }

                $delivery = self::deliveryRowForOrder($orderId, true);
                $elig = self::eligibility($fresh, $delivery);
                if (!$elig['eligible']) {
                    return ['ok' => false, 'message' => $elig['message'] ?? 'Order cannot be cancelled.'];
                }

                if (self::eventsTableReady()) {
                    $dup = $pdo->prepare('SELECT order_id FROM order_cancellation_events WHERE idempotency_key = ? LIMIT 1');
                    $dup->execute([$idempotencyKey]);
                    $dupOrder = (int) ($dup->fetchColumn() ?: 0);
                    if ($dupOrder > 0 && $dupOrder === $orderId) {
                        return ['ok' => true, 'idempotent' => true, 'order_number' => $orderNumber];
                    }
                    if ($dupOrder > 0 && $dupOrder !== $orderId) {
                        return ['ok' => false, 'message' => 'Duplicate cancellation request.'];
                    }
                }

                $statusBeforeCancel = (string) ($fresh['status'] ?? '');
                $humanReason = self::reasonLabel($reasonCode);
                $note = 'Cancelled by ' . $requestedBy . ': ' . $humanReason;
                if ($reasonDetail !== '') {
                    $note .= ' — ' . $reasonDetail;
                }

                $pdo->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([OrderStatus::CANCELLED, $orderId]);

                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
                )->execute([$orderId, OrderStatus::CANCELLED, $note, $adminUserId]);

                if (self::eventsTableReady()) {
                    $pdo->prepare(
                        'INSERT INTO order_cancellation_events
                         (order_id, reason_code, reason_detail, requested_by, customer_id, admin_user_id, idempotency_key)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $orderId,
                        $reasonCode,
                        $reasonDetail !== '' ? $reasonDetail : null,
                        $requestedBy,
                        $customerId,
                        $adminUserId,
                        $idempotencyKey,
                    ]);
                }

                if ($delivery !== null) {
                    $delId = (int) $delivery['id'];
                    $pdo->prepare(
                        "UPDATE deliveries SET status = ?, updated_at = NOW() WHERE id = ? AND status NOT IN ('delivered','cancelled')"
                    )->execute([DeliveryStatus::CANCELLED, $delId]);
                }

                DeliverySlotReservationService::releaseForOrder($orderId);

                $source = (string) ($fresh['order_source'] ?? '');
                if ($source === 'subscription') {
                    SubscriptionRestockService::restockOrderOnce($orderId, 'Customer order cancellation');
                    self::markSubscriptionDayCancelledForOrder($pdo, $orderId, $customerId);
                } else {
                    if (!self::restockStorefrontOrderOnce($orderId, 'Order cancelled')) {
                        throw new RuntimeException('Stock restoration failed for order ' . $orderId);
                    }
                }

                $refundRequestId = self::createRefundRequestIfPaidOnline($pdo, $fresh, $customerId, $humanReason, $reasonDetail);

                AuditLog::write('order.cancelled', 'orders', (string) $orderId, [
                    'by' => $requestedBy,
                    'reason_code' => $reasonCode,
                ]);

                return [
                    'ok' => true,
                    'order_number' => $orderNumber,
                    'message' => 'Your order has been cancelled.',
                    'status_from' => $statusBeforeCancel,
                    'refund_request_id' => $refundRequestId,
                ];
            });
        } catch (Throwable $e) {
            $result = null;
            error_log('OrderCancellationService: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not cancel order. Please try again or contact support.'];
        }

        if (!empty($result['ok']) && empty($result['idempotent']) && !empty($result['refund_request_id'])) {
            AdminNotificationService::notifyOrderCancellationRefundRequired((int) $result['refund_request_id']);
        }

        if (
            !empty($result['ok'])
            && empty($result['idempotent'])
            && !empty($result['status_from'])
            && $reasonCode !== self::REASON_PAYMENT_TIMEOUT
        ) {
            NotificationDispatcher::orderStatusChanged(
                $orderId,
                (string) $result['status_from'],
                OrderStatus::CANCELLED
            );
        }

        try {
            InvoiceService::syncForOrder($orderId);
        } catch (Throwable $e) {
            error_log('OrderCancellationService invoice sync: ' . $e->getMessage());
        }

        return is_array($result) ? $result : ['ok' => false, 'message' => 'Could not cancel order. Please try again or contact support.'];
    }

    private static function markSubscriptionDayCancelledForOrder(PDO $pdo, int $orderId, ?int $customerId): void
    {
        $dayId = (int) ($pdo->query(
            'SELECT subscription_delivery_day_id FROM orders WHERE id = ' . (int) $orderId
        )->fetchColumn() ?: 0);
        if ($dayId <= 0) {
            return;
        }
        $pdo->prepare(
            "UPDATE subscription_delivery_days SET status = 'cancelled', fulfilment_status = 'cancelled', updated_at = NOW()
             WHERE id = ? AND generated_order_id = ?"
        )->execute([$dayId, $orderId]);
        $subId = (int) ($pdo->query(
            'SELECT customer_subscription_id FROM subscription_delivery_days WHERE id = ' . (int) $dayId
        )->fetchColumn() ?: 0);
        if ($subId > 0) {
            $active = (string) ($pdo->query(
                'SELECT status FROM customer_subscriptions WHERE id = ' . (int) $subId
            )->fetchColumn() ?: '');
            // Never change subscription root status here — only the delivery day / order.
            if ($active !== '') {
                SubscriptionAudit::log($subId, null, 'customer', $customerId, 'order_cancelled_not_subscription', [
                    'order_id' => $orderId,
                    'day_id' => $dayId,
                ]);
            }
        }
    }

    public static function restockStorefrontOrderOnce(int $orderId, string $reason): bool
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return false;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $paid = (string) ($pdo->query(
            'SELECT payment_status FROM orders WHERE id = ' . (int) $orderId
        )->fetchColumn() ?: '');
        if ($paid !== 'paid') {
            return true;
        }

        $runRestock = static function () use ($orderId, $reason, $pdo): void {
            if (self::restockTableReady()) {
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO order_stock_restock_events (order_id, idempotency_key, reason) VALUES (?, ?, ?)'
                );
                $ins->execute([$orderId, 'restock_order_' . $orderId, $reason]);
                if ($ins->rowCount() === 0) {
                    return;
                }
            } else {
                static $restocked = [];
                if (!empty($restocked[$orderId])) {
                    return;
                }
                $restocked[$orderId] = true;
            }

            $items = $pdo->prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = ?');
            $items->execute([$orderId]);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $vid = (int) $it['variant_id'];
                $qty = (int) $it['quantity'];
                if ($vid > 0 && $qty > 0) {
                    $lineReason = $reason . ' (order ' . $orderId . ')';
                    if (!InventoryService::increment($vid, $qty, $lineReason)) {
                        throw new RuntimeException('Could not restore stock for variant ' . $vid);
                    }
                }
            }
        };

        try {
            if ($pdo->inTransaction()) {
                $runRestock();

                return true;
            }

            return (bool) Database::transaction(static function () use ($runRestock): bool {
                $runRestock();

                return true;
            });
        } catch (Throwable $e) {
            error_log('restockStorefrontOrderOnce: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $orderRow
     */
    private static function createRefundRequestIfPaidOnline(
        PDO $pdo,
        array $orderRow,
        ?int $customerId,
        string $humanReason,
        string $reasonDetail
    ): ?int {
        $orderId = (int) $orderRow['id'];
        if ((string) ($orderRow['payment_status'] ?? '') !== 'paid') {
            return null;
        }
        $refundable = RefundRequestService::availableRefundAmountForOrder($orderId);
        if ($refundable <= 0) {
            return null;
        }
        $open = $pdo->prepare(
            "SELECT id FROM refund_requests WHERE order_id = ? AND status NOT IN ('rejected', 'cancelled', 'refunded', 'refund_failed') LIMIT 1"
        );
        $open->execute([$orderId]);
        $existingId = (int) ($open->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return null;
        }
        $cancelRow = $pdo->prepare(
            "SELECT id FROM refund_requests WHERE order_id = ? AND reason_code = 'cancellation'
             AND status NOT IN ('rejected', 'cancelled', 'refunded', 'refund_failed') LIMIT 1"
        );
        $cancelRow->execute([$orderId]);
        if ((int) ($cancelRow->fetchColumn() ?: 0) > 0) {
            return null;
        }

        $custId = $customerId ?? (int) ($orderRow['customer_id'] ?? 0);
        if ($custId <= 0) {
            return null;
        }

        $requestNumber = RefundRequestService::nextRequestNumber();
        $desc = 'Automatic refund request from order cancellation: ' . $humanReason;
        if ($reasonDetail !== '') {
            $desc .= ' — ' . $reasonDetail;
        }
        $pdo->prepare(
            'INSERT INTO refund_requests
             (request_number, customer_id, order_id, reason_code, description, preferred_resolution, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $requestNumber,
            $custId,
            $orderId,
            'cancellation',
            $desc,
            'refund',
            RefundStatus::REQUESTED,
        ]);
        $reqId = (int) $pdo->lastInsertId();
        $items = $pdo->prepare('SELECT id, quantity, unit_price FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        $lineRows = [];
        $lineTotal = 0.0;
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $qty = (int) $it['quantity'];
            $amt = round((float) $it['unit_price'] * $qty, 2);
            $lineRows[] = ['id' => (int) $it['id'], 'qty' => $qty, 'amt' => $amt];
            $lineTotal += $amt;
        }
        $scale = ($lineTotal > $refundable + 0.01 && $lineTotal > 0) ? ($refundable / $lineTotal) : 1.0;
        foreach ($lineRows as $line) {
            $amt = round($line['amt'] * $scale, 2);
            if ($amt <= 0) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO refund_request_items (refund_request_id, order_item_id, quantity, amount_inr) VALUES (?, ?, ?, ?)'
            )->execute([$reqId, $line['id'], $line['qty'], $amt]);
        }
        RefundRequestService::appendHistoryPublic($pdo, $reqId, 'system', null, 'auto_created', [
            'source' => 'order_cancellation',
            'amount' => $refundable,
        ]);

        return $reqId;
    }

    /** @return array<string, mixed>|null */
    public static function deliveryRowForOrder(int $orderId, bool $forUpdate = false): ?array
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $sql = 'SELECT * FROM deliveries WHERE order_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql = 'SELECT * FROM deliveries WHERE order_id = ? LIMIT 1 FOR UPDATE';
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function cancellationEventForOrder(int $orderId): ?array
    {
        if (!self::eventsTableReady() || $orderId <= 0) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM order_cancellation_events WHERE order_id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
