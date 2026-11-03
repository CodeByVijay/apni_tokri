<?php

declare(strict_types=1);

final class AdminOrderService
{
    public const PER_PAGE = 50;

    /** Status values an admin may set manually from the order detail screen. */
    public static function adminUpdatableStatuses(): array
    {
        return [
            OrderStatus::CONFIRMED,
            OrderStatus::ACCEPTED,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED,
            OrderStatus::CANCELLED,
            OrderStatus::DELIVERY_EXCEPTION,
            OrderStatus::PARTIALLY_REFUNDED,
            OrderStatus::REFUNDED,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function listForAdmin(int $page = 1): array
    {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $total = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $offset = ($page - 1) * self::PER_PAGE;
        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.status, o.payment_status, o.grand_total,
                    o.placed_at, o.created_at,
                    c.name AS customer_name, c.mobile AS customer_mobile
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             ORDER BY COALESCE(o.placed_at, o.created_at) DESC, o.id DESC
             LIMIT ' . (int) self::PER_PAGE . ' OFFSET ' . (int) $offset
        );
        $stmt->execute();
        $rows = [];
        $base = url_base() . '/admin/orders/view.php?id=';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $customer = trim((string) ($row['customer_name'] ?? ''));
            $mobile = trim((string) ($row['customer_mobile'] ?? ''));
            if ($customer === '' && $mobile === '') {
                $customerLabel = 'Guest';
            } elseif ($customer !== '' && $mobile !== '') {
                $customerLabel = $customer . ' · ' . $mobile;
            } else {
                $customerLabel = $customer !== '' ? $customer : $mobile;
            }
            $statusLabel = self::statusLabel((string) ($row['status'] ?? ''));
            $rows[] = [
                'id' => (string) ($row['order_number'] ?? ''),
                'customer' => $customerLabel,
                'status' => $statusLabel,
                'total' => catalogue_format_price((float) ($row['grand_total'] ?? 0)),
                'link' => 'View',
                'action_href' => $base . (int) ($row['id'] ?? 0),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $orderId): ?array
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT o.*, c.name AS customer_name, c.mobile AS customer_mobile, c.email AS customer_email,
                    a.label AS address_label, a.line1, a.line2, a.city, a.state, a.pincode
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN customer_addresses a ON a.id = o.customer_address_id
             WHERE o.id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }

        $itemsStmt = $pdo->prepare(
            'SELECT oi.product_name, oi.variant_name, oi.quantity, oi.unit_price, oi.line_total,
                    (SELECT pi.file_path FROM product_images pi
                     INNER JOIN product_variants pv ON pv.product_id = pi.product_id
                     WHERE pv.id = oi.variant_id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
             FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id ASC'
        );
        $itemsStmt->execute([$orderId]);
        $items = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $items[] = [
                'name' => (string) $it['product_name'],
                'variant' => (string) $it['variant_name'],
                'qty' => (int) $it['quantity'],
                'unit_price' => (float) $it['unit_price'],
                'line_total' => (float) $it['line_total'],
                'image' => (string) ($it['image_path'] ?? '') !== '' ? asset_url((string) $it['image_path']) : '',
            ];
        }

        $payStmt = $pdo->prepare(
            'SELECT gateway, status, amount, gateway_payment_id, gateway_order_id, created_at, updated_at
             FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 5'
        );
        $payStmt->execute([$orderId]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $histStmt = $pdo->prepare(
            'SELECT h.status, h.note, h.created_at, h.admin_user_id, u.name AS admin_name
             FROM order_status_history h
             LEFT JOIN admin_users u ON u.id = h.admin_user_id
             WHERE h.order_id = ?
             ORDER BY h.created_at ASC, h.id ASC'
        );
        $histStmt->execute([$orderId]);
        $history = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $deliveryId = null;
        $dStmt = $pdo->prepare('SELECT id FROM deliveries WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $dStmt->execute([$orderId]);
        $deliveryId = (int) ($dStmt->fetchColumn() ?: 0);
        if ($deliveryId <= 0) {
            $deliveryId = null;
        }

        $addressParts = array_filter([
            (string) ($order['line1'] ?? ''),
            (string) ($order['line2'] ?? ''),
            trim((string) ($order['city'] ?? '') . ', ' . (string) ($order['state'] ?? '')),
            (string) ($order['pincode'] ?? ''),
        ]);
        $addressLine = $addressParts !== [] ? implode(' — ', $addressParts) : '—';
        // Prefer the immutable order snapshot over the live (possibly later-edited) address row.
        $snapAddr = DeliveryAddressSnapshot::forOrder($order, $pdo);
        if ($snapAddr !== null && empty($snapAddr['legacy_order'])) {
            $snapLine = trim(AddressInput::fullAddress($snapAddr));
            if ($snapLine !== '') {
                $addressLine = $snapLine;
                $order['address_label'] = (string) ($snapAddr['label'] ?? $order['address_label'] ?? '');
            }
        }

        return [
            'id' => $orderId,
            'order_number' => (string) ($order['order_number'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'payment_status' => (string) ($order['payment_status'] ?? ''),
            'order_source' => (string) ($order['order_source'] ?? ''),
            'customer_name' => (string) ($order['customer_name'] ?? ''),
            'customer_mobile' => (string) ($order['customer_mobile'] ?? ''),
            'customer_email' => (string) ($order['customer_email'] ?? ''),
            'address_line' => $addressLine,
            'address_label' => (string) ($order['address_label'] ?? ''),
            'delivery_slot' => (string) ($order['delivery_slot'] ?? ''),
            'delivery_date' => (string) ($order['delivery_date'] ?? ''),
            'delivery_fee' => (float) ($order['delivery_fee'] ?? 0),
            'subtotal' => (float) ($order['subtotal'] ?? 0),
            'discount_total' => (float) ($order['discount_total'] ?? 0),
            'grand_total' => (float) ($order['grand_total'] ?? 0),
            'coupon_code' => $order['coupon_code'] ?? null,
            'placed_at' => (string) ($order['placed_at'] ?? $order['created_at'] ?? ''),
            'items' => $items,
            'payments' => $payments,
            'history' => $history,
            'delivery_id' => $deliveryId,
        ];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function updateStatus(int $orderId, string $status, string $note): array
    {
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        $status = trim($status);
        if ($status === '' || !in_array($status, self::adminUpdatableStatuses(), true)) {
            return ['ok' => false, 'message' => 'Choose a valid order status.'];
        }
        $note = trim($note);
        if ($note === '') {
            $note = 'Status updated by admin';
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $previous = '';
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id, status FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$orderId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Order not found.'];
            }
            $previous = (string) ($row['status'] ?? '');
            if ($previous === $status && $note === 'Status updated by admin') {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Status is already set to that value.'];
            }
            $pdo->prepare('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$status, $orderId]);
            $adminId = AdminAuth::id();
            $pdo->prepare(
                'INSERT INTO order_status_history (order_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
            )->execute([$orderId, $status, $note, $adminId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('AdminOrderService::updateStatus');

            return ['ok' => false, 'message' => 'Could not update order status.'];
        }

        if (Install::isSchemaReady() && AdminAuth::check()) {
            AuditLog::write('order.status_update', 'orders', (string) $orderId, [
                'from' => $previous,
                'to' => $status,
            ]);
        }

        return ['ok' => true, 'message' => 'Order status updated.'];
    }

    public static function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
