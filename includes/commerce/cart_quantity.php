<?php

declare(strict_types=1);

/**
 * Sellable quantity caps: MIN(available stock, max per order).
 */
final class CartQuantityRules
{
    public const DEFAULT_MAX_PER_ORDER = 20;

    /** @return array{ok: bool, message?: string, cap?: int} */
    public static function validateForVariantId(int $variantId, int $requestedQty): array
    {
        if ($requestedQty <= 0) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1.', 'cap' => 0];
        }
        $stock = InventoryService::availableQty($variantId);
        $limit = self::maxPerOrderForVariantId($variantId);
        $cap = self::capFromStockAndLimit($stock, $limit);

        return self::validateAgainstCapPublic($requestedQty, $stock, $limit, $cap);
    }

    /** @return array{ok: bool, message?: string, cap?: int} */
    public static function validateForCatalogueVariant(array $variant, int $requestedQty): array
    {
        if ($requestedQty <= 0) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1.', 'cap' => 0];
        }
        $stock = max(0, (int) ($variant['stock'] ?? 0));
        $limit = self::maxPerOrderFromCatalogueVariant($variant);
        $cap = self::capFromStockAndLimit($stock, $limit);

        return self::validateAgainstCapPublic($requestedQty, $stock, $limit, $cap);
    }

    public static function orderableQty(int $variantId): int
    {
        $stock = InventoryService::availableQty($variantId);
        $limit = self::maxPerOrderForVariantId($variantId);

        return self::capFromStockAndLimit($stock, $limit);
    }

    public static function orderableQtyFromCatalogueVariant(array $variant): int
    {
        $stock = max(0, (int) ($variant['stock'] ?? 0));
        $limit = self::maxPerOrderFromCatalogueVariant($variant);

        return self::capFromStockAndLimit($stock, $limit);
    }

    /**
     * Row-locked read for concurrent cart updates (persisted storefront).
     *
     * @return array{stock: int, limit: ?int, cap: int}|null
     */
    public static function lockedSellableCaps(PDO $pdo, int $variantId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT stock_qty, max_qty_per_order, is_active FROM product_variants WHERE id = ? FOR UPDATE'
        );
        try {
            $stmt->execute([$variantId]);
        } catch (Throwable $e) {
            $stmt = $pdo->prepare(
                'SELECT stock_qty, is_active FROM product_variants WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$variantId]);
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
        $cap = self::capFromStockAndLimit($stock, $limit);

        return ['stock' => $stock, 'limit' => $limit, 'cap' => $cap];
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

    /** DB NULL or explicit 0 in catalogue export = unlimited per order. */
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

    /** @return int|null Positive limit, or null when unlimited. */
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

    /** Default for new admin rows when the field is left blank. */
    public static function adminMaxPerOrderOrDefault(mixed $raw): ?int
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return self::DEFAULT_MAX_PER_ORDER;
        }

        return self::parseAdminMaxPerOrderInput($raw);
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
     * @return array{ok: bool, message?: string, cap?: int}
     */
    public static function validateAgainstCapPublic(int $requestedQty, int $stock, ?int $limit, int $cap): array
    {
        if ($cap <= 0) {
            return ['ok' => false, 'message' => 'This item is currently out of stock.', 'cap' => 0];
        }
        if ($requestedQty <= $cap) {
            return ['ok' => true, 'cap' => $cap];
        }
        if ($limit !== null && $stock >= $limit && $requestedQty > $limit) {
            return [
                'ok' => false,
                'message' => 'Maximum ' . $limit . ' units allowed per order.',
                'cap' => $cap,
            ];
        }

        return [
            'ok' => false,
            'message' => 'Only ' . $cap . ' units are available.',
            'cap' => $cap,
        ];
    }
}
