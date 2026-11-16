<?php

declare(strict_types=1);

/**
 * Promotional coupon push — read-only coupon eligibility; does not affect checkout.
 */
final class PushCouponNotificationService
{
    public const TEMPLATE_KEY = 'coupon.promotional.push';

  /**
     * Validate coupon availability for a targeted customer push (no cart context).
     *
     * @return array{ok: bool, reason?: string, message?: string, coupon?: array<string, mixed>}
     */
    public static function validateForPromotionalPush(int $couponId, int $customerId): array
    {
        if ($couponId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_coupon', 'message' => 'Invalid coupon.'];
        }
        if ($customerId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_customer', 'message' => 'Invalid customer.'];
        }
        if (!CommerceGateway::useDatabase()) {
            return ['ok' => false, 'reason' => 'no_db', 'message' => 'Database is not available.'];
        }
        $row = CouponRepository::find($couponId);
        if ($row === null) {
            return ['ok' => false, 'reason' => 'not_found', 'message' => 'Coupon not found.'];
        }
        if (empty($row['is_active'])) {
            return ['ok' => false, 'reason' => 'inactive', 'message' => 'Coupon is inactive.'];
        }
        if (!empty($row['starts_at']) && strtotime((string) $row['starts_at']) > time()) {
            return ['ok' => false, 'reason' => 'not_started', 'message' => 'Coupon is not active yet.'];
        }
        if (!empty($row['ends_at']) && strtotime((string) $row['ends_at']) < time()) {
            return ['ok' => false, 'reason' => 'expired', 'message' => 'Coupon has expired.'];
        }
        if ($row['usage_limit'] !== null && (int) $row['used_count'] >= (int) $row['usage_limit']) {
            return ['ok' => false, 'reason' => 'usage_limit', 'message' => 'Coupon usage limit has been reached.'];
        }
        $assigned = CouponRepository::assignedCustomerIds($couponId);
        if ($assigned !== [] && !in_array($customerId, $assigned, true)) {
            return ['ok' => false, 'reason' => 'not_assigned', 'message' => 'This coupon is not assigned to this customer.'];
        }
        if ($row['per_customer_limit'] !== null) {
            $used = self::customerCouponUsageCount($couponId, $customerId);
            if ($used >= (int) $row['per_customer_limit']) {
                return ['ok' => false, 'reason' => 'per_customer_limit', 'message' => 'This customer has already used this coupon the maximum number of times.'];
            }
        }
        if (!empty($row['first_order_only']) && !PromotionService::isFirstPaidOrderCustomer($customerId)) {
            return ['ok' => false, 'reason' => 'first_order_only', 'message' => 'This coupon is for first orders only and does not apply to this customer.'];
        }

        return ['ok' => true, 'coupon' => $row];
    }

    /**
     * @param array<string, mixed> $couponRow from {@see validateForPromotionalPush}
     * @return array<string, string>
     */
    public static function buildTemplateVars(int $customerId, array $couponRow, ?string $clickUrlOverride = null): array
    {
        $clickPath = '/cart.php';
        $clickRaw = $clickUrlOverride !== null && trim($clickUrlOverride) !== ''
            ? trim($clickUrlOverride)
            : $clickPath;
        $resolvedClick = PushUrlValidator::resolveClickUrl($clickRaw, []) ?? SeoUrl::absolute($clickPath);

        return [
            'customer_name' => self::customerDisplayName($customerId),
            'coupon_code' => (string) ($couponRow['code'] ?? ''),
            'discount_value' => self::formatDiscountValue($couponRow),
            'minimum_order' => self::formatMinimumOrder($couponRow),
            'expiry_date' => self::formatExpiryDate($couponRow),
            'click_url' => $resolvedClick,
        ];
    }

    /**
     * Admin manual promotional coupon send.
     *
     * @return array{ok: bool, message?: string, sent?: int, skipped?: bool, duplicate?: bool, dry_run?: bool, reason?: string}
     */
    public static function sendPromotionalCouponToCustomer(int $customerId, int $couponId, ?string $clickUrlOverride = null): array
    {
        $check = self::validateForPromotionalPush($couponId, $customerId);
        if (empty($check['ok'])) {
            return [
                'ok' => false,
                'reason' => (string) ($check['reason'] ?? 'invalid'),
                'message' => (string) ($check['message'] ?? 'Coupon cannot be sent.'),
            ];
        }
        $couponRow = $check['coupon'];
        $vars = self::buildTemplateVars($customerId, $couponRow, $clickUrlOverride);

        return PushNotificationService::sendToCustomer(
            $customerId,
            self::TEMPLATE_KEY,
            $vars,
            'push:admin:manual:coupon:' . $couponId . ':customer:' . $customerId . ':' . time(),
            'admin.manual.coupon',
            ['coupon_id' => (string) $couponId]
        );
    }

    public static function formatDiscountValue(array $couponRow): string
    {
        $type = (string) ($couponRow['discount_type'] ?? '');
        $val = (float) ($couponRow['discount_value'] ?? 0);
        if ($type === 'percent') {
            $pct = rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.') . '%';
            $max = $couponRow['max_discount'] !== null ? (float) $couponRow['max_discount'] : null;
            if ($max !== null && $max > 0) {
                return $pct . ' (up to ₹' . number_format($max, 0, '.', ',') . ')';
            }

            return $pct . ' off';
        }

        return '₹' . number_format($val, 0, '.', ',') . ' off';
    }

    public static function formatMinimumOrder(array $couponRow): string
    {
        $min = $couponRow['min_cart_value'] ?? null;
        if ($min === null || (float) $min <= 0) {
            return 'No minimum';
        }

        return '₹' . number_format((float) $min, 0, '.', ',');
    }

    public static function formatExpiryDate(array $couponRow): string
    {
        $ends = $couponRow['ends_at'] ?? null;
        if ($ends === null || (string) $ends === '') {
            return 'No expiry';
        }

        return customer_format_datetime((string) $ends);
    }

    private static function customerDisplayName(int $customerId): string
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $name = trim((string) ($stmt->fetchColumn() ?: ''));

        return $name !== '' ? $name : 'Customer';
    }

    private static function customerCouponUsageCount(int $couponId, int $customerId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM coupon_usages WHERE coupon_id = ? AND customer_id = ?'
        );
        $stmt->execute([$couponId, $customerId]);

        return (int) $stmt->fetchColumn();
    }
}
