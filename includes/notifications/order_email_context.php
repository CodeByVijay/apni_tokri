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

        return $out;
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

    /** Absolute storefront URL for order details (uses app.base_url, not Host header). */
    public static function customerOrderDetailUrl(string $orderNumber): string
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return self::customerOrdersListUrl();
        }

        return SeoUrl::absolute('/order-details.php?id=' . rawurlencode($orderNumber));
    }

    public static function customerPaymentRetryUrl(string $orderNumber): string
    {
        $orderNumber = trim($orderNumber);

        return SeoUrl::absolute('/payment.php?order=' . rawurlencode($orderNumber));
    }

    public static function customerOrdersListUrl(): string
    {
        return SeoUrl::absolute('/my-orders.php');
    }
}
