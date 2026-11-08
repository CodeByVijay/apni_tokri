<?php

declare(strict_types=1);

final class PaymentFulfilmentReconciliationService
{
    private const ADMIN_HOLD_MINUTES = 240;

    /** @return array<string, mixed>|null */
    public static function adminRecoveryContext(int $orderId): ?array
    {
        if ($orderId <= 0 || !PaymentCompletionExceptionService::tableReady()) {
            return null;
        }
        $exception = PaymentCompletionExceptionService::openForOrderId($orderId);
        if ($exception === null) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $payStmt = $pdo->prepare(
            "SELECT * FROM payments WHERE order_id = ? AND status = 'reconciliation_required' ORDER BY id DESC LIMIT 1"
        );
        $payStmt->execute([$orderId]);
        $payment = $payStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($payment === null) {
            return null;
        }

        return [
            'exception_id' => (int) $exception['id'],
            'failure_reason' => (string) ($exception['failure_reason'] ?? ''),
            'failure_category' => (string) ($exception['failure_category'] ?? PaymentCompletionExceptionService::CATEGORY_OTHER),
            'captured_amount_inr' => (float) ($exception['captured_amount_inr'] ?? $payment['amount'] ?? 0),
            'gateway' => (string) ($exception['gateway'] ?? $payment['gateway'] ?? ''),
            'gateway_payment_id' => (string) ($exception['gateway_payment_id'] ?? $payment['gateway_payment_id'] ?? ''),
            'gateway_order_id' => (string) ($exception['gateway_order_id'] ?? $payment['gateway_order_id'] ?? ''),
            'idempotency_key' => (string) ($payment['idempotency_key'] ?? ''),
            'payment_id' => (int) $payment['id'],
            'payment_status' => (string) $payment['status'],
        ];
    }

    /**
     * Replacement slots with capacity for this order (excludes this order from hold counts).
     *
     * @return list<array{id: int, slug: string, label: string, window: string, delivery_date: string, remaining: int}>
     */
    public static function listReplacementSlotOptions(int $orderId): array
    {
        if ($orderId <= 0 || !DeliveryConfig::slotsEnabled() || !Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $now = DeliveryConfig::now();
        $today = $now->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $stmt = $pdo->query('SELECT * FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id');
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $slot) {
            $date = ($slot['delivery_day'] ?? '') === 'next_day' ? $tomorrow : $today;
            if (!DeliverySlotService::isWeekdayAllowed($slot, $date)) {
                continue;
            }
            if (!DeliverySlotService::isBeforeCutoff($slot, $now, $date)) {
                continue;
            }
            $slotId = (int) $slot['id'];
            $cap = (int) ($slot['capacity'] ?? 0);
            $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $date, $orderId);
            $remaining = max(0, $cap - $used);
            if ($remaining <= 0) {
                continue;
            }
            $err = DeliverySlotService::assertBookableLocked($pdo, $slot, $date, $orderId);
            if ($err !== null) {
                continue;
            }
            $card = DeliverySlotDisplay::checkoutCard($slot, $date, $remaining);
            $out[] = $card;
        }

        return $out;
    }

    /**
     * Reassign delivery slot, reserve capacity, re-run server-side payment completion (existing gateway capture).
     *
     * @return array{ok: bool, message?: string, idempotent?: bool}
     */
    public static function adminReassignSlotAndCompleteFulfilment(
        int $orderId,
        int $exceptionId,
        int $slotId,
        string $deliveryDate,
        int $adminUserId,
        string $confirmNote
    ): array {
        $confirmNote = trim($confirmNote);
        if (mb_strlen($confirmNote) < 10) {
            return ['ok' => false, 'message' => 'Enter a confirmation note (at least 10 characters).'];
        }
        if ($slotId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            return ['ok' => false, 'message' => 'Choose a valid delivery slot and date.'];
        }
        if (!PaymentCompletionExceptionService::tableReady()) {
            return ['ok' => false, 'message' => 'Reconciliation module is not available.'];
        }

        $gateway = '';
        $gatewayPaymentId = '';
        $gatewayOrderId = '';
        $idempotencyKey = '';
        $orderNumber = '';
        $amountInr = 0.0;

        try {
            Database::transaction(static function () use (
                $orderId,
                $exceptionId,
                $slotId,
                $deliveryDate,
                &$gateway,
                &$gatewayPaymentId,
                &$gatewayOrderId,
                &$idempotencyKey,
                &$orderNumber,
                &$amountInr
            ): void {
                $pdo = Database::pdo();
                $exStmt = $pdo->prepare(
                    'SELECT * FROM payment_completion_exceptions WHERE id = ? AND order_id = ? AND status = ? FOR UPDATE'
                );
                $exStmt->execute([$exceptionId, $orderId, PaymentCompletionExceptionService::STATUS_OPEN]);
                $exception = $exStmt->fetch(PDO::FETCH_ASSOC);
                if (!$exception) {
                    throw new RuntimeException('Reconciliation case is not open or does not match this order.');
                }

                $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $ordStmt->execute([$orderId]);
                $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    throw new RuntimeException('Order not found.');
                }
                if ((string) ($order['payment_status'] ?? '') === 'paid') {
                    throw new RuntimeException('Order is already marked paid.');
                }

                $payStmt = $pdo->prepare(
                    "SELECT * FROM payments WHERE order_id = ? AND status = 'reconciliation_required' ORDER BY id DESC LIMIT 1 FOR UPDATE"
                );
                $payStmt->execute([$orderId]);
                $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    throw new RuntimeException('No captured payment awaiting reconciliation.');
                }

                $slotRow = DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $slotId, $deliveryDate, $orderId);
                $slug = (string) ($slotRow['slug'] ?? '');

                $pdo->prepare(
                    'UPDATE orders SET delivery_slot_id = ?, delivery_date = ?, delivery_slot = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$slotId, $deliveryDate, $slug, $orderId]);

                DeliverySlotReservationService::createHold(
                    $pdo,
                    $slotId,
                    $deliveryDate,
                    $orderId,
                    self::ADMIN_HOLD_MINUTES
                );

                $gateway = (string) ($exception['gateway'] ?? $payment['gateway'] ?? '');
                $gatewayPaymentId = (string) ($exception['gateway_payment_id'] ?? $payment['gateway_payment_id'] ?? '');
                $gatewayOrderId = (string) ($exception['gateway_order_id'] ?? $payment['gateway_order_id'] ?? '');
                $idempotencyKey = (string) ($payment['idempotency_key'] ?? '');
                $orderNumber = (string) ($order['order_number'] ?? '');
                $amountInr = (float) ($exception['captured_amount_inr'] ?? $payment['amount'] ?? $order['grand_total'] ?? 0);

                if ($gatewayPaymentId === '' || $idempotencyKey === '' || $orderNumber === '') {
                    throw new RuntimeException('Payment reference incomplete for recovery.');
                }
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Admin payment fulfilment recovery prep failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not reserve the delivery slot.'];
        }

        $eventId = 'admin_slot_recovery:' . $exceptionId . ':' . $slotId . ':' . $deliveryDate;
        $result = PaymentService::completePayment(
            $orderNumber,
            $gateway,
            $gatewayPaymentId,
            $gatewayOrderId,
            $amountInr,
            'INR',
            $idempotencyKey,
            $eventId
        );

        if (!$result['ok']) {
            $msg = (string) ($result['message'] ?? 'Fulfilment could not be completed.');
            if (!empty($result['reconciliation'])) {
                $msg = 'Payment is still captured but fulfilment failed again: ' . $msg;
            }

            return ['ok' => false, 'message' => $msg];
        }

        $resolved = PaymentCompletionExceptionService::resolve(
            $exceptionId,
            PaymentCompletionExceptionService::STATUS_RESOLVED_MANUAL,
            'Admin slot reassignment: ' . $confirmNote,
            $adminUserId
        );
        if (!$resolved && empty($result['idempotent'])) {
            error_log('Payment recovered but exception resolve failed for order ' . $orderNumber);
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO order_status_history (order_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
        )->execute([
            $orderId,
            'Order placed',
            'Fulfilment completed after payment reconciliation (slot ' . $deliveryDate . ', admin).',
            $adminUserId > 0 ? $adminUserId : null,
        ]);

        PaymentService::clearStorefrontCheckoutSession($orderNumber);

        return [
            'ok' => true,
            'idempotent' => !empty($result['idempotent']),
            'message' => !empty($result['idempotent'])
                ? 'Order was already confirmed (no duplicate fulfilment).'
                : 'Payment captured and order confirmed with the new delivery slot.',
        ];
    }

    /**
     * Assign a customer-agreed custom delivery window (admin override), reserve capacity, complete fulfilment.
     * Does not create or charge a new gateway payment.
     *
     * @return array{ok: bool, message?: string, idempotent?: bool}
     */
    public static function adminCustomDeliveryAndCompleteFulfilment(
        int $orderId,
        int $exceptionId,
        string $deliveryDate,
        string $customStart,
        string $customEnd,
        int $adminUserId,
        string $confirmNote,
        bool $customerConfirmed
    ): array {
        $confirmNote = trim($confirmNote);
        if (!$customerConfirmed) {
            return ['ok' => false, 'message' => 'Confirm that you have verified this delivery time with the customer.'];
        }
        if (mb_strlen($confirmNote) < 10) {
            return ['ok' => false, 'message' => 'Enter a confirmation note (at least 10 characters).'];
        }
        $startNorm = self::normalizeAdminClockTime($customStart);
        $endNorm = self::normalizeAdminClockTime($customEnd);
        if ($startNorm === null || $endNorm === null) {
            return ['ok' => false, 'message' => 'Enter valid start and end times (HH:MM).'];
        }
        if ($startNorm >= $endNorm) {
            return ['ok' => false, 'message' => 'End time must be after start time.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            return ['ok' => false, 'message' => 'Choose a valid delivery date.'];
        }
        $dateErr = self::validateAdminDeliveryDate($deliveryDate);
        if ($dateErr !== null) {
            return ['ok' => false, 'message' => $dateErr];
        }
        $windowErr = self::validateCustomWindowNotInPast($deliveryDate, $startNorm);
        if ($windowErr !== null) {
            return ['ok' => false, 'message' => $windowErr];
        }
        if (!PaymentCompletionExceptionService::tableReady()) {
            return ['ok' => false, 'message' => 'Reconciliation module is not available.'];
        }

        $windowLabel = DeliveryAdminUi::formatClockTime12h($startNorm) . ' – '
            . DeliveryAdminUi::formatClockTime12h($endNorm);
        $allowCapacityOverride = Permission::can('deliveries.manage');

        $gateway = '';
        $gatewayPaymentId = '';
        $gatewayOrderId = '';
        $idempotencyKey = '';
        $orderNumber = '';
        $amountInr = 0.0;
        $oldDeliveryDate = '';
        $oldSlotId = 0;
        $capacityOverrideUsed = false;
        $capacitySlotId = 0;

        try {
            Database::transaction(static function () use (
                $orderId,
                $exceptionId,
                $deliveryDate,
                $startNorm,
                $endNorm,
                $windowLabel,
                $allowCapacityOverride,
                &$gateway,
                &$gatewayPaymentId,
                &$gatewayOrderId,
                &$idempotencyKey,
                &$orderNumber,
                &$amountInr,
                &$oldDeliveryDate,
                &$oldSlotId,
                &$capacityOverrideUsed,
                &$capacitySlotId
            ): void {
                $pdo = Database::pdo();
                $exStmt = $pdo->prepare(
                    'SELECT * FROM payment_completion_exceptions WHERE id = ? AND order_id = ? AND status = ? FOR UPDATE'
                );
                $exStmt->execute([$exceptionId, $orderId, PaymentCompletionExceptionService::STATUS_OPEN]);
                $exception = $exStmt->fetch(PDO::FETCH_ASSOC);
                if (!$exception) {
                    throw new RuntimeException('Reconciliation case is not open or does not match this order.');
                }

                $ordStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
                $ordStmt->execute([$orderId]);
                $order = $ordStmt->fetch(PDO::FETCH_ASSOC);
                if (!$order) {
                    throw new RuntimeException('Order not found.');
                }
                if ((string) ($order['payment_status'] ?? '') === 'paid') {
                    throw new RuntimeException('Order is already marked paid.');
                }

                $oldDeliveryDate = (string) ($order['delivery_date'] ?? '');
                $oldSlotId = isset($order['delivery_slot_id']) ? (int) $order['delivery_slot_id'] : 0;

                $payStmt = $pdo->prepare(
                    "SELECT * FROM payments WHERE order_id = ? AND status = 'reconciliation_required' ORDER BY id DESC LIMIT 1 FOR UPDATE"
                );
                $payStmt->execute([$orderId]);
                $payment = $payStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    throw new RuntimeException('No captured payment awaiting reconciliation.');
                }

                $resolved = self::resolveCapacitySlotForCustomDelivery(
                    $pdo,
                    $orderId,
                    $deliveryDate,
                    $oldSlotId,
                    $allowCapacityOverride
                );
                $capacitySlotId = (int) $resolved['slot_id'];
                $capacityOverrideUsed = !empty($resolved['capacity_override']);
                if ($capacitySlotId <= 0) {
                    throw new RuntimeException('No delivery slot bucket available for this date.');
                }
                if ($capacityOverrideUsed && !$allowCapacityOverride) {
                    throw new RuntimeException('Slot capacity is full. An admin with delivery override permission is required.');
                }

                $slotRow = DeliverySlotReservationService::lockSlotAndAssertCapacity(
                    $pdo,
                    $capacitySlotId,
                    $deliveryDate,
                    $orderId,
                    true,
                    $capacityOverrideUsed
                );
                $slug = (string) ($slotRow['slug'] ?? '');

                $snapshot = DeliveryOrderSnapshot::fromOrderRow($order);
                $patched = DeliveryOrderSnapshot::applyAdminCustomDeliveryWindow(
                    $snapshot,
                    $slotRow,
                    $deliveryDate,
                    $startNorm,
                    $endNorm,
                    $windowLabel,
                    $capacityOverrideUsed
                );
                $snapJson = json_encode($patched, JSON_UNESCAPED_UNICODE);
                if ($snapJson === false) {
                    throw new RuntimeException('Could not update delivery snapshot.');
                }

                $pdo->prepare(
                    'UPDATE orders SET delivery_slot_id = ?, delivery_date = ?, delivery_slot = ?, delivery_eta = ?,
                     delivery_snapshot_json = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$capacitySlotId, $deliveryDate, $slug, $windowLabel, $snapJson, $orderId]);

                DeliverySlotReservationService::createHold(
                    $pdo,
                    $capacitySlotId,
                    $deliveryDate,
                    $orderId,
                    self::ADMIN_HOLD_MINUTES
                );

                $gateway = (string) ($exception['gateway'] ?? $payment['gateway'] ?? '');
                $gatewayPaymentId = (string) ($exception['gateway_payment_id'] ?? $payment['gateway_payment_id'] ?? '');
                $gatewayOrderId = (string) ($exception['gateway_order_id'] ?? $payment['gateway_order_id'] ?? '');
                $idempotencyKey = (string) ($payment['idempotency_key'] ?? '');
                $orderNumber = (string) ($order['order_number'] ?? '');
                $amountInr = (float) ($exception['captured_amount_inr'] ?? $payment['amount'] ?? $order['grand_total'] ?? 0);

                if ($gatewayPaymentId === '' || $idempotencyKey === '' || $orderNumber === '') {
                    throw new RuntimeException('Payment reference incomplete for recovery.');
                }
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Admin custom delivery fulfilment prep failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not reserve delivery for the custom window.'];
        }

        AuditLog::write('order.custom_delivery_fulfilment', 'orders', (string) $orderId, [
            'exception_id' => $exceptionId,
            'previous_delivery_date' => $oldDeliveryDate,
            'previous_delivery_slot_id' => $oldSlotId > 0 ? $oldSlotId : null,
            'delivery_date' => $deliveryDate,
            'custom_start' => $startNorm,
            'custom_end' => $endNorm,
            'capacity_slot_id' => $capacitySlotId,
            'capacity_override' => $capacityOverrideUsed,
            'confirmation_note' => mb_substr($confirmNote, 0, 500),
        ], $adminUserId > 0 ? $adminUserId : null);

        $eventId = 'admin_custom_recovery:' . $exceptionId . ':' . $deliveryDate . ':' . $startNorm . ':' . $endNorm;
        $result = PaymentService::completePayment(
            $orderNumber,
            $gateway,
            $gatewayPaymentId,
            $gatewayOrderId,
            $amountInr,
            'INR',
            $idempotencyKey,
            $eventId
        );

        if (!$result['ok']) {
            $msg = (string) ($result['message'] ?? 'Fulfilment could not be completed.');
            if (!empty($result['reconciliation'])) {
                $msg = 'Payment is still captured but fulfilment failed again: ' . $msg;
            }

            return ['ok' => false, 'message' => $msg];
        }

        $resolved = PaymentCompletionExceptionService::resolve(
            $exceptionId,
            PaymentCompletionExceptionService::STATUS_RESOLVED_MANUAL,
            'Admin custom delivery window: ' . $confirmNote,
            $adminUserId
        );
        if (!$resolved && empty($result['idempotent'])) {
            error_log('Payment recovered but exception resolve failed for order ' . $orderNumber);
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO order_status_history (order_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
        )->execute([
            $orderId,
            'Order placed',
            'Fulfilment completed after payment reconciliation (custom window '
            . $deliveryDate . ' ' . $windowLabel . ', admin).',
            $adminUserId > 0 ? $adminUserId : null,
        ]);

        PaymentService::clearStorefrontCheckoutSession($orderNumber);

        return [
            'ok' => true,
            'idempotent' => !empty($result['idempotent']),
            'message' => !empty($result['idempotent'])
                ? 'Order was already confirmed (no duplicate fulfilment).'
                : 'Payment captured and order confirmed with the custom delivery window.',
        ];
    }

    /**
     * @return array{slot_id: int, capacity_override: bool}
     */
    private static function resolveCapacitySlotForCustomDelivery(
        PDO $pdo,
        int $orderId,
        string $deliveryDate,
        int $preferSlotId,
        bool $allowCapacityOverride
    ): array {
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $stmt = $pdo->query('SELECT * FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id');
        $bestId = 0;
        $bestRemaining = -1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $slot) {
            if (!DeliverySlotService::isWeekdayAllowed($slot, $deliveryDate)) {
                continue;
            }
            $slotId = (int) $slot['id'];
            $cap = (int) ($slot['capacity'] ?? 0);
            $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $deliveryDate, $orderId);
            $remaining = $cap - $used;
            if ($remaining <= 0) {
                continue;
            }
            $err = DeliverySlotService::assertBookableLocked($pdo, $slot, $deliveryDate, $orderId, true);
            if ($err !== null) {
                continue;
            }
            if ($slotId === $preferSlotId) {
                return ['slot_id' => $slotId, 'capacity_override' => false];
            }
            if ($remaining > $bestRemaining) {
                $bestRemaining = $remaining;
                $bestId = $slotId;
            }
        }
        if ($bestId > 0) {
            return ['slot_id' => $bestId, 'capacity_override' => false];
        }
        if (!$allowCapacityOverride) {
            return ['slot_id' => 0, 'capacity_override' => false];
        }
        $fallbackId = 0;
        if ($preferSlotId > 0) {
            $chk = $pdo->prepare('SELECT id FROM delivery_slots WHERE id = ? AND is_active = 1 LIMIT 1');
            $chk->execute([$preferSlotId]);
            $fallbackId = (int) ($chk->fetchColumn() ?: 0);
        }
        if ($fallbackId <= 0) {
            $fallbackId = (int) ($pdo->query('SELECT id FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1')->fetchColumn() ?: 0);
        }

        return ['slot_id' => $fallbackId, 'capacity_override' => $fallbackId > 0];
    }

    private static function normalizeAdminClockTime(string $time): ?string
    {
        $time = trim($time);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $h, $min);
    }

    private static function validateAdminDeliveryDate(string $deliveryDate): ?string
    {
        $today = DeliveryConfig::now()->format('Y-m-d');
        if ($deliveryDate < $today) {
            return 'Delivery date cannot be in the past.';
        }
        try {
            $max = DeliveryConfig::now()->modify('+60 days')->format('Y-m-d');
        } catch (Throwable $e) {
            $max = $today;
        }
        if ($deliveryDate > $max) {
            return 'Delivery date is too far in the future.';
        }

        return null;
    }

    private static function validateCustomWindowNotInPast(string $deliveryDate, string $startTime): ?string
    {
        try {
            $tz = new DateTimeZone(DeliveryConfig::timezone());
            $start = new DateTimeImmutable($deliveryDate . ' ' . $startTime, $tz);
        } catch (Throwable $e) {
            return 'Invalid delivery date or time.';
        }
        if ($start < DeliveryConfig::now()) {
            return 'Custom delivery time cannot be in the past.';
        }

        return null;
    }
}
