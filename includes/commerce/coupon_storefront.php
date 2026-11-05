<?php

declare(strict_types=1);

/**
 * Storefront discovery rules for cart "Available Offers".
 * Apply/eligibility remains CouponService::validate() (manual codes always allowed when valid).
 */
final class CouponStorefront
{
    /** @var bool|null */
    private static $hasVisibleColumn;

    public static function hasStorefrontVisibleColumn(): bool
    {
        if (self::$hasVisibleColumn !== null) {
            return self::$hasVisibleColumn;
        }
        if (!Install::isSchemaReady()) {
            self::$hasVisibleColumn = false;

            return false;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query("SHOW COLUMNS FROM coupons LIKE 'storefront_visible'");
        self::$hasVisibleColumn = (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));

        return self::$hasVisibleColumn;
    }

    /**
     * Whether a coupon may appear in the Available Offers UI for this customer.
     * Backward compatible: without storefront_visible column, only customer-assignment privacy applies.
     */
    public static function isDiscoverable(array $couponRow, ?int $customerId): bool
    {
        if (self::hasStorefrontVisibleColumn() && empty($couponRow['storefront_visible'])) {
            return false;
        }
        $couponId = (int) ($couponRow['id'] ?? 0);
        if ($couponId <= 0) {
            return false;
        }
        $assigned = CouponRepository::assignedCustomerIds($couponId);
        if ($assigned === []) {
            return true;
        }
        if ($customerId === null || $customerId <= 0) {
            return false;
        }

        return in_array($customerId, $assigned, true);
    }

    /** @internal tests only */
    public static function resetSchemaCacheForTests(): void
    {
        self::$hasVisibleColumn = null;
    }
}
