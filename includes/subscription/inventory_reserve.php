<?php

declare(strict_types=1);

final class SubscriptionInventoryReserveService
{
    /**
     * Hold stock for a delivery day (reduces effective available via InventoryService::availableQty).
     *
     * @param list<array{variant_id: int, qty: int}> $lines
     * @return array{ok: bool, message?: string}
     */
    public static function holdForDay(int $dayId, array $lines, PDO $pdo): array
    {
        foreach ($lines as $line) {
            $vid = (int) $line['variant_id'];
            $qty = (int) $line['qty'];
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT id, stock_qty, is_active FROM product_variants WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$vid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !(int) $row['is_active']) {
                return ['ok' => false, 'message' => 'Variant unavailable.'];
            }
            $available = (int) $row['stock_qty'] - self::heldForVariantExcludingDay($pdo, $vid, $dayId);
            if ($available < $qty) {
                return ['ok' => false, 'message' => 'Insufficient stock for variant ' . $vid . '.'];
            }
            $pdo->prepare(
                'INSERT INTO subscription_stock_reservations (subscription_delivery_day_id, variant_id, qty, status)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty), status = VALUES(status), updated_at = NOW()'
            )->execute([$dayId, $vid, $qty, 'held']);
        }

        return ['ok' => true];
    }

    public static function markDeducted(int $dayId, PDO $pdo): void
    {
        $pdo->prepare(
            "UPDATE subscription_stock_reservations SET status = 'deducted', updated_at = NOW()
             WHERE subscription_delivery_day_id = ? AND status = 'held'"
        )->execute([$dayId]);
    }

    public static function releaseDay(int $dayId, PDO $pdo): void
    {
        $pdo->prepare(
            "UPDATE subscription_stock_reservations SET status = 'released', updated_at = NOW()
             WHERE subscription_delivery_day_id = ? AND status = 'held'"
        )->execute([$dayId]);
    }

    /**
     * Decrement physical stock only for held reservations (prevents double deduction).
     *
     * @param list<array{variant_id: int, qty: int}> $lines
     */
    public static function deductHeldLines(int $dayId, array $lines, string $reason, PDO $pdo): bool
    {
        foreach ($lines as $line) {
            $vid = (int) $line['variant_id'];
            $qty = (int) $line['qty'];
            $res = $pdo->prepare(
                "SELECT id, qty, status FROM subscription_stock_reservations
                 WHERE subscription_delivery_day_id = ? AND variant_id = ? FOR UPDATE"
            );
            $res->execute([$dayId, $vid]);
            $r = $res->fetch(PDO::FETCH_ASSOC);
            if (!$r || (string) $r['status'] !== 'held') {
                return false;
            }
            if ((int) round((float) $r['qty']) < $qty) {
                return false;
            }
            if (!InventoryService::decrement($vid, $qty, $reason)) {
                return false;
            }
        }
        self::markDeducted($dayId, $pdo);

        return true;
    }

    private static function heldForVariantExcludingDay(PDO $pdo, int $variantId, int $excludeDayId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(qty), 0) FROM subscription_stock_reservations
             WHERE variant_id = ? AND status = 'held' AND subscription_delivery_day_id <> ?"
        );
        $stmt->execute([$variantId, $excludeDayId]);

        return (int) round((float) $stmt->fetchColumn());
    }
}
