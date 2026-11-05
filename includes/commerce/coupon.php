<?php

declare(strict_types=1);

final class CouponService
{
    /**
     * @param list<array<string, mixed>> $lines pricing lines (product_id, category_id optional)
     * @return array{ok: bool, message?: string, discount?: float, code?: string|null}
     */
    public static function validateForCart(
        ?string $code,
        array $lines,
        ?int $customerId,
        float $offerDiscount = 0.0,
        bool $offerStacksWithCoupons = false
    ): array {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
        }

        return self::validate($code, $subtotal, $customerId, $lines, $offerDiscount, $offerStacksWithCoupons);
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     * @return array{ok: bool, message?: string, reason?: string, shortfall?: float, discount?: float, code?: string|null}
     */
    public static function validate(
        ?string $code,
        float $subtotal,
        ?int $customerId = null,
        ?array $lines = null,
        float $offerDiscount = 0.0,
        bool $offerStacksWithCoupons = false
    ): array {
        $code = $code !== null ? strtoupper(trim($code)) : '';
        if ($code === '') {
            return ['ok' => true, 'discount' => 0.0, 'code' => null];
        }

        if (!CommerceGateway::useDatabase()) {
            return self::validateDemo($code, $subtotal);
        }

        if ($offerDiscount > 0 && !$offerStacksWithCoupons) {
            return ['ok' => false, 'reason' => 'offer_conflict', 'message' => 'This coupon cannot be combined with the active automatic offer.'];
        }

        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM coupons WHERE code = ? AND is_active = 1
             AND (starts_at IS NULL OR starts_at <= NOW())
             AND (ends_at IS NULL OR ends_at >= NOW())
             LIMIT 1"
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'reason' => 'invalid', 'message' => 'Invalid or expired coupon.'];
        }

        $couponId = (int) $row['id'];
        if (!self::customerMayUseCoupon($couponId, $customerId)) {
            return ['ok' => false, 'reason' => 'not_assigned', 'message' => 'This coupon is not assigned to your account.'];
        }
        if (!empty($row['first_order_only'])) {
            if ($customerId === null) {
                return ['ok' => false, 'reason' => 'login_required', 'message' => 'Please log in to use this first-order coupon.'];
            }
            if (!PromotionService::isFirstPaidOrderCustomer($customerId)) {
                return ['ok' => false, 'reason' => 'first_order_only', 'message' => 'This coupon is valid on your first order only.'];
            }
        }
        if ($row['usage_limit'] !== null && (int) $row['used_count'] >= (int) $row['usage_limit']) {
            return ['ok' => false, 'reason' => 'usage_limit', 'message' => 'This coupon has reached its usage limit.'];
        }
        if ($customerId !== null && $row['per_customer_limit'] !== null) {
            $usedByCustomer = self::customerUsageCount($couponId, $customerId);
            if ($usedByCustomer >= (int) $row['per_customer_limit']) {
                return ['ok' => false, 'reason' => 'per_customer_limit', 'message' => 'You have already used this coupon the maximum number of times.'];
            }
        }

        $eligibleSubtotal = $lines !== null
            ? self::eligibleSubtotalForCoupon($couponId, $lines)
            : $subtotal;
        if ($eligibleSubtotal <= 0) {
            return ['ok' => false, 'reason' => 'not_applicable', 'message' => 'Coupon does not apply to items in your cart.'];
        }

        // Minimum applies to the ELIGIBLE subtotal (items the coupon can discount).
        // For coupons valid on all products this equals the whole cart subtotal.
        $min = $row['min_cart_value'] !== null ? (float) $row['min_cart_value'] : 0.0;
        if ($eligibleSubtotal < $min) {
            $scoped = $lines !== null && abs($eligibleSubtotal - $subtotal) > 0.009;
            return ['ok' => false, 'reason' => 'min_cart', 'shortfall' => round($min - $eligibleSubtotal, 2), 'message' => 'Minimum ' . ($scoped ? 'eligible items value' : 'cart value')
                . ' for this coupon is ₹' . number_format($min, 0) . '.'];
        }

        if ($offerDiscount > 0 && empty($row['stackable_with_offers'])) {
            return ['ok' => false, 'reason' => 'offer_conflict', 'message' => 'This coupon cannot be combined with automatic offers.'];
        }

        $couponBase = max(0, $eligibleSubtotal - ($offerStacksWithCoupons ? $offerDiscount * ($eligibleSubtotal / max($subtotal, 0.01)) : 0));
        $discount = self::discountAmount($row, $couponBase);
        if ($discount <= 0) {
            return ['ok' => false, 'reason' => 'no_discount', 'message' => 'Coupon does not apply to this cart.'];
        }

        return ['ok' => true, 'discount' => round($discount, 2), 'code' => $code];
    }

    private static function customerMayUseCoupon(int $couponId, ?int $customerId): bool
    {
        $pdo = Database::pdo();
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM customer_coupon_assignments WHERE coupon_id = ?');
        $cnt->execute([$couponId]);
        if ((int) $cnt->fetchColumn() === 0) {
            return true;
        }
        if ($customerId === null) {
            return false;
        }
        $chk = $pdo->prepare(
            'SELECT 1 FROM customer_coupon_assignments WHERE coupon_id = ? AND customer_id = ? LIMIT 1'
        );
        $chk->execute([$couponId, $customerId]);

        return (bool) $chk->fetchColumn();
    }

    private static function customerUsageCount(int $couponId, int $customerId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND customer_id = ?'
        );
        $stmt->execute([$couponId, $customerId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Eligibility: no categories/products selected = all products. Otherwise UNION
     * (product matches selected products OR its category matches selected categories).
     * Explicitly excluded products never qualify.
     *
     * @param list<array<string, mixed>> $lines
     */
    public static function eligibleSubtotal(int $couponId, array $lines): float
    {
        return self::eligibleSubtotalForCoupon($couponId, $lines);
    }

    /** @param list<array<string, mixed>> $lines */
    private static function eligibleSubtotalForCoupon(int $couponId, array $lines): float
    {
        $products = CouponRepository::productIds($couponId);
        $categories = CouponRepository::categoryIds($couponId);
        $excluded = CouponRepository::excludedProductIds($couponId);
        $scoped = $products !== [] || $categories !== [];
        $sum = 0.0;
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $cid = (int) ($line['category_id'] ?? 0);
            if ($pid > 0 && in_array($pid, $excluded, true)) {
                continue;
            }
            if (!$scoped) {
                $sum += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
                continue;
            }
            $match = false;
            if ($products !== [] && in_array($pid, $products, true)) {
                $match = true;
            }
            if ($categories !== [] && in_array($cid, $categories, true)) {
                $match = true;
            }
            if ($match) {
                $sum += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
            }
        }

        return $sum;
    }

    /** @param array<string, mixed> $couponRow */
    public static function discountAmount(array $couponRow, float $subtotal): float
    {
        $type = (string) ($couponRow['discount_type'] ?? 'fixed');
        $value = (float) ($couponRow['discount_value'] ?? 0);
        if ($type === 'percent') {
            $discount = round($subtotal * ($value / 100), 2);
            $max = $couponRow['max_discount'] !== null ? (float) $couponRow['max_discount'] : null;
            if ($max !== null) {
                $discount = min($discount, $max);
            }

            return min($discount, $subtotal);
        }

        return min($value, $subtotal);
    }

    public static function recordUsage(string $code, int $orderId, ?int $customerId): void
    {
        if (!CommerceGateway::useDatabase()) {
            return;
        }
        try {
            Database::transaction(static function () use ($code, $orderId, $customerId): void {
                $pdo = Database::pdo();
                $dup = $pdo->prepare('SELECT id FROM coupon_usages WHERE order_id = ? LIMIT 1');
                $dup->execute([$orderId]);
                if ($dup->fetchColumn()) {
                    return;
                }
                $stmt = $pdo->prepare('SELECT * FROM coupons WHERE code = ? LIMIT 1 FOR UPDATE');
                $stmt->execute([strtoupper($code)]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return;
                }
                $couponId = (int) $row['id'];
                if ($row['usage_limit'] !== null && (int) $row['used_count'] >= (int) $row['usage_limit']) {
                    throw new RuntimeException('Coupon usage limit reached.');
                }
                if ($customerId !== null && $row['per_customer_limit'] !== null) {
                    $used = self::customerUsageCount($couponId, $customerId);
                    if ($used >= (int) $row['per_customer_limit']) {
                        throw new RuntimeException('Per-customer coupon limit reached.');
                    }
                }
                $pdo->prepare('INSERT INTO coupon_usages (coupon_id, order_id, customer_id) VALUES (?, ?, ?)')
                    ->execute([$couponId, $orderId, $customerId]);
                $pdo->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?')->execute([$couponId]);
            });
        } catch (Throwable $e) {
            error_log('Coupon recordUsage: ' . $e->getMessage());
            throw $e;
        }
    }

    /** @return array{ok: bool, message?: string, discount?: float, code?: string|null} */
    private static function validateDemo(string $code, float $subtotal): array
    {
        $valid = ['WELCOME10' => ['type' => 'percent', 'value' => 0.10], 'SAVE50' => ['type' => 'fixed', 'value' => 50.0]];
        if (!isset($valid[$code])) {
            return ['ok' => false, 'message' => 'Invalid or expired coupon (demo).'];
        }
        if ($code === 'WELCOME10') {
            return ['ok' => true, 'discount' => round($subtotal * 0.10, 2), 'code' => $code];
        }

        return ['ok' => true, 'discount' => round(min(50.0, $subtotal), 2), 'code' => $code];
    }
}
