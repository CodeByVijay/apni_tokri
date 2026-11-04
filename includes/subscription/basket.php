<?php

declare(strict_types=1);

final class SubscriptionBasketRepository
{
    /** @return array<string, mixed>|null */
    public static function dayByIdForSubscription(int $dayId, int $subscriptionId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subscription_delivery_days WHERE id = ? AND customer_subscription_id = ? LIMIT 1'
        );
        $stmt->execute([$dayId, $subscriptionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function basketByDayId(int $dayId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subscription_delivery_baskets WHERE subscription_delivery_day_id = ? LIMIT 1'
        );
        $stmt->execute([$dayId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function basketItems(int $basketId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM subscription_delivery_basket_items WHERE basket_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$basketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

final class SubscriptionBasketService
{
    /**
     * @return array{ok: bool, basket?: array<string, mixed>, items?: list<array<string, mixed>>, skipped?: list<array<string, mixed>>, message?: string}
     */
    public static function ensureSuggestedBasket(
        array $subscriptionRow,
        array $dayRow,
        array $snapshot
    ): array {
        $dayId = (int) $dayRow['id'];
        $existing = SubscriptionBasketRepository::basketByDayId($dayId);
        if ($existing !== null) {
            return [
                'ok' => true,
                'basket' => $existing,
                'items' => SubscriptionBasketRepository::basketItems((int) $existing['id']),
                'skipped' => self::decodeGenerationSkipped($existing),
            ];
        }

        $built = self::buildSuggestedLines(
            $subscriptionRow,
            $dayRow,
            $snapshot
        );
        $lines = $built['lines'];
        $skipped = $built['skipped'];
        if ($lines === []) {
            return [
                'ok' => false,
                'message' => $skipped !== []
                    ? 'No items could be added to your suggested basket. See the skipped menu items below.'
                    : 'No menu items available for this delivery day.',
                'skipped' => $skipped,
            ];
        }

        $allowance = self::dailyAllowance($snapshot);
        $total = self::sumLines($lines);
        $skippedJson = $skipped !== [] ? json_encode($skipped, JSON_UNESCAPED_UNICODE) : null;

        return Database::transaction(static function () use ($dayId, $lines, $allowance, $total, $subscriptionRow, $dayRow, $skipped, $skippedJson): array {
            $pdo = Database::pdo();
            if (self::generationSkippedColumnReady()) {
                $pdo->prepare(
                    'INSERT INTO subscription_delivery_baskets
                     (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, generation_skipped_json)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$dayId, 'suggested', 'suggested', $allowance, $total, $skippedJson]);
            } else {
                $pdo->prepare(
                    'INSERT INTO subscription_delivery_baskets
                     (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$dayId, 'suggested', 'suggested', $allowance, $total]);
            }
            $basketId = (int) $pdo->lastInsertId();
            self::insertItems($pdo, $basketId, $lines);
            $pdo->prepare(
                'UPDATE subscription_delivery_days SET basket_status = ?, updated_at = NOW() WHERE id = ?'
            )->execute(['suggested', $dayId]);

            SubscriptionAudit::log(
                (int) $subscriptionRow['id'],
                (string) $dayRow['delivery_date'],
                'system',
                null,
                'suggested_basket_generated',
                ['total_inr' => $total, 'line_count' => count($lines), 'skipped' => $skipped]
            );

            $basket = SubscriptionBasketRepository::basketByDayId($dayId);

            return [
                'ok' => true,
                'basket' => $basket,
                'items' => SubscriptionBasketRepository::basketItems($basketId),
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * @param list<array{variant_id: int, qty: float}> $requestedLines
     * @return array{ok: bool, message?: string}
     */
    public static function saveCustomerDays(
        int $customerId,
        string $subscriptionNumber,
        array $requestedDays,
        bool $confirm
    ): array {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Subscription not found or not active.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $subId = (int) $sub['id'];

        try {
            Database::transaction(static function () use ($subId, $snapshot, $requestedDays, $confirm, $customerId, $sub): void {
                foreach ($requestedDays as $dayId => $payload) {
                    $dayId = (int) $dayId;
                    $day = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subId);
                    if ($day === null) {
                        throw new RuntimeException('Invalid delivery day.');
                    }
                    if (SubscriptionCutoff::isLocked($day)) {
                        throw new RuntimeException('Cutoff passed for ' . $day['delivery_date'] . '.');
                    }
                    $linesIn = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
                    $normalized = self::normalizeCustomerLines($linesIn, $snapshot, $customerId);
                    if (!$normalized['ok']) {
                        throw new RuntimeException($normalized['message'] ?? 'Invalid basket.');
                    }
                    $lines = $normalized['lines'];
                    $total = self::sumLines($lines);
                    $allowance = self::dailyAllowance($snapshot);
                    if ($total > $allowance + 0.01) {
                        throw new RuntimeException(
                            'Basket total exceeds your daily allowance. No extra charges are applied — reduce items or remove lines.'
                        );
                    }

                    $basket = SubscriptionBasketRepository::basketByDayId($dayId);
                    $pdo = Database::pdo();
                    if ($basket === null) {
                        $pdo->prepare(
                            'INSERT INTO subscription_delivery_baskets
                             (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr)
                             VALUES (?, ?, ?, ?, ?)'
                        )->execute([
                            $dayId,
                            'custom',
                            $confirm ? 'confirmed' : 'draft',
                            $allowance,
                            $total,
                        ]);
                        $basketId = (int) $pdo->lastInsertId();
                    } else {
                        if ((string) $basket['status'] === 'confirmed' && !$confirm) {
                            throw new RuntimeException('Confirmed baskets cannot be edited without admin override.');
                        }
                        $basketId = (int) $basket['id'];
                        $pdo->prepare('DELETE FROM subscription_delivery_basket_items WHERE basket_id = ?')->execute([$basketId]);
                        $pdo->prepare(
                            'UPDATE subscription_delivery_baskets SET source_type = ?, status = ?, allowance_inr = ?, total_inr = ?,
                             confirmed_at = ?, updated_at = NOW() WHERE id = ?'
                        )->execute([
                            'custom',
                            $confirm ? 'confirmed' : 'draft',
                            $allowance,
                            $total,
                            $confirm ? date('Y-m-d H:i:s') : null,
                            $basketId,
                        ]);
                    }
                    self::insertItems($pdo, $basketId, $lines);
                    $pdo->prepare(
                        'UPDATE subscription_delivery_days SET basket_status = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$confirm ? 'confirmed' : 'custom', $dayId]);

                    SubscriptionAudit::log(
                        $subId,
                        (string) $day['delivery_date'],
                        'customer',
                        $customerId,
                        $confirm ? 'basket_confirmed' : 'basket_customized',
                        ['total_inr' => $total, 'allowance_inr' => $allowance, 'lines' => count($lines)]
                    );
                }
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message?: string} */
    public static function acceptSuggested(int $customerId, string $subscriptionNumber, int $dayId): array
    {
        $sub = CustomerSubscriptionRepository::findByNumberForCustomer($subscriptionNumber, $customerId);
        if ($sub === null || (string) $sub['status'] !== 'active') {
            return ['ok' => false, 'message' => 'Subscription not found.'];
        }
        $day = SubscriptionBasketRepository::dayByIdForSubscription($dayId, (int) $sub['id']);
        if ($day === null) {
            return ['ok' => false, 'message' => 'Delivery day not found.'];
        }
        if (SubscriptionCutoff::isLocked($day)) {
            return ['ok' => false, 'message' => 'Cutoff has passed for this delivery day.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $gen = self::ensureSuggestedBasket($sub, $day, $snapshot);
        if (!$gen['ok']) {
            return ['ok' => false, 'message' => $gen['message'] ?? 'Could not load suggestion.'];
        }
        $items = $gen['items'] ?? [];
        $lines = [];
        foreach ($items as $it) {
            $lines[] = [
                'variant_id' => (int) $it['variant_id'],
                'qty' => (float) $it['qty'],
            ];
        }

        return self::saveCustomerDays($customerId, $subscriptionNumber, [
            $dayId => ['lines' => $lines],
        ], true);
    }

    /** @return array{ok: bool, message?: string, carry_forward_date?: string} */
    public static function adminOverrideDay(
        int $adminId,
        int $subscriptionId,
        int $dayId,
        array $lines,
        bool $confirm,
        string $reason = '',
        ?array $beforeItems = null
    ): array {
        return self::adminOverrideDayWithAudit($adminId, $subscriptionId, $dayId, $lines, $confirm, $reason, $beforeItems);
    }

    /**
     * @param list<array<string, mixed>>|null $beforeItems
     * @param list<array{variant_id: int, qty: float}> $lines
     * @return array{ok: bool, message?: string}
     */
    public static function adminOverrideDayWithAudit(
        int $adminId,
        int $subscriptionId,
        int $dayId,
        array $lines,
        bool $confirm,
        string $reason = '',
        ?array $beforeItems = null
    ): array {
        $sub = CustomerSubscriptionRepository::findById($subscriptionId);
        if ($sub === null) {
            return ['ok' => false, 'message' => 'Subscription not found.'];
        }
        $day = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subscriptionId);
        if ($day === null) {
            return ['ok' => false, 'message' => 'Day not found.'];
        }
        $snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
        $normalized = self::normalizeCustomerLines($lines, $snapshot, (int) $sub['customer_id']);
        if (!$normalized['ok']) {
            return ['ok' => false, 'message' => $normalized['message'] ?? 'Invalid lines.'];
        }
        $total = self::sumLines($normalized['lines']);
        $allowance = self::dailyAllowance($snapshot);
        if ($total > $allowance + 0.01 && empty($snapshot['basket_rules_json']['over_allowance_requires_approval'])) {
            return ['ok' => false, 'message' => 'Over allowance.'];
        }

        try {
            Database::transaction(static function () use (
                $dayId,
                $subscriptionId,
                $day,
                $normalized,
                $total,
                $allowance,
                $confirm,
                $adminId,
                $reason,
                $beforeItems,
                $sub
            ): void {
                $pdo = Database::pdo();
                $basket = SubscriptionBasketRepository::basketByDayId($dayId);
                $beforeSnapshot = [
                    'items' => $beforeItems ?? ($basket ? SubscriptionBasketRepository::basketItems((int) $basket['id']) : []),
                    'status' => $basket ? (string) $basket['status'] : 'none',
                    'total_inr' => $basket ? (float) $basket['total_inr'] : 0,
                ];
                if ($basket === null) {
                    $pdo->prepare(
                        'INSERT INTO subscription_delivery_baskets
                         (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, confirmed_at)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $dayId,
                        'custom',
                        $confirm ? 'confirmed' : 'draft',
                        $allowance,
                        $total,
                        $confirm ? date('Y-m-d H:i:s') : null,
                    ]);
                    $basketId = (int) $pdo->lastInsertId();
                } else {
                    $basketId = (int) $basket['id'];
                    $pdo->prepare('DELETE FROM subscription_delivery_basket_items WHERE basket_id = ?')->execute([$basketId]);
                    $pdo->prepare(
                        'UPDATE subscription_delivery_baskets SET total_inr = ?, status = ?, confirmed_at = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$total, $confirm ? 'confirmed' : 'draft', $confirm ? date('Y-m-d H:i:s') : null, $basketId]);
                }
                self::insertItems($pdo, $basketId, $normalized['lines']);
                $pdo->prepare(
                    'UPDATE subscription_delivery_days SET basket_status = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$confirm ? 'confirmed' : 'custom', $dayId]);
                SubscriptionAudit::log(
                    $subscriptionId,
                    (string) $day['delivery_date'],
                    'admin',
                    $adminId,
                    'admin_basket_override',
                    [
                        'reason' => $reason,
                        'before' => $beforeSnapshot,
                        'after' => [
                            'total_inr' => $total,
                            'lines' => count($normalized['lines']),
                            'status' => $confirm ? 'confirmed' : 'draft',
                        ],
                        'confirmed' => $confirm,
                    ]
                );
            });

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{lines: list<array<string, mixed>>, skipped: list<array{product_name: string, variant_name: string, reason: string}>}
     */
    public static function buildSuggestedLines(array $subscriptionRow, array $dayRow, array $snapshot): array
    {
        $deliveryDate = (string) $dayRow['delivery_date'];
        $dayIndex = (int) $dayRow['day_index'];
        $menuLines = SubscriptionMenuRepository::resolveMenuLines(
            $deliveryDate,
            $dayIndex,
            isset($subscriptionRow['subscription_plan_id']) ? (int) $subscriptionRow['subscription_plan_id'] : null,
            isset($subscriptionRow['created_at']) ? (string) $subscriptionRow['created_at'] : null
        );
        if ($menuLines === []) {
            return ['lines' => [], 'skipped' => []];
        }

        $avoid = SubscriptionPreferenceRepository::avoidedVariantIds((int) $subscriptionRow['customer_id']);
        $allowance = self::dailyAllowance($snapshot);
        $maxItems = max(1, (int) ($snapshot['basket_rules_json']['max_line_items'] ?? 10));
        $rotationSeed = (int) $subscriptionRow['id'] + $dayIndex;
        $skipped = [];

        $pool = [];
        foreach ($menuLines as $ml) {
            $skipReason = self::suggestionSkipReason($ml, $avoid);
            if ($skipReason !== null) {
                $skipped[] = self::skippedEntry($ml, $skipReason);
                continue;
            }
            $pool[] = $ml;
        }
        if ($pool === []) {
            return ['lines' => [], 'skipped' => $skipped];
        }

        usort($pool, static function ($a, $b): int {
            return ((int) $a['sort_order']) <=> ((int) $b['sort_order']);
        });
        $start = $rotationSeed % count($pool);
        $ordered = array_merge(array_slice($pool, $start), array_slice($pool, 0, $start));

        $out = [];
        $spent = 0.0;
        foreach ($ordered as $ml) {
            if (count($out) >= $maxItems) {
                break;
            }
            $unitPrice = round((float) $ml['unit_price_inr'], 2);
            if ($unitPrice <= 0) {
                $skipped[] = self::skippedEntry($ml, 'Invalid menu price');
                continue;
            }
            $defaultQty = (float) $ml['default_qty'];
            $maxQty = isset($ml['max_qty']) && $ml['max_qty'] !== null ? (float) $ml['max_qty'] : $defaultQty;
            $stockCap = (int) ($ml['stock_qty'] ?? 0);
            $qty = min($defaultQty, $maxQty, (float) $stockCap);
            if ($qty <= 0) {
                $skipped[] = self::skippedEntry($ml, 'Insufficient stock for the suggested quantity');
                continue;
            }
            $lineTotal = round($qty * $unitPrice, 2);
            if ($spent + $lineTotal > $allowance + 0.01) {
                $affordable = floor(($allowance - $spent) / $unitPrice * 1000) / 1000;
                if ($affordable <= 0) {
                    $skipped[] = self::skippedEntry($ml, 'Would exceed your daily allowance');
                    break;
                }
                $qty = min($qty, $affordable);
                $lineTotal = round($qty * $unitPrice, 2);
            }
            if ($lineTotal <= 0) {
                $skipped[] = self::skippedEntry($ml, 'Would exceed your daily allowance');
                continue;
            }
            $out[] = self::lineFromMenu($ml, $qty, $unitPrice, $lineTotal);
            $spent += $lineTotal;
            if ($spent >= $allowance - 0.01) {
                break;
            }
        }

        return ['lines' => $out, 'skipped' => $skipped];
    }

    /** @return list<array<string, mixed>> */
    public static function plannedQuantityReport(string $fromDate, string $toDate): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT d.delivery_date, i.variant_id, i.product_name, i.variant_name, i.unit_label,
                    SUM(i.qty) AS total_qty, SUM(i.line_total_inr) AS total_inr
             FROM subscription_delivery_basket_items i
             INNER JOIN subscription_delivery_baskets b ON b.id = i.basket_id
             INNER JOIN subscription_delivery_days d ON d.id = b.subscription_delivery_day_id
             WHERE d.delivery_date BETWEEN ? AND ?
               AND b.status IN (\'confirmed\', \'suggested\', \'draft\')
             GROUP BY d.delivery_date, i.variant_id, i.product_name, i.variant_name, i.unit_label
             ORDER BY d.delivery_date, i.product_name'
        );
        $stmt->execute([$fromDate, $toDate]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, mixed> $snapshot */
    public static function dailyAllowance(array $snapshot): float
    {
        return round((float) ($snapshot['basket_rules_json']['daily_allowance_inr'] ?? 0), 2);
    }

    /** @param list<array<string, mixed>> $lines */
    public static function sumLines(array $lines): float
    {
        $t = 0.0;
        foreach ($lines as $l) {
            $t += (float) ($l['line_total_inr'] ?? 0);
        }

        return round($t, 2);
    }

    /**
     * @param list<array{variant_id: int, qty: float}> $raw
     * @return array{ok: bool, lines?: list<array<string, mixed>>, message?: string}
     */
    private static function normalizeCustomerLines(array $raw, array $snapshot, int $customerId): array
    {
        $maxItems = max(1, (int) ($snapshot['basket_rules_json']['max_line_items'] ?? 10));
        if (count($raw) > $maxItems) {
            return ['ok' => false, 'message' => 'Too many line items for this plan.'];
        }

        $lines = [];
        $seen = [];
        foreach ($raw as $row) {
            $vid = (int) ($row['variant_id'] ?? 0);
            $qty = (float) ($row['qty'] ?? 0);
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            if (isset($seen[$vid])) {
                return ['ok' => false, 'message' => 'Duplicate items are not allowed.'];
            }
            $seen[$vid] = true;
            $priced = self::priceVariantLine($vid, $qty, $customerId);
            if (!$priced['ok']) {
                return $priced;
            }
            $lines[] = $priced['line'];
        }

        return ['ok' => true, 'lines' => $lines];
    }

    /**
     * @return array{ok: bool, line?: array<string, mixed>, message?: string}
     */
    private static function priceVariantLine(int $variantId, float $qty, int $customerId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT v.id, v.name AS variant_name, v.price, v.sale_price, v.stock_qty, v.is_active,
                    p.name AS product_name, p.status AS product_status
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.id = ? LIMIT 1'
        );
        $stmt->execute([$variantId]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$v || !(int) $v['is_active'] || (string) $v['product_status'] !== 'published') {
            return ['ok' => false, 'message' => 'Product is not available.'];
        }
        $stock = (int) $v['stock_qty'];
        if ($stock <= 0) {
            return ['ok' => false, 'message' => 'Insufficient stock for ' . ($v['variant_name'] ?? 'item') . '.'];
        }
        if ($qty > $stock) {
            return ['ok' => false, 'message' => 'Requested quantity exceeds available stock.'];
        }

        $menuPrice = self::menuUnitPriceForVariant($variantId);
        $unit = $menuPrice ?? round((float) ($v['sale_price'] ?? $v['price']), 2);
        if ($unit <= 0) {
            return ['ok' => false, 'message' => 'Invalid item price.'];
        }
        $lineTotal = round($qty * $unit, 2);

        return [
            'ok' => true,
            'line' => [
                'variant_id' => $variantId,
                'product_name' => (string) $v['product_name'],
                'variant_name' => (string) $v['variant_name'],
                'unit_label' => 'unit',
                'qty' => $qty,
                'unit_price_inr' => $unit,
                'line_total_inr' => $lineTotal,
            ],
        ];
    }

    private static function menuUnitPriceForVariant(int $variantId): ?float
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT unit_price_inr FROM subscription_menu_template_items WHERE variant_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$variantId]);
        $p = $stmt->fetchColumn();
        if ($p === false) {
            return null;
        }

        return round((float) $p, 2);
    }

    /** @param array<string, mixed> $ml */
    private static function skippedEntry(array $ml, string $reason): array
    {
        return [
            'product_name' => (string) ($ml['product_name'] ?? ''),
            'variant_name' => (string) ($ml['variant_name'] ?? ''),
            'reason' => $reason,
        ];
    }

    /** @param list<int> $avoid */
    private static function suggestionSkipReason(array $ml, array $avoid): ?string
    {
        $vid = (int) ($ml['variant_id'] ?? 0);
        if ($vid > 0 && in_array($vid, $avoid, true)) {
            return 'On your avoid list';
        }
        if (empty($ml['include_in_rotation'])) {
            return 'Not included in rotation';
        }
        if (empty($ml['variant_active'])) {
            return 'Variant is inactive';
        }
        if ((string) ($ml['product_status'] ?? '') !== 'published') {
            return 'Product is not published';
        }
        if ((int) ($ml['stock_qty'] ?? 0) <= 0) {
            return 'Out of stock';
        }

        return null;
    }

    /** @param array<string, mixed> $basketRow */
    private static function decodeGenerationSkipped(array $basketRow): array
    {
        $raw = $basketRow['generation_skipped_json'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function generationSkippedColumnReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(['subscription_delivery_baskets', 'generation_skipped_json']);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @param array<string, mixed> $ml */
    private static function lineFromMenu(array $ml, float $qty, float $unitPrice, float $lineTotal): array
    {
        return [
            'variant_id' => (int) $ml['variant_id'],
            'product_name' => (string) $ml['product_name'],
            'variant_name' => (string) $ml['variant_name'],
            'unit_label' => (string) ($ml['unit_label'] ?? 'kg'),
            'qty' => $qty,
            'unit_price_inr' => $unitPrice,
            'line_total_inr' => $lineTotal,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private static function insertItems(PDO $pdo, int $basketId, array $lines): void
    {
        $ins = $pdo->prepare(
            'INSERT INTO subscription_delivery_basket_items
             (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sort = 0;
        foreach ($lines as $l) {
            $ins->execute([
                $basketId,
                (int) $l['variant_id'],
                (string) $l['product_name'],
                (string) $l['variant_name'],
                (string) $l['unit_label'],
                (float) $l['qty'],
                (float) $l['unit_price_inr'],
                (float) $l['line_total_inr'],
                $sort++,
            ]);
        }
    }

    public static function backfillDayCutoff(array $dayRow, array $snapshot): void
    {
        if (!empty($dayRow['selection_cutoff_at'])) {
            return;
        }
        $hours = SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot);
        $cutoff = SubscriptionCutoff::cutoffDateTime((string) $dayRow['delivery_date'], $hours)->format('Y-m-d H:i:s');
        Database::pdo()->prepare(
            'UPDATE subscription_delivery_days SET selection_cutoff_at = ?, basket_status = IF(basket_status = \'\', \'none\', basket_status) WHERE id = ?'
        )->execute([$cutoff, (int) $dayRow['id']]);
    }
}
