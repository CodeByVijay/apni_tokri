<?php

declare(strict_types=1);

/**
 * Admin-only delivery reschedule for placed orders (no payment changes).
 */
final class AdminOrderDeliveryRescheduleService
{
    private const ADMIN_HOLD_MINUTES = 240;

    private const BLOCKED_STATUSES = [
        OrderStatus::DELIVERED,
        OrderStatus::OUT_FOR_DELIVERY,
        OrderStatus::CANCELLED,
        OrderStatus::REFUNDED,
    ];

    private const MAX_DAYS_AHEAD = 60;

    public static function canRescheduleOrder(array $order, ?array $paymentRecovery): bool
    {
        if (!DeliveryConfig::slotsEnabled() || !Install::isSchemaReady()) {
            return false;
        }
        if ($paymentRecovery !== null) {
            return false;
        }
        $status = strtolower(trim((string) ($order['status'] ?? '')));

        return !in_array($status, self::BLOCKED_STATUSES, true);
    }

    /**
     * @return list<array{id: int, slug: string, label: string, window: string, delivery_date: string, remaining: int}>
     */
    public static function slotOptionsForDate(int $orderId, string $deliveryDate): array
    {
        if ($orderId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            return [];
        }
        $err = self::validateFutureDate($deliveryDate);
        if ($err !== null) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();

        return DeliverySlotService::listOptionsForDeliveryDate($pdo, $deliveryDate, $orderId, true);
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function reschedule(
        int $orderId,
        int $newSlotId,
        string $newDeliveryDate,
        int $adminUserId,
        string $confirmNote
    ): array {
        $confirmNote = trim($confirmNote);
        if (mb_strlen($confirmNote) < 10) {
            return ['ok' => false, 'message' => 'Enter customer confirmation / reason (at least 10 characters).'];
        }
        if ($orderId <= 0 || $newSlotId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDeliveryDate)) {
            return ['ok' => false, 'message' => 'Choose a valid delivery slot and date.'];
        }
        if (!DeliveryConfig::slotsEnabled() || !Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Delivery slots are not enabled.'];
        }
        $dateErr = self::validateFutureDate($newDeliveryDate);
        if ($dateErr !== null) {
            return ['ok' => false, 'message' => $dateErr];
        }
        if (PaymentFulfilmentReconciliationService::adminRecoveryContext($orderId) !== null) {
            return ['ok' => false, 'message' => 'Use payment fulfilment reconciliation for this order instead of reschedule.'];
        }

        try {
            Database::transaction(static function () use (
                $orderId,
                $newSlotId,
                $newDeliveryDate,
                $adminUserId,
                $confirmNote
            ): void {
                $pdo = Database::pdo();
                $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $ordStmt->execute([$orderId]);
                $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    throw new DeliverySlotBookingException('Order not found.');
                }
                $status = strtolower((string) ($order['status'] ?? ''));
                if (in_array($status, self::BLOCKED_STATUSES, true)) {
                    throw new DeliverySlotBookingException('This order cannot be rescheduled in its current status.');
                }

                $oldSlotId = isset($order['delivery_slot_id']) ? (int) $order['delivery_slot_id'] : 0;
                $oldDate = (string) ($order['delivery_date'] ?? '');
                $oldSlug = (string) ($order['delivery_slot'] ?? '');
                if ($oldSlotId === $newSlotId && $oldDate === $newDeliveryDate) {
                    throw new DeliverySlotBookingException('Order is already scheduled for that slot and date.');
                }

                $slotRow = DeliverySlotReservationService::lockSlotAndAssertCapacity(
                    $pdo,
                    $newSlotId,
                    $newDeliveryDate,
                    $orderId,
                    true
                );

                DeliverySlotReservationService::reassignOrderReservation(
                    $pdo,
                    $orderId,
                    $newSlotId,
                    $newDeliveryDate,
                    self::ADMIN_HOLD_MINUTES
                );

                $slug = (string) ($slotRow['slug'] ?? '');
                $snapshot = DeliveryOrderSnapshot::fromOrderRow($order);
                $patched = DeliveryOrderSnapshot::applyAdminReschedule($snapshot, $slotRow, $newDeliveryDate);
                $snapJson = json_encode($patched, JSON_UNESCAPED_UNICODE);
                if ($snapJson === false) {
                    throw new DeliverySlotBookingException('Could not update delivery snapshot.');
                }

                $pdo->prepare(
                    'UPDATE orders SET delivery_slot_id = ?, delivery_slot = ?, delivery_date = ?,
                     delivery_snapshot_json = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$newSlotId, $slug, $newDeliveryDate, $snapJson, $orderId]);

                $payStatus = (string) ($order['payment_status'] ?? '');
                if ($payStatus === 'paid') {
                    DeliverySlotReservationService::consumeForOrder($orderId, $pdo);
                }

                $oldLabel = $oldSlug;
                if ($oldSlotId > 0) {
                    $oldRow = DeliverySlotRepository::find($oldSlotId);
                    if ($oldRow !== null) {
                        $oldLabel = (string) ($oldRow['label'] ?? $oldSlug);
                    }
                }
                $newLabel = (string) ($slotRow['label'] ?? $slug);
                $historyNote = sprintf(
                    'Delivery rescheduled. From: %s (%s) on %s → To: %s (%s) on %s. Customer confirmation: %s',
                    $oldLabel,
                    $oldSlug !== '' ? $oldSlug : '—',
                    $oldDate !== '' ? $oldDate : '—',
                    $newLabel,
                    $slug,
                    $newDeliveryDate,
                    $confirmNote
                );
                if (mb_strlen($historyNote) > 2000) {
                    $historyNote = mb_substr($historyNote, 0, 1997) . '…';
                }

                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
                )->execute([
                    $orderId,
                    (string) ($order['status'] ?? OrderStatus::CONFIRMED),
                    $historyNote,
                    $adminUserId > 0 ? $adminUserId : null,
                ]);
            });
        } catch (DeliverySlotBookingException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('AdminOrderDeliveryRescheduleService::reschedule ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not reschedule delivery. Try again or choose another slot.'];
        }

        if (Install::isSchemaReady() && AdminAuth::check()) {
            AuditLog::write('order.delivery_reschedule', 'orders', (string) $orderId, [
                'slot_id' => $newSlotId,
                'delivery_date' => $newDeliveryDate,
            ]);
        }

        return ['ok' => true, 'message' => 'Delivery rescheduled.'];
    }

    private static function validateFutureDate(string $deliveryDate): ?string
    {
        $today = DeliveryConfig::now()->format('Y-m-d');
        if ($deliveryDate < $today) {
            return 'Delivery date cannot be in the past.';
        }
        try {
            $max = DeliveryConfig::now()->modify('+' . self::MAX_DAYS_AHEAD . ' days')->format('Y-m-d');
        } catch (Throwable $e) {
            $max = $today;
        }
        if ($deliveryDate > $max) {
            return 'Delivery date is too far in the future.';
        }

        return null;
    }
}
