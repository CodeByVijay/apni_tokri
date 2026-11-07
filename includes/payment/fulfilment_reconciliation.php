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
            $out[] = [
                'id' => $slotId,
                'slug' => (string) $slot['slug'],
                'label' => (string) $slot['label'],
                'window' => (string) $slot['window_label'],
                'delivery_date' => $date,
                'remaining' => $remaining,
            ];
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
}
