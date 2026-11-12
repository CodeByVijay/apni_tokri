<?php

declare(strict_types=1);

/**
 * Read-only admin order detail view model (display only; no mutations).
 */
final class AdminOrderDetailService
{
    /**
     * @param array<string, mixed> $orderRow Raw orders row from findById query
     * @param array<string, mixed> $base Existing AdminOrderService::findById payload
     * @return array<string, mixed>
     */
    public static function enrich(array $orderRow, array $base): array
    {
        $orderId = (int) ($base['id'] ?? 0);
        if ($orderId <= 0) {
            return $base;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();

        $payments = CustomerOrderDetailService::paymentsForOrder($orderId);
        $base['payments'] = self::adminPaymentRows($orderId);
        $base['customer_id'] = isset($orderRow['customer_id']) ? (int) $orderRow['customer_id'] : null;
        $base['address'] = CustomerOrderDetailService::addressBlock($orderRow, $pdo);
        $base['delivery'] = self::deliveryFulfilmentBlock($orderRow, $pdo);
        $base['pricing'] = CustomerOrderDetailService::priceBreakdown($orderRow, $payments);
        $base['order_source_label'] = self::orderSourceLabel((string) ($orderRow['order_source'] ?? ''));
        $base['overview'] = self::overviewBlock($base, $orderRow);
        $base['refunds'] = self::refundsForOrder($orderId);
        $base['cancellation_refund'] = RefundRequestService::adminOrderCancellationRefundBlock($orderId);
        $base['reschedule_notes'] = self::rescheduleNotesFromHistory($base['history'] ?? []);
        $base['payment_method_primary'] = self::primaryPaymentMethodLabel($base['payments']);
        if ((string) ($orderRow['order_source'] ?? '') === 'admin_assisted') {
            $base['assisted'] = self::assistedOrderBlock($orderRow, $base, $pdo);
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $orderRow
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private static function assistedOrderBlock(array $orderRow, array $base, PDO $pdo): array
    {
        $adminId = isset($orderRow['created_by_admin_id']) ? (int) $orderRow['created_by_admin_id'] : 0;
        $adminName = '—';
        if ($adminId > 0) {
            $stmt = $pdo->prepare('SELECT name FROM admin_users WHERE id = ? LIMIT 1');
            $stmt->execute([$adminId]);
            $adminName = (string) ($stmt->fetchColumn() ?: '—');
        }
        $meta = AdminAssistedOrderService::assistedMetaForDisplay(
            isset($orderRow['admin_meta_json']) && is_string($orderRow['admin_meta_json'])
                ? $orderRow['admin_meta_json'] : null
        );
        $pricing = $base['pricing'] ?? [];
        $payUrl = OrderEmailContext::customerPaymentRetryUrl((string) ($orderRow['order_number'] ?? ''));
        $canResend = in_array((string) ($orderRow['payment_status'] ?? ''), ['pending', 'failed'], true)
            && (string) ($orderRow['status'] ?? '') !== OrderStatus::CANCELLED;

        return [
            'created_by_admin_id' => $adminId > 0 ? $adminId : null,
            'created_by_admin_name' => $adminName,
            'admin_meta' => $meta,
            'customer_payment_url' => $payUrl,
            'can_resend_payment_email' => $canResend && AdminAssistedOrderService::canCreateOnBehalf(),
            'custom_discount' => $meta['custom_discount'] ?? null,
            'delivery_override' => $meta['delivery'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function adminPaymentRows(int $orderId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, idempotency_key, gateway, gateway_order_id, gateway_payment_id, amount, amount_refunded_inr,
                    currency, status, captured_at, metadata_json, created_at, updated_at
             FROM payments WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $meta = self::decodeMetadata($row['metadata_json'] ?? null);
            $status = (string) ($row['status'] ?? '');
            $method = null;
            foreach (['method', 'payment_method', 'payment_group'] as $mk) {
                if (!empty($meta[$mk]) && is_string($meta[$mk])) {
                    $method = (string) $meta[$mk];
                    break;
                }
            }
            $paidAt = (string) ($row['captured_at'] ?? '');
            if ($paidAt === '' && $status === 'captured') {
                $paidAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
            }
            $gw = (string) ($row['gateway'] ?? '');
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'gateway' => $gw,
                'gateway_label' => PaymentGatewayConfig::gatewayLabel($gw),
                'status' => $status,
                'admin_status_label' => PaymentService::paymentAttemptStatusLabel($status),
                'amount' => round((float) ($row['amount'] ?? 0), 2),
                'amount_refunded' => round((float) ($row['amount_refunded_inr'] ?? 0), 2),
                'currency' => (string) ($row['currency'] ?? 'INR'),
                'gateway_order_id' => trim((string) ($row['gateway_order_id'] ?? '')),
                'gateway_payment_id' => trim((string) ($row['gateway_payment_id'] ?? '')),
                'idempotency_key' => trim((string) ($row['idempotency_key'] ?? '')),
                'payment_method' => $method,
                'paid_at' => $paidAt,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'upi_hint' => self::metaString($meta, ['vpa', 'upi_vpa', 'customer_vpa']),
                'bank_reference' => self::metaString($meta, ['bank_reference', 'utr', 'bank_txn_id', 'reference_id']),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function deliveryFulfilmentBlock(array $orderRow, PDO $pdo): array
    {
        $del = CustomerOrderDetailService::deliveryBlock($orderRow, $pdo);
        $stmt = $pdo->prepare(
            'SELECT id, status, driver_name, delivery_person_phone, dispatched_at, delivered_at
             FROM deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([(int) ($orderRow['id'] ?? 0)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row !== null) {
            $del['delivery_id'] = (int) ($row['id'] ?? 0);
            $del['driver_name'] = trim((string) ($row['driver_name'] ?? '')) !== ''
                ? (string) $row['driver_name']
                : null;
            $del['dispatched_at'] = !empty($row['dispatched_at']) ? (string) $row['dispatched_at'] : null;
            if (!empty($row['delivered_at'])) {
                $del['delivered_at'] = (string) $row['delivered_at'];
            }
            $dst = DeliveryStatus::normalize((string) ($row['status'] ?? ''));
            $del['fulfilment_status'] = DeliveryStatus::publicLabel($dst);
            $del['fulfilment_status_code'] = $dst;
        } else {
            $del['fulfilment_status'] = AdminOrderService::statusLabel((string) ($orderRow['status'] ?? ''));
            $del['fulfilment_status_code'] = (string) ($orderRow['status'] ?? '');
        }

        return $del;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{at: string, note: string, admin_name: ?string}>
     */
    private static function rescheduleNotesFromHistory(array $history): array
    {
        $out = [];
        foreach ($history as $row) {
            $note = trim((string) ($row['note'] ?? ''));
            if ($note === '' || stripos($note, 'reschedule') === false) {
                continue;
            }
            $out[] = [
                'at' => (string) ($row['created_at'] ?? ''),
                'note' => $note,
                'admin_name' => trim((string) ($row['admin_name'] ?? '')) !== ''
                    ? (string) $row['admin_name']
                    : null,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function refundsForOrder(int $orderId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, request_number, status, approved_amount_inr, reason_code, description, created_at, updated_at
             FROM refund_requests WHERE order_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$orderId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rid = (int) ($row['id'] ?? 0);
            $gw = RefundRequestService::latestPaymentRefundForRequest($rid);
            $out[] = [
                'id' => $rid,
                'request_number' => (string) ($row['request_number'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'status_label' => RefundRequestService::publicStatusLabel((string) ($row['status'] ?? '')),
                'amount_inr' => round(RefundRequestService::displayAmountInr($row), 2),
                'reason_code' => (string) ($row['reason_code'] ?? ''),
                'description' => trim((string) ($row['description'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'gateway_refund_id' => $gw !== null ? trim((string) ($gw['gateway_refund_id'] ?? '')) : '',
                'admin_url' => url_base() . '/admin/refunds/index.php?id=' . $rid,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $orderRow
     * @return array<string, mixed>
     */
    private static function overviewBlock(array $base, array $orderRow): array
    {
        return [
            'order_number' => (string) ($base['order_number'] ?? ''),
            'placed_at' => (string) ($base['placed_at'] ?? ''),
            'order_status' => (string) ($base['status'] ?? ''),
            'payment_status' => (string) ($base['payment_status'] ?? ''),
            'order_source_label' => (string) ($base['order_source_label'] ?? ''),
            'grand_total' => (float) ($base['grand_total'] ?? 0),
            'delivery_date' => (string) ($base['delivery_date'] ?? ''),
            'delivery_slot_label' => (string) ($base['delivery_slot_label'] ?? $base['delivery_slot'] ?? ''),
        ];
    }

    /** @param list<array<string, mixed>> $payments */
    private static function primaryPaymentMethodLabel(array $payments): ?string
    {
        foreach (array_reverse($payments) as $p) {
            if (($p['status'] ?? '') === 'captured' && !empty($p['payment_method'])) {
                return (string) $p['payment_method'];
            }
        }
        foreach (array_reverse($payments) as $p) {
            if (!empty($p['payment_method'])) {
                return (string) $p['payment_method'];
            }
        }

        return null;
    }

    public static function orderSourceLabel(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === 'subscription') {
            return 'Subscription delivery';
        }
        if ($source === 'storefront') {
            return 'Online storefront';
        }
        if ($source === 'admin_assisted') {
            return 'Admin assisted';
        }

        return $source !== '' ? ucwords(str_replace('_', ' ', $source)) : '—';
    }

    /**
     * WhatsApp deep link for a customer mobile (India), or null when invalid.
     */
    public static function whatsappUrlForMobile(string $mobile): ?string
    {
        $digits = Validation::normalizeMobileIndia($mobile);

        return $digits !== null ? 'https://wa.me/91' . $digits : null;
    }

    public static function telUrlForMobile(string $mobile): ?string
    {
        $digits = Validation::normalizeMobileIndia($mobile);

        return $digits !== null ? 'tel:+91' . $digits : null;
    }

    /** @return array<string, mixed> */
    private static function decodeMetadata(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string> $keys
     */
    private static function metaString(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!empty($meta[$key]) && is_string($meta[$key])) {
                $v = trim($meta[$key]);
                if ($v !== '') {
                    if ($key === 'vpa' || str_contains($key, 'vpa')) {
                        return self::maskVpa($v);
                    }

                    return $v;
                }
            }
        }

        return null;
    }

    private static function maskVpa(string $vpa): string
    {
        $parts = explode('@', $vpa, 2);
        if (count($parts) !== 2) {
            return strlen($vpa) > 4 ? substr($vpa, 0, 2) . '…' : $vpa;
        }
        $local = $parts[0];
        if (strlen($local) <= 2) {
            return $local . '@' . $parts[1];
        }

        return substr($local, 0, 2) . str_repeat('•', min(4, max(1, strlen($local) - 2))) . '@' . $parts[1];
    }
}
