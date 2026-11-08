<?php

declare(strict_types=1);

final class DeliverySlotBookingException extends RuntimeException
{
}

final class PaymentCompletionExceptionService
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED_REFUND = 'resolved_refund';
    public const STATUS_RESOLVED_MANUAL = 'resolved_manual';
    public const STATUS_DISMISSED = 'dismissed';

    public const CATEGORY_DELIVERY = 'delivery';
    public const CATEGORY_INVENTORY = 'inventory';
    public const CATEGORY_ADDRESS_DRIFT = 'address_drift';
    public const CATEGORY_ORDER_CANCELLED = 'order_cancelled';
    public const CATEGORY_OTHER = 'other';

    /**
     * @return array{created: bool, id: int|null}
     */
    public static function recordFulfilmentFailure(
        string $orderNumber,
        string $idempotencyKey,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        ?string $gatewayEventId,
        float $capturedAmountInr,
        string $failureReason,
        ?string $failureCategory = null
    ): array {
        return self::recordCapturedFulfilmentFailure(
            $orderNumber,
            $idempotencyKey,
            $gateway,
            $gatewayPaymentId,
            $gatewayOrderId,
            $gatewayEventId,
            $capturedAmountInr,
            $failureReason,
            $failureCategory
        );
    }

    public static function classifyFailureReason(string $message): string
    {
        if (stripos($message, 'already cancelled') !== false) {
            return self::CATEGORY_ORDER_CANCELLED;
        }
        if (stripos($message, '[address_drift]') !== false || stripos($message, 'address changed') !== false) {
            return self::CATEGORY_ADDRESS_DRIFT;
        }
        if (stripos($message, '[inventory]') !== false || stripos($message, 'stock') !== false
            || stripos($message, 'Insufficient stock') !== false) {
            return self::CATEGORY_INVENTORY;
        }
        if (stripos($message, 'slot') !== false || stripos($message, 'delivery') !== false
            || stripos($message, 'provider') !== false) {
            return self::CATEGORY_DELIVERY;
        }

        return self::CATEGORY_OTHER;
    }

    public static function isRecoverableAfterCapture(Throwable $e): bool
    {
        if ($e instanceof DeliverySlotBookingException) {
            return true;
        }
        if ($e instanceof RuntimeException) {
            $msg = $e->getMessage();

            return stripos($msg, '[inventory]') === 0
                || stripos($msg, 'Insufficient stock') !== false
                || stripos($msg, 'Stock decrement failed') !== false
                || stripos($msg, '[reconciliation]') === 0
                || stripos($msg, 'already cancelled') !== false;
        }

        return false;
    }

    /**
     * @return array{created: bool, id: int|null}
     */
    public static function recordCapturedFulfilmentFailure(
        string $orderNumber,
        string $idempotencyKey,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        ?string $gatewayEventId,
        float $capturedAmountInr,
        string $failureReason,
        ?string $failureCategory = null
    ): array {
        if (!Install::isSchemaReady() || !self::tableReady()) {
            return ['created' => false, 'id' => null];
        }
        if ($gatewayPaymentId === '') {
            return ['created' => false, 'id' => null];
        }
        $category = $failureCategory ?? self::classifyFailureReason($failureReason);

        return Database::transaction(static function () use (
            $orderNumber,
            $idempotencyKey,
            $gateway,
            $gatewayPaymentId,
            $gatewayOrderId,
            $gatewayEventId,
            $capturedAmountInr,
            $failureReason,
            $category
        ): array {
            $pdo = Database::pdo();
            $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1 FOR UPDATE');
            $orderStmt->execute([$orderNumber]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || (string) $order['payment_status'] === 'paid') {
                return ['created' => false, 'id' => null];
            }
            $orderId = (int) $order['id'];

            $payStmt = $pdo->prepare(
                'SELECT * FROM payments WHERE order_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
            );
            $payStmt->execute([$orderId, $idempotencyKey]);
            $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
            $paymentId = $payment ? (int) $payment['id'] : null;

            $existing = $pdo->prepare(
                'SELECT id FROM payment_completion_exceptions WHERE gateway = ? AND gateway_payment_id = ? LIMIT 1'
            );
            $existing->execute([$gateway, $gatewayPaymentId]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                return ['created' => false, 'id' => (int) $existingId];
            }

            $slotId = isset($order['delivery_slot_id']) ? (int) $order['delivery_slot_id'] : null;
            $deliveryDate = (string) ($order['delivery_date'] ?? '');

            if (self::hasFailureCategoryColumn($pdo)) {
                $pdo->prepare(
                    'INSERT INTO payment_completion_exceptions (
                       order_id, payment_id, gateway, gateway_payment_id, gateway_order_id, gateway_event_id,
                       captured_amount_inr, delivery_slot_id, delivery_date, failure_reason, failure_category, status
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $orderId,
                    $paymentId,
                    $gateway,
                    $gatewayPaymentId,
                    $gatewayOrderId !== '' ? $gatewayOrderId : null,
                    $gatewayEventId,
                    round($capturedAmountInr, 2),
                    $slotId > 0 ? $slotId : null,
                    $deliveryDate !== '' ? $deliveryDate : null,
                    mb_substr($failureReason, 0, 255),
                    $category,
                    self::STATUS_OPEN,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO payment_completion_exceptions (
                       order_id, payment_id, gateway, gateway_payment_id, gateway_order_id, gateway_event_id,
                       captured_amount_inr, delivery_slot_id, delivery_date, failure_reason, status
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $orderId,
                    $paymentId,
                    $gateway,
                    $gatewayPaymentId,
                    $gatewayOrderId !== '' ? $gatewayOrderId : null,
                    $gatewayEventId,
                    round($capturedAmountInr, 2),
                    $slotId > 0 ? $slotId : null,
                    $deliveryDate !== '' ? $deliveryDate : null,
                    mb_substr($failureReason, 0, 255),
                    self::STATUS_OPEN,
                ]);
            }
            $exceptionId = (int) $pdo->lastInsertId();

            if ($paymentId !== null) {
                $pdo->prepare(
                    "UPDATE payments SET status = 'reconciliation_required', gateway_payment_id = COALESCE(NULLIF(gateway_payment_id, ''), ?),
                     gateway_order_id = COALESCE(NULLIF(gateway_order_id, ''), ?), updated_at = NOW() WHERE id = ?"
                )->execute([$gatewayPaymentId, $gatewayOrderId !== '' ? $gatewayOrderId : null, $paymentId]);
            }

            $pdo->prepare(
                'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)'
            )->execute([
                $orderId,
                'Payment reconciliation required',
                'Gateway captured ₹' . number_format($capturedAmountInr, 2) . ' but order fulfilment could not be completed ('
                . $category . '): ' . $failureReason,
            ]);

            return ['created' => true, 'id' => $exceptionId];
        });
    }

    /**
     * @deprecated use recordCapturedFulfilmentFailure
     * @return array{created: bool, id: int|null}
     */
    public static function recordSlotFailure(
        string $orderNumber,
        string $idempotencyKey,
        string $gateway,
        string $gatewayPaymentId,
        string $gatewayOrderId,
        ?string $gatewayEventId,
        float $capturedAmountInr,
        string $failureReason
    ): array {
        return self::recordCapturedFulfilmentFailure(
            $orderNumber,
            $idempotencyKey,
            $gateway,
            $gatewayPaymentId,
            $gatewayOrderId,
            $gatewayEventId,
            $capturedAmountInr,
            $failureReason,
            self::CATEGORY_DELIVERY
        );
    }

    private static function hasFailureCategoryColumn(PDO $pdo): bool
    {
        static $ready;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_completion_exceptions'
                   AND COLUMN_NAME = 'failure_category'"
            )->fetchColumn();
            $ready = $n > 0;
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_completion_exceptions'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public static function openForOrderId(int $orderId): ?array
    {
        if ($orderId <= 0 || !self::tableReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM payment_completion_exceptions WHERE order_id = ? AND status = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId, self::STATUS_OPEN]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function listOpen(int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT e.*, o.order_number FROM payment_completion_exceptions e
             INNER JOIN orders o ON o.id = e.order_id
             WHERE e.status = ?
             ORDER BY e.created_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, self::STATUS_OPEN, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function listRecent(?string $status = null, int $limit = 100): array
    {
        if (!self::tableReady()) {
            return [];
        }
        Install::ensureConnection();
        $sql = 'SELECT e.*, o.order_number FROM payment_completion_exceptions e
                INNER JOIN orders o ON o.id = e.order_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE e.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY e.created_at DESC LIMIT ?';
        $params[] = $limit;
        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function resolve(int $exceptionId, string $status, string $note, ?int $adminUserId): bool
    {
        if (!in_array($status, [self::STATUS_RESOLVED_REFUND, self::STATUS_RESOLVED_MANUAL, self::STATUS_DISMISSED], true)) {
            return false;
        }
        if (mb_strlen(trim($note)) < 10) {
            return false;
        }
        if (!self::tableReady()) {
            return false;
        }
        try {
            return Database::transaction(static function () use ($exceptionId, $status, $note, $adminUserId): bool {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT e.*, o.order_number FROM payment_completion_exceptions e
                     INNER JOIN orders o ON o.id = e.order_id WHERE e.id = ? FOR UPDATE'
                );
                $stmt->execute([$exceptionId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row || (string) $row['status'] !== self::STATUS_OPEN) {
                    return false;
                }
                $upd = $pdo->prepare(
                    'UPDATE payment_completion_exceptions SET status = ?, resolution_note = ?, resolved_at = NOW(),
                     admin_user_id = ?, updated_at = NOW() WHERE id = ? AND status = ?'
                );
                $upd->execute([$status, $note, $adminUserId, $exceptionId, self::STATUS_OPEN]);
                if ($upd->rowCount() === 0) {
                    return false;
                }
                $label = $status === self::STATUS_DISMISSED ? 'Exception dismissed'
                    : ($status === self::STATUS_RESOLVED_REFUND ? 'Exception — refund path' : 'Exception — manual fulfilment');
                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)'
                )->execute([
                    (int) $row['order_id'],
                    $label,
                    $note . ' [gateway ' . ($row['gateway_payment_id'] ?? '') . ']',
                ]);

                return true;
            });
        } catch (Throwable $e) {
            return false;
        }
    }
}
