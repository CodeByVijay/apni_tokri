<?php

declare(strict_types=1);

/**
 * Shared order/payment fields for customer email payloads (Cashfree + Razorpay).
 */
final class OrderEmailContext
{
    /** @return list<string> */
    public static function notifiableOrderStatuses(): array
    {
        return [
            OrderStatus::CONFIRMED,
            OrderStatus::ACCEPTED,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED,
            OrderStatus::CANCELLED,
        ];
    }

    public static function shouldNotifyStatusTransition(string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        if ($from === $to) {
            return false;
        }

        return in_array($to, self::notifiableOrderStatuses(), true);
    }

    public static function normalizeOrderStatus(string $status): string
    {
        return strtolower(trim($status));
    }

    public static function gatewayDisplayName(string $gateway): string
    {
        $g = strtolower(trim($gateway));
        if ($g === 'cashfree') {
            return 'Cashfree';
        }
        if ($g === 'razorpay') {
            return 'Razorpay';
        }
        if ($g === 'simulate' || $g === 'simulated') {
            return 'Test checkout';
        }

        return $gateway !== '' ? ucfirst($gateway) : 'Payment gateway';
    }

    /**
     * @return array<string, string>|null email, customer_name, recipient_ref
     */
    public static function resolveRecipientForOrderId(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT o.order_number, o.grand_total, c.email, c.name, c.id AS customer_id
             FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '' || !Validation::email($email)) {
            return null;
        }

        return [
            'email' => $email,
            'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            'recipient_ref' => 'customer:' . (int) $row['customer_id'],
            'order_number' => (string) $row['order_number'],
            'amount' => number_format((float) $row['grand_total'], 2, '.', ''),
            'customer_id' => (string) (int) $row['customer_id'],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public static function resolveRecipientForOrderNumber(string $orderNumber): ?array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT o.id, o.order_number, o.grand_total, c.email, c.name, c.id AS customer_id
             FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.order_number = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '' || !Validation::email($email)) {
            return null;
        }

        return [
            'email' => $email,
            'customer_name' => trim((string) ($row['name'] ?? '')) !== '' ? (string) $row['name'] : 'Customer',
            'recipient_ref' => 'customer:' . (int) $row['customer_id'],
            'order_number' => (string) $row['order_number'],
            'amount' => number_format((float) $row['grand_total'], 2, '.', ''),
            'customer_id' => (string) (int) $row['customer_id'],
            'order_id' => (string) (int) $row['id'],
        ];
    }

    /**
     * @param array<string, string> $base
     * @return array<string, string>
     */
    public static function enrichOrderPayload(int $orderId, array $base, ?array $paymentRow = null): array
    {
        $orderNumber = (string) ($base['order_number'] ?? '');
        $out = $base;
        $out['site_name'] = trim((string) (AppSettings::get('app.name', 'Veggie Tokri') ?? 'Veggie Tokri'));
        if ($out['site_name'] === '') {
            $out['site_name'] = 'Veggie Tokri';
        }
        $out['order_url'] = self::customerOrderDetailUrl($orderNumber);
        $out['payment_retry_url'] = self::customerPaymentRetryUrl($orderNumber);
        $out['orders_url'] = self::customerOrdersListUrl();

        if ($paymentRow === null) {
            $paymentRow = self::latestPaymentForOrder($orderId);
        }
        $gateway = is_array($paymentRow) ? (string) ($paymentRow['gateway'] ?? '') : '';
        $out['gateway_name'] = self::gatewayDisplayName($gateway);
        $ref = '';
        if (is_array($paymentRow)) {
            $gp = trim((string) ($paymentRow['gateway_payment_id'] ?? ''));
            $go = trim((string) ($paymentRow['gateway_order_id'] ?? ''));
            $ref = $gp !== '' ? $gp : $go;
            if (!empty($paymentRow['captured_at'])) {
                $out['paid_at'] = (string) $paymentRow['captured_at'];
            }
        }
        $out['payment_reference'] = $ref !== '' ? $ref : '—';
        $out['event_date'] = date('d M Y, h:i A');
        if (is_array($paymentRow) && (string) ($paymentRow['status'] ?? '') === 'captured') {
            $out = self::appendConfirmationSnapshotFields($orderId, $out);
        }

        return $out;
    }

    public static function isOrderPaidAndCaptured(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        if ((string) ($st->fetchColumn() ?: '') !== 'paid') {
            return false;
        }

        return self::capturedPaymentForOrder($orderId) !== null;
    }

    /**
     * Pending-payment email (`order.placed.email`) only while checkout is still unpaid.
     */
    public static function shouldSendPendingPaymentEmail(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }
        if (self::isOrderPaidAndCaptured($orderId)) {
            return false;
        }
        if (self::capturedPaymentForOrder($orderId) !== null) {
            return false;
        }
        Install::ensureConnection();
        $st = Database::pdo()->prepare('SELECT payment_status, status FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ((string) ($row['payment_status'] ?? '') === 'paid') {
            return false;
        }
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === OrderStatus::CANCELLED) {
            return false;
        }
        if (!in_array($status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED], true)) {
            return false;
        }

        return true;
    }

    public static function orderIdFromPlacedEmailIdempotencyKey(string $idempotencyKey): ?int
    {
        if (preg_match('/^order\.placed:order:(\d+):email$/', trim($idempotencyKey), $m) === 1) {
            $id = (int) $m[1];

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @param array<string, string> $out
     * @return array<string, string>
     */
    private static function appendConfirmationSnapshotFields(int $orderId, array $out): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT o.subtotal, o.discount_total, o.delivery_fee, o.grand_total, o.delivery_date, o.delivery_slot, o.delivery_slot_id,
                    o.coupon_code, o.customer_address_id, o.delivery_snapshot_json
             FROM orders o WHERE o.id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return $out;
        }
        $out['subtotal'] = number_format((float) ($order['subtotal'] ?? 0), 2, '.', '');
        $out['discount_total'] = number_format((float) ($order['discount_total'] ?? 0), 2, '.', '');
        $out['delivery_fee'] = number_format((float) ($order['delivery_fee'] ?? 0), 2, '.', '');
        $out['total_paid'] = number_format((float) ($order['grand_total'] ?? 0), 2, '.', '');
        $out['track_order_url'] = $out['order_url'] ?? self::customerOrderDetailUrl((string) ($out['order_number'] ?? ''));

        $addr = CustomerOrderDetailService::addressBlock($order, $pdo);
        $out['delivery_address'] = $addr !== null && !empty($addr['formatted'])
            ? (string) $addr['formatted']
            : '—';
        $slotResolved = DeliverySlotDisplay::resolveForOrder($order, $pdo);
        $slotLine = (string) ($slotResolved['display_line'] ?? $slotResolved['label'] ?? '');
        if ($slotLine === '') {
            $slotLine = trim((string) ($order['delivery_slot'] ?? ''));
        }
        $date = trim((string) ($order['delivery_date'] ?? ''));
        $out['delivery_slot_line'] = trim($date . ($date !== '' && $slotLine !== '' ? ' · ' : '') . $slotLine);
        if ($out['delivery_slot_line'] === '') {
            $out['delivery_slot_line'] = 'To be confirmed';
        }

        $itemsStmt = $pdo->prepare(
            'SELECT product_name, variant_name, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY id ASC'
        );
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['order_items_html'] = self::buildItemsHtmlTable($items);
        $out['order_items_text'] = self::buildItemsPlainText($items);
        $out['order_summary_html'] = self::buildSummaryHtml($order, $out);

        return $out;
    }

    /** @param list<array<string, mixed>> $items */
    private static function buildItemsHtmlTable(array $items): string
    {
        if ($items === []) {
            return '<p style="font-size:14px;color:#64748b;">No line items recorded.</p>';
        }
        $rows = '';
        foreach ($items as $it) {
            $name = Security::escape((string) ($it['product_name'] ?? ''));
            $variant = trim((string) ($it['variant_name'] ?? ''));
            if ($variant !== '') {
                $name .= ' <span style="color:#64748b;font-weight:normal;">(' . Security::escape($variant) . ')</span>';
            }
            $qty = (int) ($it['quantity'] ?? 0);
            $unit = catalogue_format_price((float) ($it['unit_price'] ?? 0));
            $line = catalogue_format_price((float) ($it['line_total'] ?? 0));
            $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e8e8e4;">' . $name . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e8e8e4;text-align:center;">' . $qty . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e8e8e4;text-align:right;">' . Security::escape($unit) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e8e8e4;text-align:right;font-weight:600;">' . Security::escape($line) . '</td></tr>';
        }

        return '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;">'
            . '<thead><tr style="background:#f4f6f2;"><th align="left" style="padding:6px 8px;">Item</th>'
            . '<th style="padding:6px 8px;">Qty</th><th align="right" style="padding:6px 8px;">Unit</th>'
            . '<th align="right" style="padding:6px 8px;">Total</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** @param list<array<string, mixed>> $items */
    private static function buildItemsPlainText(array $items): string
    {
        if ($items === []) {
            return 'Items: (none)';
        }
        $lines = [];
        foreach ($items as $it) {
            $lines[] = (string) ($it['product_name'] ?? '') . ' x' . (int) ($it['quantity'] ?? 0)
                . ' @ Rs ' . number_format((float) ($it['unit_price'] ?? 0), 2);
        }

        return implode('; ', $lines);
    }

    /** @param array<string, mixed> $order @param array<string, string> $out */
    private static function buildSummaryHtml(array $order, array $out): string
    {
        $disc = (float) ($order['discount_total'] ?? 0);
        $fee = (float) ($order['delivery_fee'] ?? 0);
        $coupon = trim((string) ($order['coupon_code'] ?? ''));
        $html = '<table role="presentation" style="width:100%;font-size:14px;margin:8px 0 4px;">';
        $html .= '<tr><td>Subtotal</td><td align="right">₹' . Security::escape((string) ($out['subtotal'] ?? '0')) . '</td></tr>';
        if ($disc > 0) {
            $label = 'Discount' . ($coupon !== '' ? ' (' . Security::escape($coupon) . ')' : '');
            $html .= '<tr><td>' . $label . '</td><td align="right">−₹' . Security::escape((string) ($out['discount_total'] ?? '0')) . '</td></tr>';
        }
        $html .= '<tr><td>Delivery fee</td><td align="right">' . ($fee === 0.0 ? 'Free' : '₹' . Security::escape((string) ($out['delivery_fee'] ?? '0'))) . '</td></tr>';
        $html .= '<tr><td style="font-weight:700;padding-top:6px;">Total paid</td><td align="right" style="font-weight:700;padding-top:6px;">₹' . Security::escape((string) ($out['total_paid'] ?? $out['amount'] ?? '0')) . '</td></tr>';
        $html .= '</table>';

        return $html;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function latestPaymentForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT gateway, gateway_payment_id, gateway_order_id, status, captured_at, updated_at
             FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function capturedPaymentForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT gateway, gateway_payment_id, gateway_order_id, status, captured_at
             FROM payments WHERE order_id = ? AND status = 'captured' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** Absolute storefront URL for order details (admin Website URL, not the HTTP Host header). */
    public static function customerOrderDetailUrl(string $orderNumber): string
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return self::customerOrdersListUrl();
        }

        return SeoUrl::storefrontAbsolute('/order-details.php?id=' . rawurlencode($orderNumber));
    }

    public static function customerPaymentRetryUrl(string $orderNumber): string
    {
        $orderNumber = trim($orderNumber);

        return SeoUrl::storefrontAbsolute('/payment.php?order=' . rawurlencode($orderNumber));
    }

    public static function customerOrdersListUrl(): string
    {
        return SeoUrl::storefrontAbsolute('/my-orders.php');
    }
}
