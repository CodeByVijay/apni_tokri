<?php

declare(strict_types=1);

final class PromotionService
{
    /** @return list<array<string, mixed>> */
    public static function activeOffers(?int $customerId = null): array
    {
        if (!CommerceGateway::useDatabase()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            "SELECT * FROM offers WHERE is_active = 1
             AND (starts_at IS NULL OR starts_at <= NOW())
             AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY priority DESC, id DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($customerId === null) {
            return $rows;
        }
        $filtered = [];
        foreach ($rows as $row) {
            if (!self::offerAllowedForCustomer((int) $row['id'], $customerId)) {
                continue;
            }
            $filtered[] = $row;
        }

        return $filtered;
    }

    private static function offerAllowedForCustomer(int $offerId, int $customerId): bool
    {
        $pdo = Database::pdo();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM offer_customer_assignments WHERE offer_id = ?');
        $cnt->execute([$offerId]);
        if ((int) $cnt->fetchColumn() === 0) {
            return true;
        }
        $chk = $pdo->prepare(
            'SELECT 1 FROM offer_customer_assignments WHERE offer_id = ? AND customer_id = ? LIMIT 1'
        );
        $chk->execute([$offerId, $customerId]);

        return (bool) $chk->fetchColumn();
    }

    /**
     * One best automatic offer (no stacking between offers).
     *
     * @param list<array<string, mixed>> $lines qty, unit_price, product_id?, category_id?, variant_id?
     * @return array{discount: float, offer_id: int|null, label: string|null, stack_with_coupons: bool}
     */
    public static function bestAutomaticOffer(array $lines, ?int $customerId = null): array
    {
        $empty = ['discount' => 0.0, 'offer_id' => null, 'label' => null, 'stack_with_coupons' => false];
        if ($lines === [] || !CommerceGateway::useDatabase()) {
            return $empty;
        }
        $merchSubtotal = 0.0;
        foreach ($lines as $line) {
            $merchSubtotal += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
        }
        $best = $empty;
        $bestAmount = 0.0;
        foreach (self::activeOffers($customerId) as $offer) {
            $min = $offer['min_cart_value'] !== null ? (float) $offer['min_cart_value'] : 0.0;
            if ($merchSubtotal < $min) {
                continue;
            }
            $scope = (string) ($offer['scope'] ?? 'store');
            if ($scope === 'first_order') {
                if ($customerId === null || !self::isFirstPaidOrderCustomer($customerId)) {
                    continue;
                }
            }
            $eligibleSub = self::eligibleSubtotalForOffer($offer, $lines, $customerId);
            if ($eligibleSub <= 0) {
                continue;
            }
            $amount = self::discountFromRow($offer, $eligibleSub);
            if ($amount > $bestAmount) {
                $bestAmount = $amount;
                $best = [
                    'discount' => round($amount, 2),
                    'offer_id' => (int) $offer['id'],
                    'label' => (string) ($offer['name'] ?? 'Offer'),
                    'stack_with_coupons' => !empty($offer['stack_with_coupons']),
                ];
            }
        }

        return $best;
    }

    /** @param list<array<string, mixed>> $lines */
    private static function eligibleSubtotalForOffer(array $offer, array $lines, ?int $customerId): float
    {
        $scope = (string) ($offer['scope'] ?? 'store');
        $targetId = isset($offer['target_id']) ? (int) $offer['target_id'] : 0;
        $sum = 0.0;
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $cid = (int) ($line['category_id'] ?? 0);
            $vid = (int) ($line['variant_id'] ?? 0);
            $lineTotal = (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
            if ($scope === 'store' || $scope === 'first_order') {
                $sum += $lineTotal;
                continue;
            }
            if ($scope === 'category' && $targetId > 0 && $cid === $targetId) {
                $sum += $lineTotal;
            }
            if ($scope === 'product' && $targetId > 0 && $pid === $targetId) {
                $sum += $lineTotal;
            }
            if ($scope === 'variant' && $targetId > 0 && $vid === $targetId) {
                $sum += $lineTotal;
            }
        }

        return $sum;
    }

    /** @param array<string, mixed> $row */
    public static function discountFromRow(array $row, float $subtotal): float
    {
        $type = (string) ($row['discount_type'] ?? 'percent');
        $value = (float) ($row['discount_value'] ?? 0);
        if ($type === 'percent') {
            $d = round($subtotal * ($value / 100), 2);
            $max = $row['max_discount'] !== null ? (float) $row['max_discount'] : null;
            if ($max !== null) {
                $d = min($d, $max);
            }

            return min($d, $subtotal);
        }

        return min($value, $subtotal);
    }

    public static function isFirstPaidOrderCustomer(int $customerId): bool
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM orders WHERE customer_id = ? AND payment_status = 'paid'"
        );
        $stmt->execute([$customerId]);

        return (int) $stmt->fetchColumn() === 0;
    }
}

final class CouponRepository
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        Install::ensureConnection();

        return Database::pdo()->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM coupons WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<int> */
    public static function assignedCustomerIds(int $couponId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT customer_id FROM customer_coupon_assignments WHERE coupon_id = ?');
        $stmt->execute([$couponId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids[] = (int) $r['customer_id'];
        }

        return $ids;
    }

    /** @return list<int> */
    public static function categoryIds(int $couponId): array
    {
        return self::idList('coupon_categories', 'category_id', $couponId);
    }

    /** @return list<int> */
    public static function productIds(int $couponId): array
    {
        return self::idList('coupon_products', 'product_id', $couponId);
    }

    /** @return list<int> */
    public static function excludedProductIds(int $couponId): array
    {
        return self::idList('coupon_excluded_products', 'product_id', $couponId);
    }

    private static function idList(string $table, string $column, int $couponId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare("SELECT {$column} FROM {$table} WHERE coupon_id = ?");
        $stmt->execute([$couponId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids[] = (int) $r[$column];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<int> $customerIds
     * @param list<int> $categoryIds
     * @param list<int> $productIds
     * @param list<int> $excludedProductIds
     */
    public static function save(
        ?int $id,
        array $data,
        array $customerIds,
        array $categoryIds,
        array $productIds,
        array $excludedProductIds
    ): int {
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($id) {
            $pdo->prepare(
                'UPDATE coupons SET code = ?, internal_name = ?, discount_type = ?, discount_value = ?, min_cart_value = ?,
                 max_discount = ?, usage_limit = ?, per_customer_limit = ?, first_order_only = ?, stackable_with_offers = ?,
                 priority = ?, starts_at = ?, ends_at = ?, is_active = ? WHERE id = ?'
            )->execute([
                $data['code'],
                $data['internal_name'],
                $data['discount_type'],
                $data['discount_value'],
                $data['min_cart_value'],
                $data['max_discount'],
                $data['usage_limit'],
                $data['per_customer_limit'],
                $data['first_order_only'],
                $data['stackable_with_offers'],
                $data['priority'],
                $data['starts_at'],
                $data['ends_at'],
                $data['is_active'],
                $id,
            ]);
            $couponId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO coupons (code, internal_name, discount_type, discount_value, min_cart_value, max_discount,
                 usage_limit, per_customer_limit, first_order_only, stackable_with_offers, priority, starts_at, ends_at, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $data['code'],
                $data['internal_name'],
                $data['discount_type'],
                $data['discount_value'],
                $data['min_cart_value'],
                $data['max_discount'],
                $data['usage_limit'],
                $data['per_customer_limit'],
                $data['first_order_only'],
                $data['stackable_with_offers'],
                $data['priority'],
                $data['starts_at'],
                $data['ends_at'],
                $data['is_active'],
            ]);
            $couponId = (int) $pdo->lastInsertId();
        }
        self::syncLinks($pdo, 'customer_coupon_assignments', 'customer_id', $couponId, $customerIds);
        self::syncLinks($pdo, 'coupon_categories', 'category_id', $couponId, $categoryIds);
        self::syncLinks($pdo, 'coupon_products', 'product_id', $couponId, $productIds);
        self::syncLinks($pdo, 'coupon_excluded_products', 'product_id', $couponId, $excludedProductIds);

        return $couponId;
    }

    /** @param list<int> $ids */
    private static function syncLinks(PDO $pdo, string $table, string $col, int $couponId, array $ids): void
    {
        $pdo->prepare("DELETE FROM {$table} WHERE coupon_id = ?")->execute([$couponId]);
        $stmt = $pdo->prepare("INSERT INTO {$table} (coupon_id, {$col}) VALUES (?, ?)");
        foreach ($ids as $id) {
            if ($id > 0) {
                $stmt->execute([$couponId, $id]);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function usageReport(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT c.code, c.used_count, c.usage_limit, COUNT(cu.id) AS usage_rows,
                    COALESCE(SUM(o.discount_total), 0) AS discount_given
             FROM coupons c
             LEFT JOIN coupon_usages cu ON cu.coupon_id = c.id
             LEFT JOIN orders o ON o.id = cu.order_id
             GROUP BY c.id
             ORDER BY c.used_count DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

final class OfferRepository
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        Install::ensureConnection();

        return Database::pdo()->query('SELECT * FROM offers ORDER BY priority DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM offers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public static function save(?int $id, array $data, array $customerIds): int
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($id) {
            $pdo->prepare(
                'UPDATE offers SET name = ?, scope = ?, target_id = ?, discount_type = ?, discount_value = ?,
                 max_discount = ?, min_cart_value = ?, starts_at = ?, ends_at = ?, priority = ?,
                 stack_with_coupons = ?, is_active = ? WHERE id = ?'
            )->execute([
                $data['name'],
                $data['scope'],
                $data['target_id'],
                $data['discount_type'],
                $data['discount_value'],
                $data['max_discount'],
                $data['min_cart_value'],
                $data['starts_at'],
                $data['ends_at'],
                $data['priority'],
                $data['stack_with_coupons'],
                $data['is_active'],
                $id,
            ]);
            $offerId = $id;
        } else {
            $pdo->prepare(
                'INSERT INTO offers (name, scope, target_id, discount_type, discount_value, max_discount, min_cart_value,
                 starts_at, ends_at, priority, stack_with_coupons, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $data['name'],
                $data['scope'],
                $data['target_id'],
                $data['discount_type'],
                $data['discount_value'],
                $data['max_discount'],
                $data['min_cart_value'],
                $data['starts_at'],
                $data['ends_at'],
                $data['priority'],
                $data['stack_with_coupons'],
                $data['is_active'],
            ]);
            $offerId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM offer_customer_assignments WHERE offer_id = ?')->execute([$offerId]);
        $ins = $pdo->prepare('INSERT INTO offer_customer_assignments (offer_id, customer_id) VALUES (?, ?)');
        foreach ($customerIds as $cid) {
            if ($cid > 0) {
                $ins->execute([$offerId, $cid]);
            }
        }

        return $offerId;
    }
}
