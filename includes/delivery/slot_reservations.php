<?php

declare(strict_types=1);

final class DeliverySlotReservationService
{
    public const STATUS_HELD = 'held';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONSUMED = 'consumed';

    public const DEFAULT_HOLD_MINUTES = 30;

    /** Order statuses that never consume slot capacity (checkout + admin counts). */
    public const CAPACITY_EXCLUDED_ORDER_STATUSES = ['cancelled'];

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_slot_reservations'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Release expired holds (CLI/cron safe). */
    public static function releaseExpiredHolds(?PDO $pdo = null): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $pdo = $pdo ?? Database::pdo();
        PaymentSlotHoldService::refreshHoldsForOpenPayments($pdo);
        $stmt = $pdo->prepare(
            "UPDATE delivery_slot_reservations SET status = ?, updated_at = NOW()
             WHERE status = ? AND expires_at <= NOW()"
        );
        $stmt->execute([self::STATUS_RELEASED, self::STATUS_HELD]);

        return $stmt->rowCount();
    }

    /**
     * Extend an active hold to at least $targetExpiresAt (capped by PaymentSlotHoldService absolute max).
     */
    public static function extendHoldExpiry(PDO $pdo, int $orderId, DateTimeInterface $targetExpiresAt): bool
    {
        if (!self::tableReady() || $orderId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT * FROM delivery_slot_reservations WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) ($row['status'] ?? '') !== self::STATUS_HELD) {
            return false;
        }
        try {
            $created = new DateTimeImmutable((string) ($row['created_at'] ?? 'now'));
            $cap = $created->modify('+' . PaymentSlotHoldService::ABSOLUTE_MAX_HOLD_MINUTES . ' minutes');
        } catch (Throwable $e) {
            return false;
        }
        $target = DateTimeImmutable::createFromInterface($targetExpiresAt);
        if ($target > $cap) {
            $target = $cap;
        }
        $current = new DateTimeImmutable((string) ($row['expires_at'] ?? 'now'));
        if ($target <= $current) {
            return false;
        }
        $pdo->prepare(
            'UPDATE delivery_slot_reservations SET expires_at = ?, updated_at = NOW() WHERE order_id = ? AND status = ?'
        )->execute([$target->format('Y-m-d H:i:s'), $orderId, self::STATUS_HELD]);

        return true;
    }

    public static function releaseForOrder(int $orderId, ?PDO $pdo = null): void
    {
        if (!self::tableReady() || $orderId <= 0) {
            return;
        }
        $pdo = $pdo ?? Database::pdo();
        $pdo->prepare(
            "UPDATE delivery_slot_reservations SET status = ?, updated_at = NOW()
             WHERE order_id = ? AND status IN (?, ?)"
        )->execute([self::STATUS_RELEASED, $orderId, self::STATUS_HELD, self::STATUS_CONSUMED]);
    }

    /** SQL fragment: orders that consume capacity via confirmed payment. */
    public static function paidOrderConsumesCapacitySql(string $orderAlias = 'o'): string
    {
        $excluded = implode(',', array_map(static function (string $s): string {
            return "'" . str_replace("'", "''", $s) . "'";
        }, self::CAPACITY_EXCLUDED_ORDER_STATUSES));

        return $orderAlias . ".payment_status = 'paid' AND " . $orderAlias . ".status NOT IN (" . $excluded . ')';
    }

    /**
     * Orders currently using this slot's capacity (all delivery dates): paid non-cancelled + active checkout holds.
     */
    public static function countOrdersConsumingSlot(int $slotId, ?PDO $pdo = null): int
    {
        if ($slotId <= 0) {
            return 0;
        }
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        self::releaseExpiredHolds($pdo);

        $paidSql = 'SELECT COUNT(*) FROM orders o WHERE o.delivery_slot_id = ? AND ' . self::paidOrderConsumesCapacitySql('o');
        $paid = $pdo->prepare($paidSql);
        $paid->execute([$slotId]);
        $count = (int) $paid->fetchColumn();

        if (!self::tableReady()) {
            return $count;
        }

        $holdSql = "SELECT COUNT(DISTINCT r.order_id) FROM delivery_slot_reservations r
             INNER JOIN orders o ON o.id = r.order_id
             WHERE r.delivery_slot_id = ? AND r.status = ? AND r.expires_at > NOW()
             AND o.delivery_slot_id = ?
             AND NOT (" . self::paidOrderConsumesCapacitySql('o') . ')';
        $holds = $pdo->prepare($holdSql);
        $holds->execute([$slotId, self::STATUS_HELD, $slotId]);
        $count += (int) $holds->fetchColumn();

        return $count;
    }

    public static function consumeForOrder(int $orderId, ?PDO $pdo = null): void
    {
        if (!self::tableReady() || $orderId <= 0) {
            return;
        }
        $pdo = $pdo ?? Database::pdo();
        $pdo->prepare(
            "UPDATE delivery_slot_reservations SET status = ?, updated_at = NOW()
             WHERE order_id = ? AND status = ?"
        )->execute([self::STATUS_CONSUMED, $orderId, self::STATUS_HELD]);
    }

    /**
     * Lock slot row and ensure capacity for one more booking.
     *
     * @return array<string, mixed> slot row
     */
    public static function lockSlotAndAssertCapacity(
        PDO $pdo,
        int $slotId,
        string $deliveryDate,
        ?int $excludeOrderId = null,
        bool $skipCustomerCutoff = false,
        bool $skipCapacityCheck = false
    ): array {
        self::releaseExpiredHolds($pdo);
        $stmt = $pdo->prepare('SELECT * FROM delivery_slots WHERE id = ? FOR UPDATE');
        $stmt->execute([$slotId]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slot || empty($slot['is_active'])) {
            throw new DeliverySlotBookingException('Delivery slot is not available.');
        }
        $err = DeliverySlotService::assertBookableLocked(
            $pdo,
            $slot,
            $deliveryDate,
            $excludeOrderId,
            $skipCustomerCutoff,
            $skipCapacityCheck
        );
        if ($err !== null) {
            throw new DeliverySlotBookingException($err);
        }

        return $slot;
    }

    /**
     * Move or create the per-order reservation row onto a slot/date (admin reschedule).
     * Frees capacity on the previous slot/date when the row is updated.
     */
    public static function reassignOrderReservation(
        PDO $pdo,
        int $orderId,
        int $slotId,
        string $deliveryDate,
        int $holdMinutes = self::DEFAULT_HOLD_MINUTES
    ): void {
        if (!self::tableReady() || $orderId <= 0) {
            return;
        }
        $expires = DeliveryConfig::now()->modify('+' . max(5, $holdMinutes) . ' minutes')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('SELECT * FROM delivery_slot_reservations WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $pdo->prepare(
                'UPDATE delivery_slot_reservations SET delivery_slot_id = ?, delivery_date = ?, status = ?,
                 expires_at = ?, updated_at = NOW() WHERE order_id = ?'
            )->execute([$slotId, $deliveryDate, self::STATUS_HELD, $expires, $orderId]);

            return;
        }

        $pdo->prepare(
            'INSERT INTO delivery_slot_reservations (delivery_slot_id, delivery_date, order_id, status, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$slotId, $deliveryDate, $orderId, self::STATUS_HELD, $expires]);
    }

    public static function createHold(PDO $pdo, int $slotId, string $deliveryDate, int $orderId, int $holdMinutes = self::DEFAULT_HOLD_MINUTES): void
    {
        if (!self::tableReady() || $orderId <= 0) {
            return;
        }
        $expires = DeliveryConfig::now()->modify('+' . max(5, $holdMinutes) . ' minutes')->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare('SELECT * FROM delivery_slot_reservations WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            $status = (string) ($row['status'] ?? '');
            if ($status === self::STATUS_CONSUMED) {
                return;
            }
            if ($status === self::STATUS_HELD || $status === self::STATUS_RELEASED) {
                $pdo->prepare(
                    'UPDATE delivery_slot_reservations SET delivery_slot_id = ?, delivery_date = ?, status = ?,
                     expires_at = ?, updated_at = NOW() WHERE order_id = ? AND status IN (?, ?)'
                )->execute([
                    $slotId,
                    $deliveryDate,
                    self::STATUS_HELD,
                    $expires,
                    $orderId,
                    self::STATUS_HELD,
                    self::STATUS_RELEASED,
                ]);

                return;
            }

            throw new DeliverySlotBookingException('Invalid reservation state for order.');
        }

        $pdo->prepare(
            'INSERT INTO delivery_slot_reservations (delivery_slot_id, delivery_date, order_id, status, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$slotId, $deliveryDate, $orderId, self::STATUS_HELD, $expires]);
    }

    public static function activeHoldForOrder(PDO $pdo, int $orderId): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM delivery_slot_reservations
             WHERE order_id = ? AND status = ? AND expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$orderId, self::STATUS_HELD]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function usedUnits(PDO $pdo, int $slotId, string $deliveryDate, ?int $excludeOrderId = null): int
    {
        $paid = $pdo->prepare(
            'SELECT COUNT(*) FROM orders o
             WHERE o.delivery_slot_id = ? AND o.delivery_date = ?
             AND ' . self::paidOrderConsumesCapacitySql('o')
        );
        $paid->execute([$slotId, $deliveryDate]);
        $used = (int) $paid->fetchColumn();

        if (!self::tableReady()) {
            return $used;
        }

        self::releaseExpiredHolds($pdo);
        $holdSql = "SELECT COUNT(*) FROM delivery_slot_reservations
             WHERE delivery_slot_id = ? AND delivery_date = ? AND status = ? AND expires_at > NOW()";
        $params = [$slotId, $deliveryDate, self::STATUS_HELD];
        if ($excludeOrderId !== null && $excludeOrderId > 0) {
            $holdSql .= ' AND (order_id IS NULL OR order_id != ?)';
            $params[] = $excludeOrderId;
        }
        $holds = $pdo->prepare($holdSql);
        $holds->execute($params);
        $used += (int) $holds->fetchColumn();

        return $used;
    }

    /**
     * @return array{paid: int, holds: int, orphans: int, used: int, remaining: int, capacity: int}
     */
    public static function capacityBreakdown(PDO $pdo, int $slotId, string $deliveryDate): array
    {
        $capStmt = $pdo->prepare('SELECT capacity FROM delivery_slots WHERE id = ?');
        $capStmt->execute([$slotId]);
        $cap = (int) ($capStmt->fetchColumn() ?: 0);

        $paidStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM orders o
             WHERE o.delivery_slot_id = ? AND o.delivery_date = ?
             AND ' . self::paidOrderConsumesCapacitySql('o')
        );
        $paidStmt->execute([$slotId, $deliveryDate]);
        $paid = (int) $paidStmt->fetchColumn();

        $holds = 0;
        $orphans = 0;
        if (self::tableReady()) {
            self::releaseExpiredHolds($pdo);
            $holdStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM delivery_slot_reservations
                 WHERE delivery_slot_id = ? AND delivery_date = ? AND status = ? AND expires_at > NOW()"
            );
            $holdStmt->execute([$slotId, $deliveryDate, self::STATUS_HELD]);
            $holds = (int) $holdStmt->fetchColumn();

            $excluded = implode(',', array_map(static function (string $s): string {
                return "'" . str_replace("'", "''", $s) . "'";
            }, self::CAPACITY_EXCLUDED_ORDER_STATUSES));
            $orphanStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM orders o
                 WHERE o.delivery_slot_id = ? AND o.delivery_date = ?
                 AND o.payment_status = 'pending' AND o.status NOT IN (" . $excluded . ")
                 AND NOT EXISTS (
                   SELECT 1 FROM delivery_slot_reservations r
                   WHERE r.order_id = o.id AND r.status = ? AND r.expires_at > NOW()
                 )"
            );
            $orphanStmt->execute([$slotId, $deliveryDate, self::STATUS_HELD]);
            $orphans = (int) $orphanStmt->fetchColumn();
        }

        $used = self::usedUnits($pdo, $slotId, $deliveryDate, null);

        return [
            'paid' => $paid,
            'holds' => $holds,
            'orphans' => $orphans,
            'used' => $used,
            'remaining' => max(0, $cap - $used),
            'capacity' => $cap,
        ];
    }
}
