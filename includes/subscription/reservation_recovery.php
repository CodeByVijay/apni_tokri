<?php

declare(strict_types=1);

final class SubscriptionReservationRecoveryService
{
    /**
     * Release orphaned held reservations (CLI/cron). Never touches deducted holds or active generated orders.
     *
     * @return array{released: int, skipped: int, errors: list<string>}
     */
    public static function recoverOrphanedHolds(int $maxAgeMinutes = 120): array
    {
        if (!Install::isSchemaReady()) {
            return ['released' => 0, 'skipped' => 0, 'errors' => ['Schema not ready.']];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT r.subscription_delivery_day_id AS day_id, r.id AS reservation_id
             FROM subscription_stock_reservations r
             INNER JOIN subscription_delivery_days d ON d.id = r.subscription_delivery_day_id
             LEFT JOIN subscription_fulfilment_events fe ON fe.subscription_delivery_day_id = d.id
             WHERE r.status = 'held'
               AND d.generated_order_id IS NULL
               AND (fe.id IS NULL OR fe.status NOT IN ('completed', 'pending'))
               AND r.updated_at < (NOW() - INTERVAL ? MINUTE)
             LIMIT 500"
        );
        $stmt->execute([max(15, $maxAgeMinutes)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $released = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $row) {
            $dayId = (int) $row['day_id'];
            try {
                $result = Database::transaction(static function () use ($dayId): array {
                    $pdo = Database::pdo();
                    if (!self::mayReleaseDay($pdo, $dayId)) {
                        return ['released' => false];
                    }
                    SubscriptionInventoryReserveService::releaseDay($dayId, $pdo);

                    return ['released' => true];
                });
                if (!empty($result['released'])) {
                    $released++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors[] = 'Day ' . $dayId . ': ' . $e->getMessage();
                SubscriptionRecoveryLog::record('orphan_hold_release', 'day_' . $dayId, 'failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['released' => $released, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Restock when reservations were deducted but no order was committed (crash recovery).
     *
     * @return array{recovered: int, errors: list<string>}
     */
    public static function recoverDeductedWithoutOrder(): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT DISTINCT r.subscription_delivery_day_id AS day_id
             FROM subscription_stock_reservations r
             INNER JOIN subscription_delivery_days d ON d.id = r.subscription_delivery_day_id
             LEFT JOIN subscription_fulfilment_events fe ON fe.subscription_delivery_day_id = d.id AND fe.status = 'completed'
             WHERE r.status = 'deducted' AND d.generated_order_id IS NULL AND fe.id IS NULL
             LIMIT 100"
        );
        $dayIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $recovered = 0;
        $errors = [];
        foreach ($dayIds as $dayId) {
            try {
                $ok = Database::transaction(static function () use ($dayId): bool {
                    $pdo = Database::pdo();
                    if (!self::mayRecoverDeductedStock($pdo, (int) $dayId)) {
                        return false;
                    }
                    $lines = $pdo->prepare(
                        "SELECT variant_id, qty FROM subscription_stock_reservations
                         WHERE subscription_delivery_day_id = ? AND status = 'deducted'"
                    );
                    $lines->execute([(int) $dayId]);
                    foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                        $vid = (int) $line['variant_id'];
                        $qty = (int) round((float) $line['qty']);
                        if ($vid > 0 && $qty > 0) {
                            InventoryService::adjust($vid, $qty, 'Recovered deducted reservation day ' . $dayId);
                        }
                    }
                    $pdo->prepare(
                        "UPDATE subscription_stock_reservations SET status = 'released', updated_at = NOW()
                         WHERE subscription_delivery_day_id = ? AND status = 'deducted'"
                    )->execute([(int) $dayId]);

                    return true;
                });
                if ($ok) {
                    $recovered++;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                SubscriptionRecoveryLog::record('deducted_restock', 'day_' . $dayId, 'failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['recovered' => $recovered, 'errors' => $errors];
    }

    public static function mayReleaseDay(PDO $pdo, int $dayId): bool
    {
        $day = $pdo->prepare(
            'SELECT generated_order_id, fulfilment_status FROM subscription_delivery_days WHERE id = ? FOR UPDATE'
        );
        $day->execute([$dayId]);
        $d = $day->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return false;
        }
        if (!empty($d['generated_order_id'])) {
            return false;
        }
        if ((string) ($d['fulfilment_status'] ?? '') === 'generated') {
            return false;
        }
        $ev = $pdo->prepare(
            'SELECT status, order_id FROM subscription_fulfilment_events WHERE subscription_delivery_day_id = ? LIMIT 1 FOR UPDATE'
        );
        $ev->execute([$dayId]);
        $e = $ev->fetch(PDO::FETCH_ASSOC);
        if ($e !== false) {
            $st = (string) $e['status'];
            if ($st === 'completed' || !empty($e['order_id'])) {
                return false;
            }
            if ($st === 'pending') {
                return false;
            }
        }
        $ded = $pdo->prepare(
            "SELECT COUNT(*) FROM subscription_stock_reservations
             WHERE subscription_delivery_day_id = ? AND status = 'deducted'"
        );
        $ded->execute([$dayId]);
        if ((int) $ded->fetchColumn() > 0) {
            return false;
        }

        return true;
    }

    /** Allow restock only when no active generated order exists. */
    public static function mayRecoverDeductedStock(PDO $pdo, int $dayId): bool
    {
        $day = $pdo->prepare(
            'SELECT generated_order_id, fulfilment_status FROM subscription_delivery_days WHERE id = ? FOR UPDATE'
        );
        $day->execute([$dayId]);
        $d = $day->fetch(PDO::FETCH_ASSOC);
        if (!$d) {
            return false;
        }
        if (!empty($d['generated_order_id'])) {
            return false;
        }
        if ((string) ($d['fulfilment_status'] ?? '') === 'generated') {
            return false;
        }
        $ev = $pdo->prepare(
            'SELECT status, order_id FROM subscription_fulfilment_events WHERE subscription_delivery_day_id = ? LIMIT 1 FOR UPDATE'
        );
        $ev->execute([$dayId]);
        $e = $ev->fetch(PDO::FETCH_ASSOC);
        if ($e !== false) {
            if ((string) $e['status'] === 'completed' || !empty($e['order_id'])) {
                return false;
            }
            if ((string) $e['status'] === 'pending') {
                return false;
            }
        }
        $ded = $pdo->prepare(
            "SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ? AND status = 'deducted'"
        );
        $ded->execute([$dayId]);

        return (int) $ded->fetchColumn() > 0;
    }
}
