<?php

declare(strict_types=1);

/**
 * Inventory reports (Stage 14.5) — uses live stock_qty and inventory_movements.
 */
final class InventoryReportService
{
    public const MOVEMENTS_PER_PAGE = 50;
    public const EXPORT_MAX_ROWS = 5000;

    /** @return list<array<string, mixed>> */
    public static function currentStock(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT p.name AS product_name, v.id AS variant_id, v.name AS variant_name, v.sku,
                    v.stock_qty, v.low_stock_threshold
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.is_active = 1 AND p.status = \'published\'
             ORDER BY p.name, v.sort_order, v.name'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function lowStock(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT p.name AS product_name, v.name AS variant_name, v.sku, v.stock_qty, v.low_stock_threshold
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.is_active = 1 AND p.status = \'published\'
               AND v.stock_qty > 0 AND v.stock_qty <= v.low_stock_threshold
             ORDER BY v.stock_qty ASC, p.name'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function outOfStock(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT p.name AS product_name, v.name AS variant_name, v.sku, v.stock_qty
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.is_active = 1 AND p.status = \'published\' AND v.stock_qty <= 0
             ORDER BY p.name, v.name'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{totals: array<string, int|float>, rows: list<array<string, mixed>>, total: int}
     */
    public static function movementSummary(ReportDateRange $range, int $page = 1): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $start = $range->sqlStart();
        $end = $range->sqlEnd();

        $sumStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN m.change_qty > 0 THEN m.change_qty ELSE 0 END), 0) AS stock_received,
                COALESCE(SUM(CASE WHEN m.change_qty < 0 THEN ABS(m.change_qty) ELSE 0 END), 0) AS stock_deducted,
                COALESCE(SUM(CASE WHEN m.admin_user_id IS NOT NULL THEN ABS(m.change_qty) ELSE 0 END), 0) AS stock_adjustments_qty,
                COUNT(CASE WHEN m.admin_user_id IS NOT NULL THEN 1 END) AS adjustment_rows
             FROM inventory_movements m
             WHERE m.created_at >= ? AND m.created_at < ?'
        );
        $sumStmt->execute([$start, $end]);
        $totals = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM inventory_movements m WHERE m.created_at >= ? AND m.created_at < ?'
        );
        $countStmt->execute([$start, $end]);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * self::MOVEMENTS_PER_PAGE);
        $listStmt = $pdo->prepare(
            'SELECT m.created_at, m.change_qty, m.qty_before, m.qty_after, m.reason,
                    v.sku, v.name AS variant_name, p.name AS product_name
             FROM inventory_movements m
             INNER JOIN product_variants v ON v.id = m.variant_id
             INNER JOIN products p ON p.id = v.product_id
             WHERE m.created_at >= ? AND m.created_at < ?
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ' . (int) self::MOVEMENTS_PER_PAGE . ' OFFSET ' . (int) $offset
        );
        $listStmt->execute([$start, $end]);

        return [
            'totals' => [
                'stock_received' => (int) ($totals['stock_received'] ?? 0),
                'stock_deducted' => (int) ($totals['stock_deducted'] ?? 0),
                'stock_adjustments_qty' => (int) ($totals['stock_adjustments_qty'] ?? 0),
                'adjustment_rows' => (int) ($totals['adjustment_rows'] ?? 0),
            ],
            'rows' => $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function productWiseMovements(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT p.name AS product_name, v.name AS variant_name, v.sku,
                    COALESCE(SUM(CASE WHEN m.change_qty > 0 THEN m.change_qty ELSE 0 END), 0) AS received,
                    COALESCE(SUM(CASE WHEN m.change_qty < 0 THEN ABS(m.change_qty) ELSE 0 END), 0) AS deducted
             FROM inventory_movements m
             INNER JOIN product_variants v ON v.id = m.variant_id
             INNER JOIN products p ON p.id = v.product_id
             WHERE m.created_at >= ? AND m.created_at < ?
             GROUP BY v.id, p.name, v.name, v.sku
             HAVING received > 0 OR deducted > 0
             ORDER BY p.name, v.name
             LIMIT ' . (int) self::EXPORT_MAX_ROWS
        );
        $stmt->execute([$range->sqlStart(), $range->sqlEnd()]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function movementsForExport(ReportDateRange $range): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT m.created_at, m.change_qty, m.qty_after, m.reason, v.sku, v.name AS variant_name, p.name AS product_name
             FROM inventory_movements m
             INNER JOIN product_variants v ON v.id = m.variant_id
             INNER JOIN products p ON p.id = v.product_id
             WHERE m.created_at >= ? AND m.created_at < ?
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ' . (int) self::EXPORT_MAX_ROWS
        );
        $stmt->execute([$range->sqlStart(), $range->sqlEnd()]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
