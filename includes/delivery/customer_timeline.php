<?php

declare(strict_types=1);

final class DeliveryCustomerTimeline
{
    /**
     * @return list<array{status: string, at: string, done: bool}>
     */
    public static function forOrderId(int $orderId): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $delStmt = $pdo->prepare('SELECT id FROM deliveries WHERE order_id = ? LIMIT 1');
        $delStmt->execute([$orderId]);
        $deliveryId = (int) ($delStmt->fetchColumn() ?: 0);
        if ($deliveryId <= 0) {
            return self::fallbackFromOrderHistory($orderId);
        }

        $hasVisible = DeliveryAssignmentService::historyExtendedReady($pdo);
        if ($hasVisible) {
            $stmt = $pdo->prepare(
                'SELECT status, public_label, created_at FROM delivery_status_history
                 WHERE delivery_id = ? AND customer_visible = 1 ORDER BY created_at ASC, id ASC'
            );
        } else {
            $stmt = $pdo->prepare(
                'SELECT status, created_at FROM delivery_status_history WHERE delivery_id = ? ORDER BY created_at ASC, id ASC'
            );
        }
        $stmt->execute([$deliveryId]);
        $steps = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $st = DeliveryStatus::normalize((string) $h['status']);
            if (isset($seen[$st])) {
                continue;
            }
            $seen[$st] = true;
            $label = $hasVisible && !empty($h['public_label'])
                ? (string) $h['public_label']
                : DeliveryStatus::publicLabel($st);
            if ($st === DeliveryStatus::FAILED) {
                $label = 'We could not complete this delivery. Our team will contact you.';
            }
            $steps[] = [
                'status' => $label,
                'at' => (string) $h['created_at'],
                'done' => true,
            ];
        }
        if ($steps === []) {
            return self::fallbackFromOrderHistory($orderId);
        }

        return $steps;
    }

    /**
     * Customer-visible history rows (for tests — same fields as timeline source query).
     *
     * @return list<array<string, mixed>>
     */
    public static function visibleHistoryRows(int $deliveryId): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        if (!DeliveryAssignmentService::historyExtendedReady($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT status, note, public_label, customer_visible, created_at FROM delivery_status_history
             WHERE delivery_id = ? AND customer_visible = 1 ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$deliveryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array{status: string, at: string, done: bool}> */
    private static function fallbackFromOrderHistory(int $orderId): array
    {
        $pdo = Database::pdo();
        $hist = $pdo->prepare(
            'SELECT status, created_at FROM order_status_history WHERE order_id = ? ORDER BY created_at'
        );
        $hist->execute([$orderId]);
        $timeline = [];
        foreach ($hist->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $timeline[] = [
                'status' => (string) $h['status'],
                'at' => (string) $h['created_at'],
                'done' => true,
            ];
        }
        if ($timeline === []) {
            $timeline[] = ['status' => 'Order confirmed', 'at' => '', 'done' => true];
        }

        return $timeline;
    }
}
