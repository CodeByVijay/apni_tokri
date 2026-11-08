<?php

declare(strict_types=1);

final class OrderRepository
{
    /**
     * Customer list filter chips → public status values (same mapping as publicStatus()).
     *
     * @var array<string, list<string>>
     */
    public const LIST_BUCKETS = [
        'processing' => ['pending_payment', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'delivery_exception'],
        'confirmed' => ['confirmed'],
        'delivered' => ['delivered'],
        'cancelled' => ['cancelled'],
    ];

    public static function normalizeListBucket(?string $bucket): ?string
    {
        $bucket = strtolower(trim((string) $bucket));

        return isset(self::LIST_BUCKETS[$bucket]) ? $bucket : null;
    }

    /** SQL expression equivalent to {@see publicStatus()} for alias $alias. */
    private static function publicStatusSql(string $alias): string
    {
        $a = $alias;

        return "(CASE
            WHEN {$a}.payment_status = 'failed' OR {$a}.status = 'payment_failed' THEN 'payment_failed'
            WHEN {$a}.status IN ('delivered','partially_refunded','refunded','cancelled') THEN {$a}.status
            WHEN {$a}.payment_status = 'paid' OR {$a}.status = 'confirmed' THEN 'confirmed'
            ELSE {$a}.status END)";
    }

    private static function bucketStatusPlaceholders(string $bucket): string
    {
        return implode(',', array_fill(0, count(self::LIST_BUCKETS[$bucket]), '?'));
    }

    /**
     * Counts for the My Orders filter chips (own orders only).
     *
     * @return array{all: int, processing: int, confirmed: int, delivered: int, cancelled: int}
     */
    public static function statusCountsForCustomer(int $customerId): array
    {
        $out = ['all' => 0, 'processing' => 0, 'confirmed' => 0, 'delivered' => 0, 'cancelled' => 0];
        if ($customerId <= 0) {
            return $out;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::publicStatusSql('o') . ' AS pub, COUNT(*) AS c FROM orders o WHERE o.customer_id = ? GROUP BY pub'
        );
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = (int) $row['c'];
            $out['all'] += $n;
            foreach (self::LIST_BUCKETS as $key => $statuses) {
                if (in_array((string) $row['pub'], $statuses, true)) {
                    $out[$key] += $n;
                }
            }
        }

        return $out;
    }

    /**
     * Paginated order list for the authenticated customer (newest first).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function listForCustomer(
        int $customerId,
        int $page = 1,
        int $perPage = AccountListPagination::DEFAULT_PER_PAGE,
        ?string $bucket = null
    ): array {
        Install::ensureConnection();
        $norm = AccountListPagination::normalize($page, $perPage, true);
        if ($customerId <= 0) {
            return AccountListPagination::emptyResult($norm['unlimited'] ? AccountListPagination::DEFAULT_PER_PAGE : $norm['per_page']);
        }

        $bucket = self::normalizeListBucket($bucket);
        $bucketSql = $bucket !== null ? ' AND ' . self::publicStatusSql('o') . ' IN (' . self::bucketStatusPlaceholders($bucket) . ')' : '';
        $bucketParams = $bucket !== null ? self::LIST_BUCKETS[$bucket] : [];

        $pdo = Database::pdo();
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM orders o WHERE o.customer_id = ?' . $bucketSql);
        $countStmt->execute(array_merge([$customerId], $bucketParams));
        $total = (int) $countStmt->fetchColumn();
        if ($total === 0) {
            return AccountListPagination::emptyResult($norm['unlimited'] ? AccountListPagination::DEFAULT_PER_PAGE : $norm['per_page']);
        }

        $params = array_merge([$customerId], $bucketParams);
        if ($norm['unlimited']) {
            $pageNum = 1;
            $per = 0;
            $limitSql = '';
        } else {
            $totalPages = AccountListPagination::totalPages($total, $norm['per_page']);
            $pageNum = AccountListPagination::clampPage($norm['page'], $totalPages);
            $per = $norm['per_page'];
            $offset = ($pageNum - 1) * $per;
            $limitSql = ' LIMIT ? OFFSET ?';
            $params[] = $per;
            $params[] = $offset;
        }

        $stmt = $pdo->prepare(
            'SELECT o.id, o.order_number, o.status, o.payment_status, o.grand_total, o.delivery_slot,
                    o.placed_at, o.created_at, COALESCE(SUM(oi.quantity), 0) AS item_qty
             FROM orders o
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.customer_id = ?' . $bucketSql . '
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

    public static function publicStatus(string $status, string $paymentStatus): string
    {
        if ($paymentStatus === 'failed' || $status === 'payment_failed') {
            return 'payment_failed';
        }
        if (in_array($status, [
            OrderStatus::DELIVERED,
            OrderStatus::PARTIALLY_REFUNDED,
            OrderStatus::REFUNDED,
            OrderStatus::CANCELLED,
        ], true)) {
            return $status;
        }
        if ($paymentStatus === 'paid' || $status === OrderStatus::CONFIRMED) {
            return OrderStatus::CONFIRMED;
        }

        return $status;
    }

    /**
     * Customer-facing status: {@see publicStatus()} plus fulfilment from the deliveries row (not payment or timeline alone).
     */
    public static function publicStatusWithFulfillment(string $orderStatus, string $paymentStatus, ?string $deliveryStatus): string
    {
        $public = self::publicStatus($orderStatus, $paymentStatus);
        if (in_array($public, [
            OrderStatus::CANCELLED,
            OrderStatus::PAYMENT_FAILED,
            OrderStatus::REFUNDED,
            OrderStatus::PARTIALLY_REFUNDED,
        ], true)) {
            return $public;
        }
        if ($public === OrderStatus::PENDING_PAYMENT) {
            return $public;
        }
        $fromDelivery = self::orderPhaseFromDeliveryStatus($deliveryStatus);
        if ($fromDelivery === null) {
            return $public;
        }
        if ($orderStatus === OrderStatus::DELIVERED) {
            return OrderStatus::DELIVERED;
        }
        if (self::orderPhaseRank($fromDelivery) > self::orderPhaseRank($public)) {
            return $fromDelivery;
        }

        return $public;
    }

    private static function orderPhaseFromDeliveryStatus(?string $deliveryStatus): ?string
    {
        if ($deliveryStatus === null || trim($deliveryStatus) === '') {
            return null;
        }
        $d = DeliveryStatus::normalize(trim($deliveryStatus));
        if ($d === DeliveryStatus::DELIVERED) {
            return OrderStatus::DELIVERED;
        }
        if ($d === DeliveryStatus::DISPATCHED) {
            return OrderStatus::OUT_FOR_DELIVERY;
        }
        if ($d === DeliveryStatus::READY) {
            return OrderStatus::READY;
        }
        if ($d === DeliveryStatus::FAILED) {
            return OrderStatus::DELIVERY_EXCEPTION;
        }
        if ($d === DeliveryStatus::CANCELLED) {
            return OrderStatus::CANCELLED;
        }

        return null;
    }

    /** @return int Higher means further along fulfilment. */
    private static function orderPhaseRank(string $status): int
    {
        $map = [
            OrderStatus::PENDING_PAYMENT => 0,
            OrderStatus::PAYMENT_FAILED => 0,
            OrderStatus::CONFIRMED => 10,
            OrderStatus::ACCEPTED => 15,
            OrderStatus::PREPARING => 20,
            OrderStatus::READY => 30,
            OrderStatus::OUT_FOR_DELIVERY => 40,
            OrderStatus::DELIVERED => 50,
            OrderStatus::DELIVERY_EXCEPTION => 45,
            OrderStatus::CANCELLED => 5,
            OrderStatus::REFUNDED => 5,
            OrderStatus::PARTIALLY_REFUNDED => 5,
        ];

        return $map[$status] ?? 8;
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

    /** @return array{ok: bool, message?: string} */
    private static function persistCheckoutContact(string $name, string $email): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            $customerId = CustomerAuth::id();
            if ($customerId === null) {
                return ['ok' => false, 'message' => 'Please log in to continue.'];
            }

            return CustomerAccountService::updateContactFromCheckout($customerId, $name, $email);
        }
        if (AppEnvironment::allowsSessionDemoCommerce()) {
            return SessionCustomerBackend::saveContactFromCheckout($name, $email);
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string, order_number?: string, redirect?: string}
     */
    public static function placeOrder(array $input): array
    {
        if (!CustomerService::isLoggedIn()) {
            return ['ok' => false, 'message' => 'Please log in to continue.'];
        }
        $customerId = CustomerAuth::id();
        if ($customerId !== null) {
            $profile = CustomerAccountService::profile($customerId);
            if ($profile === null || (int) ($profile['is_active'] ?? 1) !== 1) {
                return ['ok' => false, 'message' => 'Your account is inactive. Contact support if you need help.'];
            }
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
        $deliveryDate = isset($input['delivery_date']) && is_string($input['delivery_date'])
            ? trim($input['delivery_date']) : '';
        $paySim = (string) ($input['payment_sim'] ?? 'success');
        $terms = !empty($input['terms']);

        if (!$terms) {
            return ['ok' => false, 'message' => 'Please accept the terms to continue.'];
        }

        $contactName = isset($input['customer_name']) && is_string($input['customer_name'])
            ? $input['customer_name'] : '';
        $contactEmail = isset($input['customer_email']) && is_string($input['customer_email'])
            ? $input['customer_email'] : '';
        $contactSave = self::persistCheckoutContact($contactName, $contactEmail);
        if (!$contactSave['ok']) {
            return ['ok' => false, 'message' => (string) ($contactSave['message'] ?? 'Check your contact details and try again.')];
        }
        if (!DeliveryConfig::slotsEnabled()) {
            $slot = $slot === '' ? 'auto' : $slot;
            $deliveryDate = '';
        } else {
            if (!DeliveryService::validateSlot($slot)) {
                return ['ok' => false, 'message' => 'Choose a valid delivery slot.'];
            }
            $dateErr = DeliverySlotService::validateCustomerSlotDate($slot, $deliveryDate);
            if ($dateErr !== null) {
                return ['ok' => false, 'message' => $dateErr];
            }
        }

        if (!StorefrontReadiness::isStorefrontOperational()) {
            return ['ok' => false, 'message' => 'The store is not available for checkout yet. Please check back soon.'];
        }
        if (AppEnvironment::usePersistedStorefront()) {
            return self::placeOrderDb($addressId, $slot, $deliveryDate, $paySim);
        }
        if (AppEnvironment::allowsSessionDemoCommerce()) {
            return self::placeOrderSession($addressId, $slot, $paySim);
        }

        return ['ok' => false, 'message' => 'Checkout is unavailable in this environment.'];
    }

    /** @return array{ok: bool, message?: string, order_number?: string, redirect?: string} */
    private static function placeOrderDb(int $addressId, string $slot, string $deliveryDateYmd, string $paySim): array
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
        $minRejection = MarketingSettings::checkoutMinimumRejection($totals);
        if ($minRejection !== null) {
            return ['ok' => false, 'message' => $minRejection];
        }
        $lines = CartService::items();

        $slotDateParam = DeliveryConfig::slotsEnabled() && $deliveryDateYmd !== '' ? $deliveryDateYmd : null;
        $quote = DeliveryQuoteService::quoteForCheckout($address, $lines, $slot, $totals, null, null, $slotDateParam);
        if (empty($quote['ok'])) {
            return ['ok' => false, 'message' => (string) ($quote['message'] ?? 'Delivery is not available for this address.')];
        }
        if ($slotDateParam !== null && (string) ($quote['delivery_date'] ?? '') !== $deliveryDateYmd) {
            return ['ok' => false, 'message' => 'This delivery slot and date are no longer available. Refresh checkout and try again.'];
        }

        $deliveryDate = $quote['delivery_date'] ?? null;
        $deliveryEta = $quote['delivery_eta'] ?? null;
        $slotId = isset($quote['slot_id']) ? (int) $quote['slot_id'] : null;
        $slot = (string) ($quote['slot_slug'] ?? $slot);
        $totals['delivery_fee'] = (float) $quote['delivery_fee'];
        $totals['total'] = (float) $quote['grand_total'];
        foreach ($lines as $line) {
            $vid = (int) $line['variant_id'];
            $check = CartQuantityRules::validateForVariantId($vid, (int) $line['qty']);
            if (!$check['ok']) {
                $label = (string) ($line['name'] ?? 'an item');
                $msg = (string) ($check['message'] ?? 'Update your cart.');
                if (strpos($msg, 'out of stock') !== false) {
                    return ['ok' => false, 'message' => $label . ' is currently out of stock. Please update your cart.'];
                }

                return ['ok' => false, 'message' => $msg . ' (' . $label . '). Update your cart.'];
            }
        }

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
        $checkoutSpec = CheckoutIdempotencyService::buildSpec(
            $customerId,
            $addressId,
            $slot,
            is_string($deliveryDate) ? $deliveryDate : null,
            $slotId,
            (float) $totals['total'],
            isset($totals['coupon_code']) ? (string) $totals['coupon_code'] : null,
            $lines
        );

        try {
            $placed = Database::transaction(static function () use (
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
                $status,
                $paymentStatus,
                $checkoutSpec
            ): array {
                $pdo = Database::pdo();

                return CheckoutIdempotencyService::resolveOrCreateOrder(
                    $pdo,
                    $checkoutSpec,
                    $lines,
                    static function () use (
                        $pdo,
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
                        $status,
                        $paymentStatus,
                        $checkoutSpec
                    ): array {
                        $orderNumber = OrderNumberService::allocate($pdo, 'storefront');
                        if ($slotId !== null && $slotId > 0 && is_string($deliveryDate) && $deliveryDate !== '') {
                            DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, (int) $slotId, $deliveryDate, null);
                        }

                        $pdo->prepare(
                            'INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, discount_total,
                             delivery_fee, grand_total, payment_status, delivery_slot, delivery_zone_id, delivery_slot_id,
                             delivery_date, delivery_eta, delivery_snapshot_json, coupon_code, checkout_nonce, placed_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
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
                            (string) $checkoutSpec['signature'],
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

                        if ($slotId !== null && $slotId > 0 && is_string($deliveryDate) && $deliveryDate !== ''
                            && DeliverySlotReservationService::tableReady()) {
                            DeliverySlotReservationService::createHold($pdo, (int) $slotId, $deliveryDate, $orderId);
                        }

                        return [
                            'ok' => true,
                            'order_id' => $orderId,
                            'order_number' => $orderNumber,
                            'reused' => false,
                        ];
                    }
                );
            });
            if (empty($placed['ok'])) {
                return ['ok' => false, 'message' => (string) ($placed['message'] ?? 'Could not place order. Please review your cart and try again.')];
            }
            $orderId = (int) $placed['order_id'];
            $orderNumber = (string) $placed['order_number'];
            $orderReused = !empty($placed['reused']);
        } catch (Throwable $e) {
            error_log('Checkout failed: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not place order. Please review your cart and try again.'];
        }

        if (!$orderReused) {
            NotificationDispatcher::orderPlacedPendingPayment($orderId);
        } else {
            $pdoHold = Database::pdo();
            PaymentSlotHoldService::synchronizeOpenPaymentHold($pdoHold, $orderId);
        }

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
            $_SESSION['checkout_pending_order'] = $orderNumber;

            return ['ok' => false, 'message' => $init['error'] ?? 'Could not start payment.'];
        }
        unset($_SESSION['checkout_pending_order']);
        $_SESSION['checkout_payment'][$orderNumber] = $init['checkout'] ?? [];
        $checkoutMode = is_array($init['checkout'] ?? null) ? (string) ($init['checkout']['mode'] ?? '') : '';
        if (in_array($checkoutMode, ['cashfree', 'razorpay'], true)) {
            $_SESSION['checkout_payment_autolaunch'][$orderNumber] = true;
        }

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
        $minRejection = MarketingSettings::checkoutMinimumRejection($totals);
        if ($minRejection !== null) {
            return ['ok' => false, 'message' => $minRejection];
        }
        foreach (CartService::items() as $line) {
            $product = app_catalogue()->product((string) ($line['product_slug'] ?? ''));
            if ($product === null) {
                return ['ok' => false, 'message' => 'An item in your cart is no longer available.'];
            }
            $variant = null;
            foreach ($product['variants'] ?? [] as $v) {
                if ((string) ($v['id'] ?? '') === (string) ($line['variant_id'] ?? '')) {
                    $variant = $v;
                    break;
                }
            }
            if ($variant === null) {
                return ['ok' => false, 'message' => 'Please update your cart — a selected size is unavailable.'];
            }
            $check = CartQuantityRules::validateForCatalogueVariant($variant, (int) ($line['qty'] ?? 0));
            if (!$check['ok']) {
                return ['ok' => false, 'message' => (string) ($check['message'] ?? 'Update your cart.')];
            }
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
