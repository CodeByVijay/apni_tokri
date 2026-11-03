<?php

declare(strict_types=1);

final class SubscriptionFulfilmentService
{
    public const IDEMPOTENCY_PREFIX = 'sub_fulfil_day_';

    /**
     * Generate orders for confirmed baskets due on or before today (Asia/Kolkata).
     *
     * @return array{ok: bool, processed: int, skipped: int, awaiting_substitution: int, errors: list<string>, run_id: string}
     */
    public static function run(?DateTimeImmutable $now = null, ?string $runId = null): array
    {
        if (PHP_SAPI !== 'cli') {
            return [
                'ok' => false,
                'processed' => 0,
                'skipped' => 0,
                'awaiting_substitution' => 0,
                'errors' => ['CLI only.'],
                'run_id' => '',
            ];
        }
        Install::ensureConnection();
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        if ($now === null) {
            $now = new DateTimeImmutable('now', $tz);
        }
        if ($runId === null) {
            $runId = 'fulfil_' . $now->format('YmdHis') . '_' . substr(Security::randomToken(4), 0, 6);
        }
        $today = $now->format('Y-m-d');

        $stmt = Database::pdo()->prepare(
            "SELECT d.id AS day_id
             FROM subscription_delivery_days d
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             INNER JOIN subscription_delivery_baskets b ON b.subscription_delivery_day_id = d.id
             LEFT JOIN subscription_fulfilment_events fe ON fe.subscription_delivery_day_id = d.id AND fe.status = 'completed'
             WHERE cs.status = 'active'
               AND d.status = 'scheduled'
               AND d.delivery_date <= ?
               AND b.status = 'confirmed'
               AND d.basket_status = 'confirmed'
               AND fe.id IS NULL
               AND d.fulfilment_status IN ('none', 'failed', 'pending', 'slot_unavailable')
             ORDER BY d.delivery_date, d.id
             LIMIT 200"
        );
        $stmt->execute([$today]);
        $dayIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $processed = 0;
        $skipped = 0;
        $awaiting = 0;
        $errors = [];
        foreach ($dayIds as $dayId) {
            $result = self::generateForDay((int) $dayId, $runId, $now);
            if (!empty($result['idempotent'])) {
                $skipped++;
            } elseif (!empty($result['awaiting_substitution'])) {
                $awaiting++;
            } elseif ($result['ok']) {
                $processed++;
            } else {
                $errors[] = 'Day ' . $dayId . ': ' . ($result['message'] ?? 'failed');
            }
        }

        return [
            'ok' => true,
            'processed' => $processed,
            'skipped' => $skipped,
            'awaiting_substitution' => $awaiting,
            'errors' => $errors,
            'run_id' => $runId,
        ];
    }

    /**
     * @return array{ok: bool, idempotent?: bool, awaiting_substitution?: bool, message?: string, order_number?: string}
     */
    public static function generateForDay(int $dayId, string $runId, ?DateTimeImmutable $now = null): array
    {
        $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
        if ($now === null) {
            $now = new DateTimeImmutable('now', $tz);
        }
        $idempotencyKey = self::IDEMPOTENCY_PREFIX . $dayId;

        try {
            return Database::transaction(static function () use ($dayId, $runId, $idempotencyKey, $now): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT d.*, cs.id AS sub_id, cs.subscription_number, cs.customer_id, cs.plan_snapshot_json,
                            cs.status AS sub_status, cs.preferred_delivery_slot_slug AS subscription_preferred_slug
                     FROM subscription_delivery_days d
                     INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
                     WHERE d.id = ? FOR UPDATE'
                );
                $stmt->execute([$dayId]);
                $day = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$day) {
                    return ['ok' => false, 'message' => 'Day not found.'];
                }
                if ((string) $day['sub_status'] !== 'active' || (string) $day['status'] !== 'scheduled') {
                    return ['ok' => true, 'idempotent' => true];
                }

                $evStmt = $pdo->prepare(
                    'SELECT * FROM subscription_fulfilment_events WHERE subscription_delivery_day_id = ? LIMIT 1 FOR UPDATE'
                );
                $evStmt->execute([$dayId]);
                $existingEv = $evStmt->fetch(PDO::FETCH_ASSOC);
                if ($existingEv !== false) {
                    $evStatus = (string) $existingEv['status'];
                    if ($evStatus === 'completed' && !empty($existingEv['order_id'])) {
                        return ['ok' => true, 'idempotent' => true];
                    }
                    if ($evStatus === 'awaiting_substitution' && SubscriptionSubstitutionService::dayHasBlockingSubstitutions($dayId, $pdo)) {
                        return ['ok' => false, 'awaiting_substitution' => true, 'message' => 'Awaiting substitution.'];
                    }
                    if ($evStatus === 'pending') {
                        $updated = (string) ($existingEv['updated_at'] ?? '');
                        $stale = $updated !== '' && strtotime($updated) < time() - 1800;
                        if (!$stale) {
                            return ['ok' => false, 'message' => 'Fulfilment already in progress.'];
                        }
                        SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                        $pdo->prepare(
                            "UPDATE subscription_fulfilment_events SET status = 'failed', error_message = 'Stale pending recovered', updated_at = NOW()
                             WHERE subscription_delivery_day_id = ?"
                        )->execute([$dayId]);
                    }
                }

                $dedOnly = $pdo->prepare(
                    "SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ? AND status = 'deducted'"
                );
                $dedOnly->execute([$dayId]);
                if ((int) $dedOnly->fetchColumn() > 0 && empty($day['generated_order_id'])) {
                    throw new RuntimeException('Interrupted fulfilment — run reservation recovery.');
                }

                $basket = SubscriptionBasketRepository::basketByDayId($dayId);
                if ($basket === null || (string) $basket['status'] !== 'confirmed') {
                    return ['ok' => false, 'message' => 'Basket not confirmed.'];
                }
                $items = SubscriptionBasketRepository::basketItems((int) $basket['id']);
                if ($items === []) {
                    return ['ok' => false, 'message' => 'Incomplete basket (no lines).'];
                }

                if (SubscriptionSubstitutionService::dayHasBlockingSubstitutions($dayId, $pdo)) {
                    return ['ok' => false, 'awaiting_substitution' => true, 'message' => 'Pending substitution.'];
                }

                $stockLines = [];
                foreach ($items as $item) {
                    $qty = (int) round((float) ($item['qty'] ?? 0));
                    if ($qty <= 0) {
                        return ['ok' => false, 'message' => 'Invalid basket quantity.'];
                    }
                    $stockLines[] = ['variant_id' => (int) $item['variant_id'], 'qty' => $qty];
                }

                $hold = SubscriptionInventoryReserveService::holdForDay($dayId, $stockLines, $pdo);
                if (!$hold['ok']) {
                    $prop = SubscriptionSubstitutionService::proposeForStockShortage($dayId, $items, $pdo);
                    if (($prop['created'] ?? 0) > 0) {
                        self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'awaiting_substitution', null, 'Stock shortage — substitution proposed');
                        $pdo->prepare(
                            "UPDATE subscription_delivery_days SET fulfilment_status = 'awaiting_substitution', updated_at = NOW() WHERE id = ?"
                        )->execute([$dayId]);
                        SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                        self::notifySubstitutionRequest((int) $day['customer_id'], $dayId, (string) $day['subscription_number']);

                        return ['ok' => false, 'awaiting_substitution' => true, 'message' => 'Substitution required.'];
                    }

                    return ['ok' => false, 'message' => $hold['message'] ?? 'Insufficient stock.'];
                }

                $address = self::defaultAddress($pdo, (int) $day['customer_id']);
                if ($address === null) {
                    SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                    self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'failed', null, 'No delivery address');

                    return ['ok' => false, 'message' => 'Customer has no delivery address.'];
                }

                $deliveryDate = (string) $day['delivery_date'];
                $slotSlug = 'auto';
                $slotId = null;
                $deliveryEta = null;

                if (DeliveryConfig::slotsEnabled()) {
                    $slotResolved = SubscriptionSlotService::resolveForDay($day, $deliveryDate);
                    if (!$slotResolved['ok']) {
                        SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                        $msg = (string) ($slotResolved['message'] ?? 'Slot unavailable');
                        if (!empty($slotResolved['unavailable'])) {
                            $pdo->prepare(
                                'UPDATE subscription_delivery_days SET fulfilment_status = ?, slot_snapshot_json = ?, updated_at = NOW() WHERE id = ?'
                            )->execute([
                                'slot_unavailable',
                                json_encode(['error' => $msg, 'preferred' => $day['preferred_delivery_slot_slug'] ?? null], JSON_UNESCAPED_UNICODE),
                                $dayId,
                            ]);
                            self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'failed', null, $msg);
                            self::notifySlotUnavailable((int) $day['customer_id'], $dayId, $msg);

                            return ['ok' => false, 'message' => $msg];
                        }
                        self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'failed', null, $msg);

                        return ['ok' => false, 'message' => $msg];
                    }
                    $slotPick = $slotResolved['slot'] ?? null;
                    if ($slotPick === null) {
                        SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);

                        return ['ok' => false, 'message' => 'No slot.'];
                    }
                    $slotSlug = (string) $slotPick['slug'];
                    $slotId = (int) ($slotPick['id'] ?? 0);
                } else {
                    $deliveryEta = DeliverySlotService::automaticEta();
                }

                $cartLines = self::cartLinesForQuote($pdo, $items);
                $quote = DeliveryQuoteService::quoteForCheckout(
                    $address,
                    $cartLines,
                    $slotSlug,
                    null,
                    $pdo,
                    null,
                    $deliveryDate
                );
                if (empty($quote['ok'])) {
                    return self::failDeliveryQuote(
                        $pdo,
                        $dayId,
                        $day,
                        $idempotencyKey,
                        $runId,
                        $quote
                    );
                }

                $zoneId = isset($quote['zone_id']) ? (int) $quote['zone_id'] : null;
                if ($zoneId === 0) {
                    $zoneId = null;
                }
                if ($slotId === null && !empty($quote['slot_id'])) {
                    $slotId = (int) $quote['slot_id'];
                }
                if ($deliveryEta === null && !empty($quote['delivery_eta'])) {
                    $deliveryEta = $quote['delivery_eta'];
                }
                $custStmt = $pdo->prepare('SELECT name, mobile FROM customers WHERE id = ? LIMIT 1');
                $custStmt->execute([(int) $day['customer_id']]);
                $addrSnapshot = DeliveryAddressSnapshot::fromAddressRow($address, $custStmt->fetch(PDO::FETCH_ASSOC) ?: null);
                $snapshot = json_encode(
                    DeliveryQuoteService::buildSubscriptionOrderSnapshot($quote, [
                        'subscription_number' => (string) $day['subscription_number'],
                        'day_id' => $dayId,
                    ], $addrSnapshot),
                    JSON_UNESCAPED_UNICODE
                );

                $subtotal = (float) $basket['total_inr'];
                $orderNumber = 'AT-SUB-' . date('ymd') . '-' . strtoupper(substr(Security::randomToken(4), 0, 6));

                self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'pending', null, null);

                $pdo->prepare(
                    'INSERT INTO orders (order_number, order_source, subscription_delivery_day_id, customer_id, customer_address_id,
                     status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot,
                     delivery_zone_id, delivery_slot_id, delivery_date, delivery_eta, delivery_snapshot_json, placed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    $orderNumber,
                    'subscription',
                    $dayId,
                    (int) $day['customer_id'],
                    (int) $address['id'],
                    'confirmed',
                    $subtotal,
                    0.0,
                    'paid',
                    $slotSlug,
                    $zoneId,
                    $slotId,
                    $deliveryDate,
                    $deliveryEta,
                    $snapshot,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, sku, quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($items as $item) {
                    $qty = (int) round((float) ($item['qty'] ?? 0));
                    $unit = (float) $item['unit_price_inr'];
                    $itemStmt->execute([
                        $orderId,
                        (int) $item['variant_id'],
                        (string) $item['product_name'],
                        (string) $item['variant_name'],
                        null,
                        $qty,
                        $unit,
                        round($unit * $qty, 2),
                    ]);
                }

                $reason = 'Subscription ' . $day['subscription_number'] . ' delivery ' . $deliveryDate;
                if (!SubscriptionInventoryReserveService::deductHeldLines($dayId, $stockLines, $reason, $pdo)) {
                    throw new RuntimeException('Stock deduction failed (reservation mismatch).');
                }

                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note) VALUES (?, ?, ?)'
                )->execute([
                    $orderId,
                    'confirmed',
                    'Subscription prepaid fulfilment — no additional payment',
                ]);

                DeliveryAssignmentService::createForOrder($orderId);

                $pdo->prepare(
                    'UPDATE subscription_fulfilment_events SET status = ?, order_id = ?, updated_at = NOW()
                     WHERE subscription_delivery_day_id = ?'
                )->execute(['completed', $orderId, $dayId]);
                $pdo->prepare(
                    "UPDATE subscription_delivery_days SET fulfilment_status = 'generated', generated_order_id = ?, updated_at = NOW() WHERE id = ?"
                )->execute([$orderId, $dayId]);

                SubscriptionAudit::log(
                    (int) $day['sub_id'],
                    $deliveryDate,
                    'system',
                    null,
                    'delivery_order_generated',
                    ['order_id' => $orderId, 'order_number' => $orderNumber, 'cron_run_id' => $runId]
                );

                self::notifyOrderGenerated((int) $day['customer_id'], $dayId, $orderNumber, $deliveryDate);

                return ['ok' => true, 'order_number' => $orderNumber];
            });
        } catch (Throwable $e) {
            try {
                Database::transaction(static function () use ($dayId, $idempotencyKey, $runId, $e): void {
                    $pdo = Database::pdo();
                    SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
                    self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'failed', null, $e->getMessage());
                    $pdo->prepare(
                        "UPDATE subscription_delivery_days SET fulfilment_status = 'failed', updated_at = NOW() WHERE id = ?"
                    )->execute([$dayId]);
                });
            } catch (Throwable $inner) {
                error_log('Fulfilment recovery failed: ' . $inner->getMessage());
            }

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function idempotencyKeyForDay(int $dayId): string
    {
        return self::IDEMPOTENCY_PREFIX . $dayId;
    }

    /** @return list<array<string, mixed>> */
    public static function adminUpcoming(int $limit = 100): array
    {
        $result = self::searchUpcomingForAdmin('', 1, min(100, max(1, $limit)), 'delivery_date', 'asc');

        return $result['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchUpcomingForAdmin(
        string $query,
        int $page,
        int $perPage,
        string $sortColumn = 'delivery_date',
        string $sortDirection = 'asc'
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = [
            "cs.status IN ('active', 'paused')",
            "d.status = 'scheduled'",
            "d.basket_status = 'confirmed'",
            'd.delivery_date >= CURDATE() - INTERVAL 3 DAY',
        ];
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(cs.subscription_number LIKE ? OR c.mobile LIKE ? OR c.name LIKE ? OR o.order_number LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'delivery_date' => 'd.delivery_date',
            'subscription_number' => 'cs.subscription_number',
            'total_inr' => 'b.total_inr',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'delivery_date');
        $from = ' FROM subscription_delivery_days d
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id
             INNER JOIN customers c ON c.id = cs.customer_id
             LEFT JOIN subscription_delivery_baskets b ON b.subscription_delivery_day_id = d.id
             LEFT JOIN orders o ON o.id = d.generated_order_id
             LEFT JOIN deliveries del ON del.order_id = o.id
             WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT d.id AS day_id, d.delivery_date, d.status AS day_status, d.basket_status, d.fulfilment_status,
                    cs.subscription_number, cs.id AS subscription_id, c.mobile, c.name AS customer_name,
                    o.order_number, o.status AS order_status, o.payment_status,
                    del.id AS delivery_id, del.status AS delivery_status, b.total_inr" . $from
            . ' ORDER BY ' . $orderSql . ', d.id ASC LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function defaultAddress(PDO $pdo, int $customerId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM customer_addresses WHERE customer_id = ? AND archived_at IS NULL ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param list<array<string, mixed>> $basketItems
     * @return list<array<string, mixed>>
     */
    private static function cartLinesForQuote(PDO $pdo, array $basketItems): array
    {
        $lines = [];
        $grantStmt = $pdo->prepare(
            'SELECT p.grants_free_delivery FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.id = ? LIMIT 1'
        );
        foreach ($basketItems as $item) {
            $variantId = (int) ($item['variant_id'] ?? 0);
            $grantStmt->execute([$variantId]);
            $grants = (bool) $grantStmt->fetchColumn();
            $qty = (int) round((float) ($item['qty'] ?? 0));
            $lines[] = [
                'variant_id' => $variantId,
                'qty' => $qty,
                'unit_price' => (float) ($item['unit_price_inr'] ?? 0),
                'grants_free_delivery' => $grants,
            ];
        }

        return $lines;
    }

    /**
     * Release inventory and record fulfilment failure when D4 quote validation fails (D5).
     *
     * @param array<string, mixed> $day subscription_delivery_days row fragment
     * @param array<string, mixed> $quote failed quote payload
     * @return array{ok: false, message: string}
     */
    private static function failDeliveryQuote(
        PDO $pdo,
        int $dayId,
        array $day,
        string $idempotencyKey,
        string $runId,
        array $quote
    ): array {
        SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);
        $msg = (string) ($quote['message'] ?? 'Delivery quote failed.');
        $code = (string) ($quote['failure_code'] ?? 'quote_failed');
        $slotCodes = ['slot_full', 'slot_unavailable', 'slot_invalid', 'provider_unavailable'];
        if (DeliveryConfig::slotsEnabled() && in_array($code, $slotCodes, true)) {
            $pdo->prepare(
                'UPDATE subscription_delivery_days SET fulfilment_status = ?, slot_snapshot_json = ?, updated_at = NOW() WHERE id = ?'
            )->execute([
                'slot_unavailable',
                json_encode([
                    'error' => $msg,
                    'failure_code' => $code,
                    'preferred' => $day['preferred_delivery_slot_slug'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
                $dayId,
            ]);
            self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'failed', null, $msg);
            self::notifySlotUnavailable((int) $day['customer_id'], $dayId, $msg);

            return ['ok' => false, 'message' => $msg];
        }

        $pdo->prepare(
            "UPDATE subscription_delivery_days SET fulfilment_status = 'failed', updated_at = NOW() WHERE id = ?"
        )->execute([$dayId]);
        self::upsertFulfilmentEvent($pdo, $dayId, $idempotencyKey, $runId, 'failed', null, '[' . $code . '] ' . $msg);

        return ['ok' => false, 'message' => $msg];
    }

    private static function upsertFulfilmentEvent(
        PDO $pdo,
        int $dayId,
        string $idempotencyKey,
        string $runId,
        string $status,
        ?int $orderId,
        ?string $error
    ): void {
        $pdo->prepare(
            'INSERT INTO subscription_fulfilment_events
             (subscription_delivery_day_id, idempotency_key, cron_run_id, status, order_id, error_message)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), order_id = COALESCE(VALUES(order_id), order_id),
             error_message = VALUES(error_message), cron_run_id = VALUES(cron_run_id), updated_at = NOW()'
        )->execute([$dayId, $idempotencyKey, $runId, $status, $orderId, $error]);
    }

    private static function notifyOrderGenerated(int $customerId, int $dayId, string $orderNumber, string $deliveryDate): void
    {
        SubscriptionNotificationDedupeService::notifyOnce(
            $customerId,
            'subscription_order_generated',
            'day_' . $dayId . '_order',
            'Subscription delivery scheduled',
            'Your order ' . $orderNumber . ' was created for delivery on ' . $deliveryDate . '.',
            ['order_number' => $orderNumber, 'delivery_date' => $deliveryDate, 'day_id' => $dayId]
        );
    }

    private static function notifySubstitutionRequest(int $customerId, int $dayId, string $subNumber): void
    {
        SubscriptionNotificationDedupeService::notifyOnce(
            $customerId,
            'subscription_substitution',
            'day_' . $dayId . '_substitution',
            'Vegetable substitution needed',
            'An item in your ' . $subNumber . ' basket needs your approval. Open subscription details to review.',
            ['subscription_delivery_day_id' => $dayId]
        );
    }

    private static function notifySlotUnavailable(int $customerId, int $dayId, string $message): void
    {
        SubscriptionNotificationDedupeService::notifyOnce(
            $customerId,
            'subscription_slot_unavailable',
            'day_' . $dayId . '_slot',
            'Delivery slot unavailable',
            $message . ' Your preferred slot was not changed automatically — update it in subscription details if needed.',
            ['day_id' => $dayId]
        );
    }
}
