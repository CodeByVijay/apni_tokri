<?php

declare(strict_types=1);

/**
 * Sellable quantity bounds: min per order, max MIN(stock, max per order).
 */
final class CartQuantityRules
{
    public const DEFAULT_MAX_PER_ORDER = 20;
    public const DEFAULT_MIN_PER_ORDER = 1;

    /** @return array{ok: bool, message?: string, cap?: int, min?: int} */
    public static function validateForVariantId(int $variantId, int $requestedQty): array
    {
        $stock = InventoryService::availableQty($variantId);
        $max = self::maxPerOrderForVariantId($variantId);
        $min = self::minPerOrderForVariantId($variantId);
        $cap = self::capFromStockAndLimit($stock, $max);

        return self::validateQuantityPublic($requestedQty, $stock, $max, $min, $cap);
    }

    /** @return array{ok: bool, message?: string, cap?: int, min?: int} */
    public static function validateForCatalogueVariant(array $variant, int $requestedQty): array
    {
        $stock = max(0, (int) ($variant['stock'] ?? 0));
        $max = self::maxPerOrderFromCatalogueVariant($variant);
        $min = self::minPerOrderFromCatalogueVariant($variant);
        $cap = self::capFromStockAndLimit($stock, $max);

        return self::validateQuantityPublic($requestedQty, $stock, $max, $min, $cap);
    }

    public static function orderableQty(int $variantId): int
    {
        $stock = InventoryService::availableQty($variantId);
        $max = self::maxPerOrderForVariantId($variantId);

        return self::capFromStockAndLimit($stock, $max);
    }

    public static function orderableQtyFromCatalogueVariant(array $variant): int
    {
        $stock = max(0, (int) ($variant['stock'] ?? 0));
        $max = self::maxPerOrderFromCatalogueVariant($variant);

        return self::capFromStockAndLimit($stock, $max);
    }

    public static function minPerOrderForVariantId(int $variantId): int
    {
        if ($variantId <= 0 || !Install::isSchemaReady()) {
            return self::DEFAULT_MIN_PER_ORDER;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT min_qty_per_order FROM product_variants WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$variantId]);
            $val = $stmt->fetchColumn();
            if ($val === false) {
                return self::DEFAULT_MIN_PER_ORDER;
            }

            return self::parseStoredMinPerOrder($val);
        } catch (Throwable $e) {
            return self::DEFAULT_MIN_PER_ORDER;
        }
    }

    public static function minPerOrderFromCatalogueVariant(array $variant): int
    {
        if (!array_key_exists('min_qty_per_order', $variant)) {
            return self::DEFAULT_MIN_PER_ORDER;
        }

        return self::parseStoredMinPerOrder($variant['min_qty_per_order']);
    }

    /**
     * Row-locked read for concurrent cart updates (persisted storefront).
     *
     * @return array{stock: int, limit: ?int, min: int, cap: int}|null
     */
    public static function lockedSellableCaps(PDO $pdo, int $variantId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT stock_qty, max_qty_per_order, min_qty_per_order, is_active FROM product_variants WHERE id = ? FOR UPDATE'
        );
        try {
            $stmt->execute([$variantId]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare(
                'SELECT stock_qty, max_qty_per_order, is_active FROM product_variants WHERE id = ? FOR UPDATE'
            );
            try {
                $stmt->execute([$variantId]);
            } catch (Throwable $e2) {
                $stmt = $pdo->prepare(
                    'SELECT stock_qty, is_active FROM product_variants WHERE id = ? FOR UPDATE'
                );
                $stmt->execute([$variantId]);
            }
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int) ($row['is_active'] ?? 0)) {
            return null;
        }
        $stockQty = max(0, (int) ($row['stock_qty'] ?? 0));
        $held = InventoryService::subscriptionHeldQty($variantId);
        $stock = max(0, $stockQty - $held);
        $limit = array_key_exists('max_qty_per_order', $row)
            ? self::parseStoredMaxPerOrder($row['max_qty_per_order'])
            : self::DEFAULT_MAX_PER_ORDER;
        $min = array_key_exists('min_qty_per_order', $row)
            ? self::parseStoredMinPerOrder($row['min_qty_per_order'])
            : self::DEFAULT_MIN_PER_ORDER;
        $cap = self::capFromStockAndLimit($stock, $limit);

        return ['stock' => $stock, 'limit' => $limit, 'min' => $min, 'cap' => $cap];
    }

    public static function maxPerOrderForVariantId(int $variantId): ?int
    {
        if ($variantId <= 0 || !Install::isSchemaReady()) {
            return self::DEFAULT_MAX_PER_ORDER;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT max_qty_per_order FROM product_variants WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$variantId]);
            $val = $stmt->fetchColumn();
            if ($val === false) {
                return self::DEFAULT_MAX_PER_ORDER;
            }

            return self::parseStoredMaxPerOrder($val);
        } catch (Throwable $e) {
            return self::DEFAULT_MAX_PER_ORDER;
        }
    }

    public static function maxPerOrderFromCatalogueVariant(array $variant): ?int
    {
        if (!array_key_exists('max_qty_per_order', $variant)) {
            return self::DEFAULT_MAX_PER_ORDER;
        }

        return self::parseStoredMaxPerOrder($variant['max_qty_per_order']);
    }

    private static function parseStoredMaxPerOrder(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $n = (int) $raw;
        if ($n <= 0) {
            return null;
        }

        return $n;
    }

    private static function parseStoredMinPerOrder(mixed $raw): int
    {
        $n = (int) $raw;
        if ($n < 1) {
            return self::DEFAULT_MIN_PER_ORDER;
        }

        return $n;
    }

    public static function parseAdminMaxPerOrderInput(mixed $raw): ?int
    {
        $s = trim((string) $raw);
        if ($s === '' || $s === '0') {
            return null;
        }
        $n = (int) $s;
        if ($n <= 0) {
            return null;
        }

        return $n;
    }

    public static function adminMaxPerOrderOrDefault(mixed $raw): ?int
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return self::DEFAULT_MAX_PER_ORDER;
        }

        return self::parseAdminMaxPerOrderInput($raw);
    }

    public static function adminMinPerOrderOrDefault(mixed $raw): int
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return self::DEFAULT_MIN_PER_ORDER;
        }
        $n = (int) $s;
        if ($n < 1) {
            return self::DEFAULT_MIN_PER_ORDER;
        }

        return $n;
    }

    private static function capFromStockAndLimit(int $stock, ?int $limit): int
    {
        if ($stock <= 0) {
            return 0;
        }
        if ($limit === null) {
            return $stock;
        }

        return min($stock, $limit);
    }

    /**
     * @return array{ok: bool, message?: string, cap?: int, min?: int}
     */
    public static function validateQuantityPublic(
        int $requestedQty,
        int $stock,
        ?int $maxLimit,
        int $minQty,
        int $cap
    ): array {
        $minQty = max(1, $minQty);
        if ($cap > 0 && $minQty > $cap) {
            return [
                'ok' => false,
                'message' => self::minimumExceedsStockMessage($minQty, $cap),
                'cap' => $cap,
                'min' => $minQty,
            ];
        }
        if ($cap <= 0) {
            return ['ok' => false, 'message' => 'This item is currently out of stock.', 'cap' => 0, 'min' => $minQty];
        }
        if ($requestedQty < $minQty) {
            return [
                'ok' => false,
                'message' => self::minimumRequiredMessage($minQty),
                'cap' => $cap,
                'min' => $minQty,
            ];
        }
        if ($requestedQty <= $cap) {
            return ['ok' => true, 'cap' => $cap, 'min' => $minQty];
        }
        if ($maxLimit !== null && $stock >= $maxLimit && $requestedQty > $maxLimit) {
            return [
                'ok' => false,
                'message' => self::maximumPerOrderMessage($maxLimit),
                'cap' => $cap,
                'min' => $minQty,
            ];
        }

        return [
            'ok' => false,
            'message' => self::stockAvailabilityMessage($cap),
            'cap' => $cap,
            'min' => $minQty,
        ];
    }

    /** @deprecated Use validateQuantityPublic via validateFor* */
    public static function validateAgainstCapPublic(int $requestedQty, int $stock, ?int $limit, int $cap): array
    {
        return self::validateQuantityPublic($requestedQty, $stock, $limit, self::DEFAULT_MIN_PER_ORDER, $cap);
    }

    public static function minimumRequiredMessage(int $min): string
    {
        return 'Minimum ' . $min . ' units required for this variant.';
    }

    public static function maximumPerOrderMessage(int $limit): string
    {
        return 'Maximum ' . $limit . ' units allowed per order.';
    }

    public static function stockAvailabilityMessage(int $available): string
    {
        return 'Only ' . $available . ' units are available.';
    }

    public static function minimumExceedsStockMessage(int $min, int $available): string
    {
        return 'Minimum ' . $min . ' units is required, but only ' . $available . ' are available for this variant.';
    }

    /** Toast when customer tries to raise quantity above the sellable cap (cart + button). */
    public static function cartIncreaseBlockedMessage(int $stock, ?int $limit, int $cap): string
    {
        if ($limit !== null && $stock >= $limit && $cap === $limit) {
            return self::maximumPerOrderMessage($limit) . ' for this variant.';
        }

        return self::stockAvailabilityMessage($cap);
    }

    public static function cartDecreaseBlockedMessage(int $min): string
    {
        return self::minimumRequiredMessage($min);
    }

    /**
     * Cart line validity when admin changes limits (do not auto-fix qty).
     *
     * @return array{ok: bool, message?: string}
     */
    public static function validateExistingCartLine(int $qtyInCart, int $stock, ?int $maxLimit, int $minQty): array
    {
        $check = self::validateQuantityPublic($qtyInCart, $stock, $maxLimit, $minQty, self::capFromStockAndLimit($stock, $maxLimit));
        if ($check['ok']) {
            return ['ok' => true];
        }
        $msg = (string) ($check['message'] ?? 'Update quantity for this item.');
        if ($qtyInCart < $minQty) {
            $msg .= ' Increase quantity or remove this item.';
        } elseif ($qtyInCart > ($check['cap'] ?? 0)) {
            $msg .= ' Reduce quantity or remove this item.';
        } else {
            $msg .= ' Please update or remove this item.';
        }

        return ['ok' => false, 'message' => $msg];
    }
}
