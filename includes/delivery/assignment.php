<?php

declare(strict_types=1);

final class DeliveryCourierPresets
{
    /** @return list<string> */
    public static function list(): array
    {
        $raw = AppSettings::get('delivery.courier_presets', '["Porter","Rapido","In-house","Other"]') ?? '[]';
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $v) {
                    if (is_string($v) && trim($v) !== '') {
                        $out[] = trim($v);
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        } catch (Throwable $e) {
            // fall through
        }

        return ['Porter', 'Rapido', 'In-house', 'Other'];
    }
}

final class DeliveryAssignmentService
{
    public static function createForOrder(int $orderId): ?int
    {
        if (!Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT id FROM deliveries WHERE order_id = ? LIMIT 1');
        $exists->execute([$orderId]);
        $existingId = $exists->fetchColumn();
        if ($existingId) {
            return (int) $existingId;
        }
        $status = DeliveryStatus::PENDING_DISPATCH;
        $pdo->prepare('INSERT INTO deliveries (order_id, status) VALUES (?, ?)')->execute([$orderId, $status]);
        $deliveryId = (int) $pdo->lastInsertId();
        self::insertHistory(
            $pdo,
            $deliveryId,
            $status,
            'Order confirmed — awaiting dispatch',
            null,
            true,
            DeliveryStatus::publicLabel($status)
        );
        DeliveryCustomerNotifyService::afterOrderConfirmed($deliveryId);

        return $deliveryId;
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $deliveryId): ?array
    {
        if (!Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT d.*, o.order_number, o.customer_id, o.delivery_slot, o.delivery_date, o.delivery_eta,
                    o.payment_status, o.status AS order_status, o.grand_total
             FROM deliveries d
             INNER JOIN orders o ON o.id = d.order_id
             WHERE d.id = ? LIMIT 1'
        );
        $stmt->execute([$deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findByOrderNumber(string $orderNumber): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT d.id FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE o.order_number = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber]);
        $id = (int) ($stmt->fetchColumn() ?: 0);

        return $id > 0 ? self::findById($id) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function listForAdmin(array $filters = [], int $limit = 150): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $sql = "SELECT d.*, o.order_number, o.delivery_slot, o.delivery_date, o.payment_status,
                       c.name AS customer_name, c.mobile AS customer_mobile
                FROM deliveries d
                INNER JOIN orders o ON o.id = d.order_id
                LEFT JOIN customers c ON c.id = o.customer_id
                WHERE 1=1";
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= ' AND d.status = ?';
            $params[] = DeliveryStatus::normalize((string) $filters['status']);
        }
        if (!empty($filters['active_only'])) {
            $sql .= " AND d.status NOT IN ('delivered', 'cancelled')";
        }
        $sql .= ' ORDER BY COALESCE(o.delivery_date, DATE(d.created_at)) ASC, d.updated_at DESC LIMIT ' . (int) $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function queue(): array
    {
        return self::listForAdmin(['active_only' => true], 100);
    }

    /** @return list<array<string, mixed>> */
    public static function statusHistory(int $deliveryId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT h.*, u.name AS admin_name FROM delivery_status_history h
             LEFT JOIN admin_users u ON u.id = h.admin_user_id
             WHERE h.delivery_id = ? ORDER BY h.created_at ASC, h.id ASC'
        );
        $stmt->execute([$deliveryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message?: string}
     */
    public static function updateFromAdmin(int $deliveryId, array $input): array
    {
        $note = trim((string) ($input['internal_remarks'] ?? $input['note'] ?? ''));
        if (mb_strlen($note) < 5) {
            return ['ok' => false, 'message' => 'Internal remark required (min 5 characters).'];
        }
        $newStatus = isset($input['status']) ? DeliveryStatus::normalize((string) $input['status']) : null;
        $isRetry = !empty($input['retry']);
        Install::ensureConnection();
        $pdo = Database::pdo();
        $row = self::findById($deliveryId);
        if (!$row) {
            return ['ok' => false, 'message' => 'Delivery not found.'];
        }
        $current = DeliveryStatus::normalize((string) $row['status']);
        if ($newStatus === null || $newStatus === '') {
            return ['ok' => false, 'message' => 'Choose a status.'];
        }
        if (!DeliveryStatusTransitions::canTransition($current, $newStatus, $isRetry)) {
            $allowShortcut = !empty($input['allow_status_shortcut'])
                && (($newStatus === DeliveryStatus::DELIVERED && !in_array($current, [DeliveryStatus::DELIVERED, DeliveryStatus::CANCELLED], true))
                    || ($newStatus === DeliveryStatus::FAILED && !in_array($current, [DeliveryStatus::FAILED, DeliveryStatus::CANCELLED], true)));
            if (!$allowShortcut) {
                return ['ok' => false, 'message' => 'Invalid status transition.'];
            }
        }

        $courier = trim((string) ($input['courier_service_name'] ?? ''));
        $booking = trim((string) ($input['booking_reference'] ?? ''));
        $driver = trim((string) ($input['driver_name'] ?? ''));
        $phone = trim((string) ($input['delivery_person_phone'] ?? ''));
        $phoneNorm = $phone !== '' ? Validation::normalizeMobileIndia($phone) : null;
        if ($phone !== '' && $phoneNorm === null) {
            return ['ok' => false, 'message' => 'Invalid delivery person phone.'];
        }

        $dispatchedAt = null;
        if ($newStatus === DeliveryStatus::DISPATCHED) {
            $dispatchedAt = trim((string) ($input['dispatched_at'] ?? ''));
            if ($dispatchedAt === '') {
                $dispatchedAt = DeliveryConfig::now()->format('Y-m-d H:i:s');
            }
        }

        $retryCount = (int) ($row['retry_count'] ?? 0);
        if ($isRetry && $newStatus === DeliveryStatus::PENDING_DISPATCH) {
            $retryCount++;
            if (empty($input['keep_booking_reference'])) {
                $booking = '';
            }
        }

        try {
            Database::transaction(static function () use (
                $pdo,
                $deliveryId,
                $newStatus,
                $current,
                $courier,
                $booking,
                $driver,
                $phoneNorm,
                $dispatchedAt,
                $note,
                $retryCount
            ): void {
                $prevStmt = $pdo->prepare('SELECT status FROM deliveries WHERE id = ? FOR UPDATE');
                $prevStmt->execute([$deliveryId]);
                $previousStatus = DeliveryStatus::normalize((string) ($prevStmt->fetchColumn() ?: ''));

                $sets = [
                    'status = ?',
                    'courier_service_name = ?',
                    'booking_reference = ?',
                    'driver_name = ?',
                    'delivery_person_phone = ?',
                    'internal_remarks = ?',
                    'retry_count = ?',
                    'updated_at = NOW()',
                ];
                $params = [
                    $newStatus,
                    $courier !== '' ? $courier : null,
                    $booking !== '' ? $booking : null,
                    $driver !== '' ? $driver : null,
                    $phoneNorm,
                    $note,
                    $retryCount,
                ];
                if ($newStatus === DeliveryStatus::DISPATCHED && $dispatchedAt !== null) {
                    $sets[] = 'dispatched_at = ?';
                    $params[] = $dispatchedAt;
                }
                if ($newStatus === DeliveryStatus::DELIVERED) {
                    $sets[] = 'delivered_at = NOW()';
                }
                $params[] = $deliveryId;
                $pdo->prepare('UPDATE deliveries SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

                $publicLabel = DeliveryStatus::publicLabel($newStatus);
                self::insertHistory(
                    $pdo,
                    $deliveryId,
                    $newStatus,
                    '',
                    AdminAuth::id(),
                    true,
                    $publicLabel
                );
                self::insertHistory(
                    $pdo,
                    $deliveryId,
                    $newStatus,
                    $note,
                    AdminAuth::id(),
                    false,
                    null
                );

                if ($newStatus === DeliveryStatus::DELIVERED) {
                    SubscriptionEntitlementService::onDeliveryMarkedDelivered($deliveryId);
                } elseif (in_array($newStatus, [DeliveryStatus::FAILED, DeliveryStatus::CANCELLED], true)) {
                    if ($previousStatus === DeliveryStatus::DELIVERED) {
                        SubscriptionEntitlementService::onDeliveryReversed($deliveryId, $newStatus);
                    }
                    $orderStmt = $pdo->prepare(
                        'SELECT o.id FROM deliveries d INNER JOIN orders o ON o.id = d.order_id WHERE d.id = ? LIMIT 1'
                    );
                    $orderStmt->execute([$deliveryId]);
                    $orderId = (int) ($orderStmt->fetchColumn() ?: 0);
                    if ($orderId > 0) {
                        SubscriptionRestockService::restockOrderOnce($orderId, 'Delivery ' . $newStatus);
                    }
                }
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not update delivery.'];
        }

        DeliveryCustomerNotifyService::afterStatusChange($deliveryId, $newStatus);

        return ['ok' => true];
    }

    /** Backward-compatible wrapper for tests */
    public static function updateStatus(int $deliveryId, string $status, ?string $driver = null, ?string $note = null): bool
    {
        $status = DeliveryStatus::normalize($status);
        $row = self::findById($deliveryId);
        if (!$row) {
            return false;
        }
        $current = DeliveryStatus::normalize((string) $row['status']);
        if ($current === $status) {
            return true;
        }
        if (!DeliveryStatusTransitions::canTransition($current, $status, false)) {
            $allowShortcut = ($status === DeliveryStatus::DELIVERED && !in_array($current, [DeliveryStatus::DELIVERED, DeliveryStatus::CANCELLED], true))
                || ($status === DeliveryStatus::FAILED && !in_array($current, [DeliveryStatus::FAILED, DeliveryStatus::CANCELLED], true));
            if (!$allowShortcut) {
                return false;
            }
        }
        $noteText = $note !== null && $note !== '' ? $note : 'Updated from admin (legacy API)';
        if (mb_strlen($noteText) < 5) {
            $noteText = 'Legacy update: ' . $noteText;
        }
        $res = self::updateFromAdmin($deliveryId, [
            'status' => $status,
            'driver_name' => $driver ?? '',
            'internal_remarks' => $noteText,
            'allow_status_shortcut' => true,
        ]);

        return !empty($res['ok']);
    }

    private static function insertHistory(
        PDO $pdo,
        int $deliveryId,
        string $status,
        string $note,
        ?int $adminUserId,
        bool $customerVisible,
        ?string $publicLabel
    ): void {
        $hasExtended = self::historyExtendedReady($pdo);
        if ($hasExtended) {
            $pdo->prepare(
                'INSERT INTO delivery_status_history
                 (delivery_id, status, note, admin_user_id, customer_visible, public_label) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $deliveryId,
                $status,
                mb_substr($note, 0, 65000),
                $adminUserId,
                $customerVisible ? 1 : 0,
                $publicLabel,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO delivery_status_history (delivery_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
            )->execute([$deliveryId, $status, mb_substr($note, 0, 255), $adminUserId]);
        }
    }

    public static function historyExtendedReady(PDO $pdo): bool
    {
        static $ready;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'delivery_status_history' AND COLUMN_NAME = 'customer_visible'"
            )->fetchColumn();
            $ready = $n > 0;
        } catch (Throwable $e) {
            $ready = false;
        }

        return $ready;
    }
}
