<?php

declare(strict_types=1);

/**
 * Customer-facing order detail view model (read-only; does not mutate orders).
 */
final class CustomerOrderDetailService
{
    /**
     * @param array<string, mixed> $orderRow Full orders table row (ownership already verified).
     * @param array<string, mixed> $base Output from OrderRepository::mapFull().
     * @return array<string, mixed>
     */
    public static function enrich(array $orderRow, array $base, int $customerId): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $orderId = (int) $orderRow['id'];

        $base['overview'] = self::overview($orderRow, $base);
        $base['payments'] = self::paymentsForOrder($orderId);
        $base['payment_summary'] = self::paymentSummary($orderRow, $base['payments']);
        $base['address'] = self::addressBlock($orderRow, $pdo);
        $base['delivery'] = self::deliveryBlock($orderRow, $pdo);
        $base['price'] = self::priceBreakdown($orderRow, $base['payments']);
        $base['meta'] = self::metaBlock($orderRow, $pdo);
        $base['timeline'] = CustomerOrderTimeline::build($orderRow, $base['payments'], $pdo);

        return $base;
    }

    /**
     * @param array<string, mixed> $orderRow
     * @param array<string, mixed> $base
     */
    private static function overview(array $orderRow, array $base): array
    {
        $status = (string) ($orderRow['status'] ?? '');
        $paymentStatus = (string) ($orderRow['payment_status'] ?? '');
        $placedAt = (string) ($orderRow['placed_at'] ?? $orderRow['created_at'] ?? '');
        $deliveryDate = trim((string) ($orderRow['delivery_date'] ?? ''));
        $eta = trim((string) ($orderRow['delivery_eta'] ?? ''));
        $expected = $deliveryDate !== '' ? $deliveryDate : ($eta !== '' ? $eta : null);

        return [
            'order_number' => (string) ($orderRow['order_number'] ?? ''),
            'placed_at' => $placedAt,
            'status' => $status,
            'status_public' => (string) ($base['status'] ?? $status),
            'payment_status' => $paymentStatus,
            'payment_status_label' => self::orderPaymentStatusLabel($paymentStatus),
            'order_type' => self::orderTypeLabel((string) ($orderRow['order_source'] ?? '')),
            'expected_delivery' => $expected,
            'currency' => (string) ($orderRow['currency'] ?? 'INR'),
        ];
    }

    public static function orderPaymentStatusLabel(string $paymentStatus): string
    {
        switch ($paymentStatus) {
            case 'paid':
                return 'Paid';
            case 'pending':
                return 'Pending';
            case 'failed':
                return 'Failed';
            case 'refunded':
                return 'Refunded';
            case 'partially_refunded':
                return 'Partially refunded';
            default:
                return $paymentStatus !== '' ? ucwords(str_replace('_', ' ', $paymentStatus)) : '—';
        }
    }

    public static function orderTypeLabel(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }
        if ($source === 'subscription') {
            return 'Subscription delivery';
        }
        if ($source === 'storefront') {
            return 'Online order';
        }

        return ucwords(str_replace('_', ' ', $source));
    }

    /** @return list<array<string, mixed>> */
    public static function paymentsForOrder(int $orderId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, gateway, gateway_order_id, gateway_payment_id, amount, amount_refunded_inr,
                    currency, status, captured_at, metadata_json, created_at, updated_at
             FROM payments WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = [];
            if (!empty($row['metadata_json'])) {
                try {
                    $decoded = json_decode((string) $row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                } catch (Throwable $e) {
                    $meta = [];
                }
            }
            $gateway = (string) ($row['gateway'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $paidAt = (string) ($row['captured_at'] ?? '');
            if ($paidAt === '' && $status === 'captured') {
                $paidAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
            }
            $method = null;
            foreach (['method', 'payment_method', 'payment_group'] as $mk) {
                if (!empty($meta[$mk]) && is_string($meta[$mk])) {
                    $method = (string) $meta[$mk];
                    break;
                }
            }

            $out[] = [
                'gateway' => $gateway,
                'gateway_label' => PaymentGatewayConfig::gatewayLabel($gateway),
                'status' => $status,
                'status_label' => self::paymentRowStatusLabel($status),
                'amount' => round((float) ($row['amount'] ?? 0), 2),
                'amount_refunded' => round((float) ($row['amount_refunded_inr'] ?? 0), 2),
                'currency' => (string) ($row['currency'] ?? 'INR'),
                'gateway_order_id' => self::customerSafeId((string) ($row['gateway_order_id'] ?? '')),
                'gateway_payment_id' => self::customerSafeId((string) ($row['gateway_payment_id'] ?? '')),
                'paid_at' => $paidAt,
                'payment_method' => $method,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    public static function paymentRowStatusLabel(string $status): string
    {
        switch ($status) {
            case 'captured':
                return 'Paid';
            case 'created':
            case 'authorized':
                return 'Pending';
            case 'failed':
                return 'Failed';
            case 'refunded':
                return 'Refunded';
            default:
                return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '—';
        }
    }

    private static function customerSafeId(string $id): ?string
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if (strlen($id) > 64 && preg_match('/^[A-Za-z0-9_\-]+$/', $id) !== 1) {
            return substr($id, 0, 8) . '…';
        }

        return $id;
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>
     */
    private static function paymentSummary(array $orderRow, array $payments): array
    {
        $primary = null;
        foreach (array_reverse($payments) as $p) {
            if (($p['status'] ?? '') === 'captured') {
                $primary = $p;
                break;
            }
        }
        if ($primary === null && $payments !== []) {
            $primary = $payments[count($payments) - 1];
        }

        $orderPay = (string) ($orderRow['payment_status'] ?? '');
        $source = (string) ($orderRow['order_source'] ?? '');

        if ($primary === null && $orderPay === 'paid' && $source === 'subscription') {
            return [
                'has_record' => false,
                'gateway_label' => 'Subscription (prepaid)',
                'status_label' => 'Paid',
                'amount' => round((float) ($orderRow['grand_total'] ?? 0), 2),
                'message' => 'This delivery is part of your active subscription.',
            ];
        }

        if ($primary === null) {
            return [
                'has_record' => false,
                'gateway_label' => null,
                'status_label' => self::orderPaymentStatusLabel($orderPay),
                'amount' => round((float) ($orderRow['grand_total'] ?? 0), 2),
                'message' => $orderPay === 'pending' || $orderPay === ''
                    ? 'Payment details will appear here once a payment is recorded.'
                    : 'Detailed payment metadata is not available for this order.',
            ];
        }

        return [
            'has_record' => true,
            'gateway_label' => (string) $primary['gateway_label'],
            'status_label' => (string) $primary['status_label'],
            'amount' => (float) $primary['amount'],
            'gateway_order_id' => $primary['gateway_order_id'],
            'gateway_payment_id' => $primary['gateway_payment_id'],
            'paid_at' => (string) ($primary['paid_at'] ?? ''),
            'payment_method' => $primary['payment_method'],
            'amount_refunded' => (float) ($primary['amount_refunded'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function addressBlock(array $orderRow, PDO $pdo): ?array
    {
        $snap = DeliveryAddressSnapshot::forOrder($orderRow, $pdo);
        if ($snap === null) {
            return null;
        }
        $courier = DeliveryAddressSnapshot::courierPayload($snap);
        $state = trim((string) ($snap['state'] ?? ''));

        return [
            'receiver_name' => (string) ($courier['receiver_name'] ?? ''),
            'receiver_phone' => (string) ($courier['receiver_phone'] ?? ''),
            'alternate_phone' => $courier['alternate_phone'] ?? null,
            'lines' => $courier['address_lines'],
            'landmark' => $courier['landmark'] ?? null,
            'city' => (string) ($snap['city'] ?? ''),
            'state' => $state !== '' ? $state : null,
            'pincode' => (string) ($courier['pincode'] ?? ''),
            'delivery_instructions' => $courier['delivery_instructions'] ?? null,
            'formatted' => AddressInput::fullAddress(array_merge($snap, [
                'line1' => $snap['line1'] ?? implode(', ', $courier['address_lines']),
            ])),
            'map_link' => $courier['map_link'],
            'location_confirmed' => !empty($courier['location_confirmed']),
            'legacy' => !empty($snap['legacy_order']),
        ];
    }

    /** @return array<string, mixed> */
    public static function deliveryBlock(array $orderRow, PDO $pdo): array
    {
        $slotId = (int) ($orderRow['delivery_slot_id'] ?? 0);
        $slotSlug = trim((string) ($orderRow['delivery_slot'] ?? ''));
        $slotLabel = $slotSlug;
        $windowLabel = null;
        $timeRange = null;

        if ($slotId > 0) {
            $st = $pdo->prepare(
                'SELECT label, window_label, start_time, end_time FROM delivery_slots WHERE id = ? LIMIT 1'
            );
            $st->execute([$slotId]);
            $slotRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($slotRow) {
                $slotLabel = (string) ($slotRow['label'] ?? $slotLabel);
                $windowLabel = (string) ($slotRow['window_label'] ?? '');
                $timeRange = self::formatSlotTimes(
                    (string) ($slotRow['start_time'] ?? ''),
                    (string) ($slotRow['end_time'] ?? '')
                );
            }
        } elseif ($slotSlug !== '') {
            $st = $pdo->prepare(
                'SELECT label, window_label, start_time, end_time FROM delivery_slots WHERE slug = ? LIMIT 1'
            );
            $st->execute([$slotSlug]);
            $slotRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($slotRow) {
                $slotLabel = (string) ($slotRow['label'] ?? $slotSlug);
                $windowLabel = (string) ($slotRow['window_label'] ?? '');
                $timeRange = self::formatSlotTimes(
                    (string) ($slotRow['start_time'] ?? ''),
                    (string) ($slotRow['end_time'] ?? '')
                );
            }
        }

        $deliveryDate = trim((string) ($orderRow['delivery_date'] ?? ''));
        $eta = trim((string) ($orderRow['delivery_eta'] ?? ''));

        $delStmt = $pdo->prepare(
            'SELECT status, driver_name, delivery_person_phone, dispatched_at, delivered_at FROM deliveries WHERE order_id = ? LIMIT 1'
        );
        $delStmt->execute([(int) $orderRow['id']]);
        $del = $delStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $deliveryStatus = null;
        $contactPhone = null;
        if ($del !== null) {
            $dst = DeliveryStatus::normalize((string) ($del['status'] ?? ''));
            $deliveryStatus = DeliveryStatus::publicLabel($dst);
            if ($dst === DeliveryStatus::DISPATCHED) {
                $phone = trim((string) ($del['delivery_person_phone'] ?? ''));
                if ($phone !== '') {
                    $contactPhone = $phone;
                }
            }
        }

        $hasSlot = $slotLabel !== '' || $deliveryDate !== '' || $eta !== '';

        return [
            'has_slot' => $hasSlot,
            'slot_label' => $slotLabel !== '' ? $slotLabel : null,
            'window_label' => $windowLabel !== '' ? $windowLabel : null,
            'time_range' => $timeRange,
            'delivery_date' => $deliveryDate !== '' ? $deliveryDate : null,
            'delivery_eta' => $eta !== '' ? $eta : null,
            'delivery_status' => $deliveryStatus,
            'delivery_contact_phone' => $contactPhone,
        ];
    }

    private static function formatSlotTimes(string $start, string $end): ?string
    {
        $start = trim($start);
        $end = trim($end);
        if ($start === '' && $end === '') {
            return null;
        }
        $fmt = static function (string $t): string {
            $ts = strtotime($t);

            return $ts !== false ? date('g:i A', $ts) : $t;
        };
        if ($start !== '' && $end !== '') {
            return $fmt($start) . ' – ' . $fmt($end);
        }

        return $start !== '' ? $fmt($start) : $fmt($end);
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @return array<string, mixed>
     */
    public static function priceBreakdown(array $orderRow, array $payments): array
    {
        $subtotal = round((float) ($orderRow['subtotal'] ?? 0), 2);
        $discount = round((float) ($orderRow['discount_total'] ?? 0), 2);
        $deliveryFee = round((float) ($orderRow['delivery_fee'] ?? 0), 2);
        $grand = round((float) ($orderRow['grand_total'] ?? 0), 2);

        $paid = 0.0;
        $refunded = 0.0;
        foreach ($payments as $p) {
            if (($p['status'] ?? '') === 'captured') {
                $paid += (float) $p['amount'];
            }
            $refunded += (float) ($p['amount_refunded'] ?? 0);
        }
        $paid = round($paid, 2);
        $refunded = round($refunded, 2);

        $orderPay = (string) ($orderRow['payment_status'] ?? '');
        if ($paid <= 0 && $orderPay === 'paid' && $grand > 0) {
            $paid = $grand;
        }

        $outstanding = 0.0;
        if ($orderPay === 'pending' || $orderPay === 'failed') {
            $outstanding = max(0, round($grand - $paid, 2));
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon_code' => $orderRow['coupon_code'] ?? null,
            'delivery_fee' => $deliveryFee,
            'tax' => null,
            'grand_total' => $grand,
            'amount_paid' => $paid,
            'amount_refunded' => $refunded,
            'outstanding' => $outstanding,
        ];
    }

    /** @return array<string, mixed> */
    private static function metaBlock(array $orderRow, PDO $pdo): array
    {
        $cancellationReason = null;
        if ((string) ($orderRow['status'] ?? '') === OrderStatus::CANCELLED) {
            $evt = OrderCancellationService::cancellationEventForOrder((int) $orderRow['id']);
            if ($evt !== null) {
                $code = (string) ($evt['reason_code'] ?? '');
                $label = OrderCancellationService::CUSTOMER_REASONS[$code] ?? $code;
                $detail = trim((string) ($evt['reason_detail'] ?? ''));
                $cancellationReason = $label . ($detail !== '' ? ' — ' . $detail : '');
            } else {
                $st = $pdo->prepare(
                    'SELECT note FROM order_status_history WHERE order_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1'
                );
                $st->execute([(int) $orderRow['id'], OrderStatus::CANCELLED]);
                $note = trim((string) ($st->fetchColumn() ?: ''));
                if ($note !== '') {
                    $cancellationReason = $note;
                }
            }
        }

        return [
            'coupon_code' => $orderRow['coupon_code'] ?? null,
            'order_source' => self::orderTypeLabel((string) ($orderRow['order_source'] ?? '')),
            'invoice_available' => false,
            'cancellation_reason' => $cancellationReason,
        ];
    }
}

final class CustomerOrderTimeline
{
    /** @return list<array{status: string, at: string, done: bool}> */
    public static function build(array $orderRow, array $payments, PDO $pdo): array
    {
        $orderId = (int) $orderRow['id'];
        /** @var list<array{status: string, at: string, done: bool, key: string, source: string}> $events */
        $events = [];

        $placedAt = (string) ($orderRow['placed_at'] ?? $orderRow['created_at'] ?? '');
        if ($placedAt !== '') {
            $events[] = self::event('Order placed', $placedAt, 'order.placed', 'order');
        }

        foreach ($payments as $p) {
            $gw = (string) ($p['gateway_label'] ?? 'Payment');
            $payKey = (string) ($p['gateway_payment_id'] ?? $p['created_at'] ?? 'unknown');
            if (($p['status'] ?? '') === 'captured' && !empty($p['paid_at'])) {
                $events[] = self::event(
                    'Payment received (' . $gw . ')',
                    (string) $p['paid_at'],
                    'payment.captured.' . $payKey,
                    'payment'
                );
            } elseif (($p['status'] ?? '') === 'failed') {
                $at = (string) ($p['created_at'] ?? '');
                if ($at !== '') {
                    $events[] = self::event(
                        'Payment failed (' . $gw . ')',
                        $at,
                        'payment.failed.' . $at . '.' . $payKey,
                        'payment'
                    );
                }
            }
        }

        $hist = $pdo->prepare(
            'SELECT status, note, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at ASC, id ASC'
        );
        $hist->execute([$orderId]);
        foreach ($hist->fetchAll(PDO::FETCH_ASSOC) ?: [] as $h) {
            $statusRaw = (string) ($h['status'] ?? '');
            $note = (string) ($h['note'] ?? '');
            $label = self::historyLabel($statusRaw, $note);
            $at = (string) ($h['created_at'] ?? '');
            if ($label === '' || $at === '') {
                continue;
            }
            $events[] = self::event(
                $label,
                $at,
                self::orderHistorySemanticKey($statusRaw, $note),
                'order_history'
            );
        }

        $deliveryCtx = self::appendDeliveryHistoryEvents($orderId, $pdo, $events);

        $refStmt = $pdo->prepare(
            'SELECT status, created_at, updated_at FROM refund_requests WHERE order_id = ? ORDER BY created_at ASC'
        );
        $refStmt->execute([$orderId]);
        foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $rr) {
            $st = (string) ($rr['status'] ?? '');
            $label = RefundRequestService::publicStatusLabel($st);
            $at = (string) ($rr['updated_at'] ?? $rr['created_at'] ?? '');
            if ($st === RefundStatus::REFUNDED || $st === RefundStatus::REFUND_INITIATED) {
                if ($at !== '') {
                    $events[] = self::event($label, $at, 'refund.' . $st . '.' . $at, 'refund');
                }
            } elseif ($st === RefundStatus::REQUESTED && $at !== '') {
                $events[] = self::event($label, $at, 'refund.' . $st . '.' . $at, 'refund');
            }
        }

        if ($deliveryCtx !== null && ($deliveryCtx['status'] ?? '') === DeliveryStatus::DELIVERED) {
            $deliveredAt = trim((string) ($deliveryCtx['delivered_at'] ?? ''));
            if ($deliveredAt !== '' && !self::hasSemanticKey($events, 'milestone.delivered')) {
                $events[] = self::event(
                    DeliveryStatus::publicLabel(DeliveryStatus::DELIVERED),
                    $deliveredAt,
                    'milestone.delivered',
                    'delivered_at'
                );
            }
        }

        $events = self::dedupeEvents($events);
        usort($events, static function (array $a, array $b): int {
            return strcmp($a['at'], $b['at']);
        });

        $hasDeliveredMilestone = self::hasSemanticKey($events, 'milestone.delivered');
        $events = self::stripInternalFields($events);

        $status = (string) ($orderRow['status'] ?? '');
        $paymentStatus = (string) ($orderRow['payment_status'] ?? '');
        $orderTerminal = in_array($status, [
            OrderStatus::DELIVERED,
            OrderStatus::CANCELLED,
            OrderStatus::REFUNDED,
            OrderStatus::PARTIALLY_REFUNDED,
            OrderStatus::PAYMENT_FAILED,
        ], true);

        $hidePending = $orderTerminal || self::shouldHidePendingDeliveryMilestones($deliveryCtx, $hasDeliveredMilestone);

        if ($events === [] && !$orderTerminal && !self::deliveryIsDelivered($deliveryCtx)) {
            $events[] = ['status' => 'Order received', 'at' => '', 'done' => true];
        }

        if (!$hidePending && $paymentStatus !== 'failed') {
            foreach (self::pendingMilestones($status) as $pending) {
                $events[] = ['status' => $pending, 'at' => '', 'done' => false];
            }
        }

        return $events;
    }

    /**
     * @param list<array{status: string, at: string, done: bool, key: string, source: string}> $events
     * @return array{status: string, delivered_at: string|null}|null
     */
    private static function appendDeliveryHistoryEvents(int $orderId, PDO $pdo, array &$events): ?array
    {
        $delStmt = $pdo->prepare('SELECT id, status, delivered_at FROM deliveries WHERE order_id = ? LIMIT 1');
        $delStmt->execute([$orderId]);
        $delRow = $delStmt->fetch(PDO::FETCH_ASSOC);
        if (!$delRow) {
            return null;
        }
        $deliveryId = (int) ($delRow['id'] ?? 0);
        if ($deliveryId <= 0) {
            return null;
        }

        $hasVisible = DeliveryAssignmentService::historyExtendedReady($pdo);
        if ($hasVisible) {
            $stmt = $pdo->prepare(
                'SELECT id, status, public_label, created_at FROM delivery_status_history
                 WHERE delivery_id = ? AND customer_visible = 1 ORDER BY created_at ASC, id ASC'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, status, created_at FROM delivery_status_history WHERE delivery_id = ? ORDER BY created_at ASC, id ASC'
            );
        }
        $stmt->execute([$deliveryId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $h) {
            $histId = (int) ($h['id'] ?? 0);
            $st = DeliveryStatus::normalize((string) ($h['status'] ?? ''));
            $at = (string) ($h['created_at'] ?? '');
            if ($at === '') {
                continue;
            }
            $label = $hasVisible && !empty($h['public_label'])
                ? (string) $h['public_label']
                : DeliveryStatus::publicLabel($st);
            if ($st === DeliveryStatus::FAILED) {
                $label = 'We could not complete this delivery. Our team will contact you.';
            }
            $events[] = self::event(
                $label,
                $at,
                self::deliveryHistorySemanticKey($st, $histId),
                'delivery_history'
            );
        }

        return [
            'status' => DeliveryStatus::normalize((string) ($delRow['status'] ?? '')),
            'delivered_at' => ($delRow['delivered_at'] ?? null) !== null && (string) $delRow['delivered_at'] !== ''
                ? (string) $delRow['delivered_at']
                : null,
        ];
    }

    private static function shouldHidePendingDeliveryMilestones(?array $deliveryCtx, bool $hasDeliveredMilestone): bool
    {
        if (self::deliveryIsDelivered($deliveryCtx)) {
            return true;
        }
        if ($deliveryCtx !== null && ($deliveryCtx['status'] ?? '') === DeliveryStatus::CANCELLED) {
            return true;
        }

        return $hasDeliveredMilestone;
    }

    /** @param array{status: string, delivered_at: string|null}|null $deliveryCtx */
    private static function deliveryIsDelivered(?array $deliveryCtx): bool
    {
        return $deliveryCtx !== null && ($deliveryCtx['status'] ?? '') === DeliveryStatus::DELIVERED;
    }

    private static function deliveryHistorySemanticKey(string $normalizedStatus, int $historyId): string
    {
        if ($normalizedStatus === DeliveryStatus::DELIVERED) {
            return 'milestone.delivered';
        }
        if ($normalizedStatus === DeliveryStatus::DISPATCHED) {
            return 'milestone.out_for_delivery';
        }
        if ($normalizedStatus === DeliveryStatus::FAILED) {
            return 'delivery.attempt.failed.' . $historyId;
        }

        return 'delivery.status.' . $normalizedStatus . '.' . $historyId;
    }

    private static function orderHistorySemanticKey(string $statusRaw, string $note): string
    {
        if ($note !== '' && stripos($note, 'payment') !== false && strlen($note) < 120) {
            return 'payment.received.server';
        }

        $norm = self::normalizeOrderHistoryToken($statusRaw);
        if ($norm === 'order_placed' || $norm === 'placed') {
            return 'order.placed';
        }
        if ($norm === OrderStatus::DELIVERED) {
            return 'milestone.delivered';
        }
        if ($norm === OrderStatus::OUT_FOR_DELIVERY) {
            return 'milestone.out_for_delivery';
        }
        if ($norm === OrderStatus::CONFIRMED) {
            return 'order.status.confirmed';
        }
        if ($norm === OrderStatus::PREPARING) {
            return 'order.status.preparing';
        }
        if ($norm === OrderStatus::READY) {
            return 'order.status.ready';
        }
        if ($norm === OrderStatus::CANCELLED) {
            return 'order.status.cancelled';
        }
        if ($norm === OrderStatus::PAYMENT_FAILED) {
            return 'order.status.payment_failed';
        }

        return 'order.history.' . ($norm !== '' ? $norm : 'row') . '.' . substr(sha1($statusRaw . '|' . $note), 0, 10);
    }

    private static function normalizeOrderHistoryToken(string $status): string
    {
        $s = str_replace(' ', '_', strtolower(trim($status)));
        if ($s === 'order_placed') {
            return 'order_placed';
        }
        if (in_array($s, OrderStatus::all(), true)) {
            return $s;
        }

        return $s;
    }

    /**
     * @param list<array{status: string, at: string, done: bool, key: string, source: string}> $events
     */
    private static function hasSemanticKey(array $events, string $key): bool
    {
        foreach ($events as $e) {
            if (($e['key'] ?? '') === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status: string, at: string, done: bool, key: string, source: string}
     */
    private static function event(string $label, string $at, string $semanticKey, string $source): array
    {
        return [
            'status' => $label,
            'at' => $at,
            'done' => true,
            'key' => $semanticKey,
            'source' => $source,
        ];
    }

    private static function historyLabel(string $status, string $note): string
    {
        $status = trim($status);
        if ($status === '') {
            return $note !== '' ? $note : '';
        }
        if ($note !== '' && strlen($note) < 80 && stripos($note, 'payment') !== false) {
            return $note;
        }
        $norm = str_replace(' ', '_', strtolower($status));
        if (in_array($norm, OrderStatus::all(), true)) {
            return ucwords(str_replace('_', ' ', $norm));
        }

        return $status;
    }

    /**
     * @param list<array{status: string, at: string, done: bool, key: string, source: string}> $events
     * @return list<array{status: string, at: string, done: bool, key: string, source: string}>
     */
    private static function dedupeEvents(array $events): array
    {
        /** @var array<string, array{status: string, at: string, done: bool, key: string, source: string}> $byKey */
        $byKey = [];
        foreach ($events as $e) {
            $key = (string) ($e['key'] ?? '');
            if ($key === '') {
                $key = 'unknown.' . substr(sha1($e['status'] . $e['at']), 0, 12);
                $e['key'] = $key;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = $e;
                continue;
            }
            $byKey[$key] = self::preferEvent($byKey[$key], $e, $key);
        }

        if (isset($byKey['payment.received.server'])) {
            foreach (array_keys($byKey) as $k) {
                if (strpos($k, 'payment.captured.') === 0) {
                    unset($byKey['payment.received.server']);
                    break;
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @param array{status: string, at: string, done: bool, key: string, source: string} $a
     * @param array{status: string, at: string, done: bool, key: string, source: string} $b
     * @return array{status: string, at: string, done: bool, key: string, source: string}
     */
    private static function preferEvent(array $a, array $b, string $key): array
    {
        if ($key === 'milestone.delivered') {
            return self::pickDeliveredEvent($a, $b);
        }
        if ($key === 'payment.received.server') {
            return self::sourceRank($a) <= self::sourceRank($b) ? $a : $b;
        }
        if ($key === 'order.placed') {
            return strcmp($a['at'], $b['at']) <= 0 ? $a : $b;
        }

        return self::sourceRank($a) <= self::sourceRank($b) ? $a : $b;
    }

    /**
     * @param array{status: string, at: string, done: bool, key: string, source: string} $a
     * @param array{status: string, at: string, done: bool, key: string, source: string} $b
     * @return array{status: string, at: string, done: bool, key: string, source: string}
     */
    private static function pickDeliveredEvent(array $a, array $b): array
    {
        $rank = static function (array $e): int {
            $src = (string) ($e['source'] ?? '');
            if ($src === 'delivered_at') {
                return 0;
            }
            if ($src === 'delivery_history') {
                return 1;
            }
            if ($src === 'order_history') {
                return 2;
            }

            return 3;
        };
        $ra = $rank($a);
        $rb = $rank($b);
        if ($ra !== $rb) {
            return $ra < $rb ? $a : $b;
        }

        return strcmp($a['at'], $b['at']) <= 0 ? $a : $b;
    }

    /** @param array{status: string, at: string, done: bool, key: string, source: string} $e */
    private static function sourceRank(array $e): int
    {
        $src = (string) ($e['source'] ?? '');
        if ($src === 'payment') {
            return 0;
        }
        if ($src === 'delivery_history') {
            return 1;
        }
        if ($src === 'delivered_at') {
            return 2;
        }
        if ($src === 'order_history') {
            return 3;
        }

        return 4;
    }

    /**
     * @param list<array{status: string, at: string, done: bool, key?: string, source?: string}> $events
     * @return list<array{status: string, at: string, done: bool}>
     */
    private static function stripInternalFields(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            $out[] = [
                'status' => (string) ($e['status'] ?? ''),
                'at' => (string) ($e['at'] ?? ''),
                'done' => !empty($e['done']),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function pendingMilestones(string $orderStatus): array
    {
        $flow = [
            OrderStatus::CONFIRMED => 'Order confirmed',
            OrderStatus::PREPARING => 'Order processing',
            OrderStatus::READY => 'Packed & ready',
            OrderStatus::OUT_FOR_DELIVERY => 'Out for delivery',
            OrderStatus::DELIVERED => 'Delivered',
        ];
        $order = [
            OrderStatus::CONFIRMED,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED,
        ];
        $idx = array_search($orderStatus, $order, true);
        if ($idx === false) {
            if ($orderStatus === OrderStatus::PENDING_PAYMENT) {
                return ['Order confirmed', 'Order processing', 'Out for delivery', 'Delivered'];
            }

            return [];
        }
        $pending = [];
        for ($i = $idx + 1, $n = count($order); $i < $n; $i++) {
            $pending[] = $flow[$order[$i]] ?? ucwords(str_replace('_', ' ', $order[$i]));
        }

        return $pending;
    }
}
