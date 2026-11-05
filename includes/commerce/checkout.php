<?php

declare(strict_types=1);

final class OrderRepository
{
    /**
     * Paginated order list for the authenticated customer (newest first).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function listForCustomer(
        int $customerId,
        int $page = 1,
        int $perPage = AccountListPagination::DEFAULT_PER_PAGE
    ): array {
        Install::ensureConnection();
        $norm = AccountListPagination::normalize($page, $perPage, true);
        if ($customerId <= 0) {
            return AccountListPagination::emptyResult($norm['unlimited'] ? AccountListPagination::DEFAULT_PER_PAGE : $norm['per_page']);
        }

        $pdo = Database::pdo();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = ?');
        $countStmt->execute([$customerId]);
        $total = (int) $countStmt->fetchColumn();
        if ($total === 0) {
            return AccountListPagination::emptyResult($norm['unlimited'] ? AccountListPagination::DEFAULT_PER_PAGE : $norm['per_page']);
        }

        if ($norm['unlimited']) {
            $pageNum = 1;
            $per = 0;
            $limitSql = '';
            $params = [$customerId];
        } else {
            $totalPages = AccountListPagination::totalPages($total, $norm['per_page']);
            $pageNum = AccountListPagination::clampPage($norm['page'], $totalPages);
            $per = $norm['per_page'];
            $offset = ($pageNum - 1) * $per;
            $limitSql = ' LIMIT ? OFFSET ?';
            $params = [$customerId, $per, $offset];
        }

        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.status, o.payment_status, o.grand_total, o.delivery_slot,
                    o.placed_at, o.created_at, COALESCE(SUM(oi.quantity), 0) AS item_qty
             FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.customer_id = ?
             GROUP BY o.id
             ORDER BY COALESCE(o.placed_at, o.created_at) DESC, o.id DESC' . $limitSql
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $orderIds = [];
        foreach ($rows as $row) {
            $orderIds[] = (int) $row['id'];
        }
        $previews = self::itemPreviewsForOrders($orderIds);

        $out = [];
        foreach ($rows as $row) {
            $oid = (int) $row['id'];
            $out[] = self::mapSummary($row, $previews[$oid] ?? []);
        }

        $totalPagesOut = $norm['unlimited'] ? 1 : AccountListPagination::totalPages($total, $per);

        return [
            'rows' => $out,
            'total' => $total,
            'page' => $pageNum,
            'per_page' => $norm['unlimited'] ? $total : $per,
            'total_pages' => $totalPagesOut,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listAllRowsForCustomer(int $customerId): array
    {
        return self::listForCustomer($customerId, 1, 0)['rows'];
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, list<array<string, mixed>>>
     */
    private static function itemPreviewsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        Install::ensureConnection();
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT oi.order_id, oi.product_name, oi.variant_name, oi.quantity, oi.variant_id,
                    (SELECT pi.file_path FROM product_images pi
                     INNER JOIN product_variants pv ON pv.product_id = pi.product_id
                     WHERE pv.id = oi.variant_id
                     ORDER BY pi.is_primary DESC, pi.sort_order ASC LIMIT 1) AS image_path
             FROM order_items oi
             WHERE oi.order_id IN (' . $placeholders . ')
             ORDER BY oi.order_id ASC, oi.id ASC'
        );
        $stmt->execute($orderIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $oid = (int) $row['order_id'];
            if (!isset($out[$oid])) {
                $out[$oid] = [];
            }
            if (count($out[$oid]) >= 3) {
                continue;
            }
            $img = (string) ($row['image_path'] ?? '');
            $out[$oid][] = [
                'name' => (string) $row['product_name'],
                'label' => (string) $row['variant_name'],
                'qty' => (int) $row['quantity'],
                'image' => $img !== '' ? asset_url($img) : '',
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function forCustomerByNumber(string $orderNumber, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }

        return self::mapFull($order);
    }

    /** @return array<string, mixed>|null Full customer detail view (items, timeline, payment, address). */
    public static function forCustomerDetail(string $orderNumber, int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber, $customerId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return null;
        }
        $base = self::mapFull($order);

        return CustomerOrderDetailService::enrich($order, $base, $customerId);
    }

    /** @param array<string, mixed> $row @param list<array<string, mixed>> $itemPreview */
    private static function mapSummary(array $row, array $itemPreview = []): array
    {
        $total = (float) ($row['grand_total'] ?? 0);

        return [
            'id' => (string) $row['order_number'],
            'status' => self::publicStatus((string) $row['status'], (string) $row['payment_status']),
            'totals' => ['total' => $total],
            'created_at' => (string) ($row['placed_at'] ?? $row['created_at'] ?? ''),
            'slot' => (string) ($row['delivery_slot'] ?? ''),
            'item_qty' => (int) ($row['item_qty'] ?? 0),
            'items' => $itemPreview,
        ];
    }

    /** @param array<string, mixed> $orderRow */
    private static function mapFull(array $orderRow): array
    {
        $orderId = (int) $orderRow['id'];
        Install::ensureConnection();
        $itemsStmt = Database::pdo()->prepare(
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
                'label' => (string) $it['variant_name'],
                'qty' => (int) $it['quantity'],
                'unit_price' => (float) $it['unit_price'],
                'line_total' => (float) $it['line_total'],
                'image' => (string) ($it['image_path'] ?? '') !== '' ? asset_url((string) $it['image_path']) : '',
            ];
        }

        $hist = Database::pdo()->prepare(
            'SELECT status, note, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at'
        );
        $hist->execute([$orderId]);
        $deliveryTimeline = DeliveryCustomerTimeline::forOrderId($orderId);
        if ($deliveryTimeline !== []) {
            $timeline = $deliveryTimeline;
        } else {
            $timeline = [];
            foreach ($hist->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $timeline[] = [
                    'status' => (string) $h['status'],
                    'at' => (string) $h['created_at'],
                    'done' => true,
                ];
            }
        }

        return [
            'id' => (string) $orderRow['order_number'],
            'status' => self::publicStatus((string) $orderRow['status'], (string) $orderRow['payment_status']),
            'mobile' => CustomerService::profile()['mobile'] ?? '',
            'customer_name' => CustomerService::profile()['name'] ?? '',
            'slot' => (string) ($orderRow['delivery_slot'] ?? ''),
            'items' => $items,
            'totals' => [
                'subtotal' => (float) $orderRow['subtotal'],
                'coupon_discount' => (float) $orderRow['discount_total'],
                'delivery_fee' => (float) $orderRow['delivery_fee'],
                'total' => (float) $orderRow['grand_total'],
                'coupon_code' => $orderRow['coupon_code'],
            ],
            'timeline' => $timeline,
            'created_at' => (string) ($orderRow['placed_at'] ?? $orderRow['created_at'] ?? ''),
        ];
    }

    private static function publicStatus(string $status, string $paymentStatus): string
    {
        if ($paymentStatus === 'failed' || $status === 'payment_failed') {
            return 'payment_failed';
        }
        if (in_array($status, [OrderStatus::DELIVERED, OrderStatus::PARTIALLY_REFUNDED, OrderStatus::REFUNDED], true)) {
            return $status;
        }
        if ($paymentStatus === 'paid' || $status === 'confirmed') {
            return 'confirmed';
        }

        return $status;
    }
}

final class CheckoutService
{
    public static function freshNonce(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['checkout_nonce'] = $nonce;

        return $nonce;
    }

    /**
     * @return array{ok: bool, message?: string, order_number?: string, redirect?: string}
     */
    public static function placeOrder(array $input): array
    {
        if (!CustomerService::isLoggedIn()) {
            return ['ok' => false, 'message' => 'Please log in to continue.'];
        }
        if (CartService::items() === []) {
            return ['ok' => false, 'message' => 'Your cart is empty.'];
        }

        $nonce = (string) ($input['checkout_nonce'] ?? '');
        $expected = (string) ($_SESSION['checkout_nonce'] ?? '');
        if ($nonce === '' || $expected === '' || !Security::hashEquals($expected, $nonce)) {
            return ['ok' => false, 'message' => 'This checkout session expired. Refresh the page and try again.'];
        }
        unset($_SESSION['checkout_nonce']);

        $addressId = (int) ($input['address_id'] ?? 0);
        $slot = (string) ($input['delivery_slot'] ?? '');
        $paySim = (string) ($input['payment_sim'] ?? 'success');
        $terms = !empty($input['terms']);

        if (!$terms) {
            return ['ok' => false, 'message' => 'Please accept the terms to continue.'];
        }
        if (!DeliveryConfig::slotsEnabled()) {
            $slot = $slot === '' ? 'auto' : $slot;
        } elseif (!DeliveryService::validateSlot($slot)) {
            return ['ok' => false, 'message' => 'Choose a valid delivery slot.'];
        }

        if (!StorefrontReadiness::isStorefrontOperational()) {
            return ['ok' => false, 'message' => 'The store is not available for checkout yet. Please check back soon.'];
        }
        if (AppEnvironment::usePersistedStorefront()) {
            return self::placeOrderDb($addressId, $slot, $paySim);
        }
        if (AppEnvironment::allowsSessionDemoCommerce()) {
            return self::placeOrderSession($addressId, $slot, $paySim);
        }

        return ['ok' => false, 'message' => 'Checkout is unavailable in this environment.'];
    }

    /** @return array{ok: bool, message?: string, order_number?: string, redirect?: string} */
    private static function placeOrderDb(int $addressId, string $slot, string $paySim): array
    {
        $customerId = CustomerAuth::id();
        if ($customerId === null) {
            return ['ok' => false, 'message' => 'Not logged in.'];
        }
        $address = CustomerService::address($addressId, $customerId);
        if ($address === null) {
            return ['ok' => false, 'message' => 'Select a valid delivery address.'];
        }
        DeliveryContext::setPin((string) $address['pincode']);

        $totals = CartService::totals();
        if (!empty($totals['coupon_error'])) {
            return ['ok' => false, 'message' => (string) $totals['coupon_error']];
        }
        $lines = CartService::items();

        $quote = DeliveryQuoteService::quoteForCheckout($address, $lines, $slot, $totals);
        if (empty($quote['ok'])) {
            return ['ok' => false, 'message' => (string) ($quote['message'] ?? 'Delivery is not available for this address.')];
        }

        $slotResolved = $quote['slot_resolved'] ?? null;
        $deliveryDate = $quote['delivery_date'] ?? null;
        $deliveryEta = $quote['delivery_eta'] ?? null;
        $slotId = isset($quote['slot_id']) ? (int) $quote['slot_id'] : null;
        $slot = (string) ($quote['slot_slug'] ?? $slot);
        $totals['delivery_fee'] = (float) $quote['delivery_fee'];
        $totals['total'] = (float) $quote['grand_total'];
        foreach ($lines as $line) {
            $vid = (int) $line['variant_id'];
            if (InventoryService::availableQty($vid) < (int) $line['qty']) {
                return ['ok' => false, 'message' => 'Stock changed for ' . ($line['name'] ?? 'an item') . '. Update your cart.'];
            }
        }

        $orderNumber = 'AT-' . date('ymd') . '-' . strtoupper(substr(Security::randomToken(4), 0, 6));
        $status = 'pending_payment';
        $paymentStatus = 'pending';

        $zoneId = isset($quote['zone_id']) ? (int) $quote['zone_id'] : null;
        if ($zoneId === 0) {
            $zoneId = null;
        }
        // Immutable order-time address: later edits to the saved address never change this order.
        $addrSnapshot = DeliveryAddressSnapshot::fromAddressRow($address, CustomerService::profile());
        if ($addrSnapshot['receiver_name'] === '' || $addrSnapshot['receiver_phone'] === '') {
            return ['ok' => false, 'message' => 'Add a receiver name and phone number to this address before checkout.'];
        }
        if (Validation::normalizeMobileIndia((string) $addrSnapshot['receiver_phone']) === null) {
            return ['ok' => false, 'message' => 'The receiver phone on this address is not valid. Please edit the address.'];
        }
        $snapshot = json_encode(DeliveryQuoteService::buildSnapshot($quote, $addrSnapshot), JSON_UNESCAPED_UNICODE);

        try {
            $orderId = Database::transaction(static function () use (
                $customerId,
                $addressId,
                $slot,
                $slotId,
                $deliveryDate,
                $deliveryEta,
                $zoneId,
                $snapshot,
                $totals,
                $lines,
                $orderNumber,
                $status,
                $paymentStatus,
                $slotResolved
            ): int {
                $pdo = Database::pdo();
                if ($slotResolved !== null) {
                    $slotIdLocal = (int) $slotResolved['id'];
                    $delDate = (string) $slotResolved['delivery_date'];
                    DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, $slotIdLocal, $delDate, null);
                }

                $pdo->prepare(
                    'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, discount_total,
                     delivery_fee, grand_total, payment_status, delivery_slot, delivery_zone_id, delivery_slot_id,
                     delivery_date, delivery_eta, delivery_snapshot_json, coupon_code, placed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    $orderNumber,
                    $customerId,
                    $addressId,
                    $status,
                    $totals['subtotal'],
                    (float) ($totals['coupon_discount'] ?? 0) + (float) ($totals['offer_discount'] ?? 0),
                    $totals['delivery_fee'],
                    $totals['total'],
                    $paymentStatus,
                    $slot,
                    $zoneId,
                    $slotId,
                    $deliveryDate,
                    $deliveryEta,
                    $snapshot,
                    $totals['coupon_code'],
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, sku, quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($lines as $line) {
                    $qty = (int) $line['qty'];
                    $unit = (float) $line['unit_price'];
                    $itemStmt->execute([
                        $orderId,
                        (int) $line['variant_id'],
                        (string) $line['name'],
                        (string) $line['label'],
                        null,
                        $qty,
                        $unit,
                        round($unit * $qty, 2),
                    ]);
                }

                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)'
                )->execute([
                    $orderId,
                    'Awaiting payment',
                    'Order created — complete payment to confirm',
                ]);

                if ($slotResolved !== null && DeliverySlotReservationService::tableReady()) {
                    DeliverySlotReservationService::createHold(
                        $pdo,
                        (int) $slotResolved['id'],
                        (string) $slotResolved['delivery_date'],
                        $orderId
                    );
                }

                return $orderId;
            });
        } catch (Throwable $e) {
            error_log('Checkout failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not place order. Please review your cart and try again.'];
        }

        NotificationDispatcher::orderPlacedPendingPayment($orderId);

        if (!PaymentGatewayConfig::useSimulatedCheckout()) {
            $liveReady = PaymentGatewayConfig::assertLivePaymentsReady();
            if (!$liveReady['ok']) {
                DeliverySlotReservationService::releaseForOrder($orderId);

                return ['ok' => false, 'message' => (string) ($liveReady['message'] ?? 'Payment gateway is not ready.')];
            }
        }

        $mobile = (string) (CustomerService::profile()['mobile'] ?? '');
        $init = PaymentService::initiateForOrder($orderId, $orderNumber, (float) $totals['total'], $mobile);
        if (!$init['ok']) {
            DeliverySlotReservationService::releaseForOrder($orderId);

            return ['ok' => false, 'message' => $init['error'] ?? 'Could not start payment.'];
        }
        $_SESSION['checkout_payment'][$orderNumber] = $init['checkout'] ?? [];

        return [
            'ok' => true,
            'order_number' => $orderNumber,
            'redirect' => url_base() . '/payment.php?order=' . rawurlencode($orderNumber),
        ];
    }

    /** @return array{ok: bool, message?: string, order_number?: string, redirect?: string} */
    private static function placeOrderSession(int $addressId, string $slot, string $paySim): array
    {
        $profile = CustomerService::profile();
        $totals = CartService::totals();
        if (!empty($totals['coupon_error'])) {
            return ['ok' => false, 'message' => (string) $totals['coupon_error']];
        }
        $order = [
            'status' => $paySim === 'fail' ? 'payment_failed' : 'confirmed',
            'customer_name' => $profile['name'] ?? 'Customer',
            'mobile' => $profile['mobile'] ?? '',
            'address_id' => (string) $addressId,
            'slot' => $slot,
            'items' => CartService::items(),
            'totals' => $totals,
            'timeline' => [
                ['status' => 'Order placed', 'at' => date('c'), 'done' => true],
                ['status' => 'Payment ' . ($paySim === 'fail' ? 'failed' : 'successful'), 'at' => date('c'), 'done' => true],
            ],
        ];
        if ($paySim === 'fail') {
            $orderId = SessionCustomerBackend::saveOrder($order);

            return [
                'ok' => true,
                'order_number' => $orderId,
                'redirect' => url_base() . '/payment-failed.php',
            ];
        }
        $orderId = SessionCustomerBackend::saveOrder($order);
        CartService::clear();

        return [
            'ok' => true,
            'order_number' => $orderId,
            'redirect' => url_base() . '/order.php?id=' . rawurlencode($orderId),
        ];
    }
}
