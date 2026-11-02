<?php

declare(strict_types=1);

final class InventoryService
{
    /**
     * Adjust stock and record movement (admin).
     *
     * @return array{ok: bool, message?: string, qty_after?: int}
     */
    public static function adjust(int $variantId, int $changeQty, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Reason is required.'];
        }
        if ($changeQty === 0) {
            return ['ok' => false, 'message' => 'Quantity change cannot be zero.'];
        }

        try {
            return Database::transaction(static function () use ($variantId, $changeQty, $reason): array {
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'SELECT id, stock_qty FROM product_variants WHERE id = ? FOR UPDATE'
                );
                $stmt->execute([$variantId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return ['ok' => false, 'message' => 'Variant not found.'];
                }
                $before = (int) $row['stock_qty'];
                $after = $before + $changeQty;
                if ($after < 0) {
                    return ['ok' => false, 'message' => 'Stock cannot go below zero (current: ' . $before . ').'];
                }

                $upd = $pdo->prepare('UPDATE product_variants SET stock_qty = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$after, $variantId]);

                $mov = $pdo->prepare(
                    'INSERT INTO inventory_movements (variant_id, change_qty, qty_before, qty_after, reason, admin_user_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $mov->execute([
                    $variantId,
                    $changeQty,
                    $before,
                    $after,
                    $reason,
                    AdminAuth::id(),
                ]);

                AuditLog::write('inventory.adjust', 'variant', (string) $variantId, [
                    'change' => $changeQty,
                    'before' => $before,
                    'after' => $after,
                    'reason' => $reason,
                ]);

                return ['ok' => true, 'qty_after' => $after];
            });
        } catch (Throwable $e) {
            error_log('Inventory adjust: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not adjust stock.'];
        }
    }

    /**
     * Decrement sellable stock (checkout / reservation tests). Uses row lock.
     */
    public static function decrement(int $variantId, int $qty, string $reason): bool
    {
        if ($qty <= 0) {
            return false;
        }

        return Database::transaction(static function () use ($variantId, $qty, $reason): bool {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT id, stock_qty, is_active FROM product_variants WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$variantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !(int) $row['is_active']) {
                return false;
            }
            $before = (int) $row['stock_qty'];
            if ($before < $qty) {
                return false;
            }
            $after = $before - $qty;
            $pdo->prepare('UPDATE product_variants SET stock_qty = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$after, $variantId]);
            $pdo->prepare(
                'INSERT INTO inventory_movements (variant_id, change_qty, qty_before, qty_after, reason, admin_user_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$variantId, -$qty, $before, $after, $reason, AdminAuth::id()]);

            return true;
        });
    }

    public static function availableQty(int $variantId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT stock_qty FROM product_variants WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$variantId]);
        $v = $stmt->fetchColumn();
        $stock = $v === false ? 0 : (int) $v;

        return max(0, $stock - self::subscriptionHeldQty($variantId));
    }

    /** Sellable qty held for subscription fulfilment (status held). */
    public static function subscriptionHeldQty(int $variantId): int
    {
        if (!Install::isSchemaReady()) {
            return 0;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                "SELECT COALESCE(SUM(qty), 0) FROM subscription_stock_reservations
                 WHERE variant_id = ? AND status = 'held'"
            );
            $stmt->execute([$variantId]);
            $sum = $stmt->fetchColumn();

            return (int) round((float) $sum);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function countLowStockVariants(): int
    {
        if (!Install::isSchemaReady()) {
            return 0;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                'SELECT COUNT(*) FROM product_variants v
                 INNER JOIN products p ON p.id = v.product_id
                 WHERE v.is_active = 1 AND p.status = \'published\'
                 AND v.stock_qty > 0 AND v.stock_qty <= v.low_stock_threshold'
            );

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function recentMovements(int $limit = 50): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT m.created_at, m.change_qty, m.qty_after, m.reason, v.sku, v.name AS variant_name,
                    p.name AS product_name, u.name AS admin_name
             FROM inventory_movements m
             INNER JOIN product_variants v ON v.id = m.variant_id
             INNER JOIN products p ON p.id = v.product_id
             LEFT JOIN admin_users u ON u.id = m.admin_user_id
             ORDER BY m.created_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = $r;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function variantsForSelect(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT v.id, v.name, v.sku, v.stock_qty, p.name AS product_name, p.slug AS product_slug
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.is_active = 1
             ORDER BY p.name, v.sort_order, v.name'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
