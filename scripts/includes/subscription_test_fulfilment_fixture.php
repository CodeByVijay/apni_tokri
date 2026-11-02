<?php

declare(strict_types=1);

/**
 * CLI test fixtures only — ensures subscription fulfilment tests have a bookable slot/date.
 */
final class SubscriptionTestFulfilmentFixture
{
    public const SLOT_SLUG = 'sub_fulfil_test';

    /** Prefix for Stage 11 hardening CLI fixtures (safe to purge between runs). */
    public const STAGE11_HARDENING_SUB_PREFIX = 'SUB-H-';

    /**
     * Remove prior CLI test subscriptions matching subscription_number prefix (not real customer data).
     */
    public static function purgeTestSubscriptionsByNumberPrefix(PDO $pdo, string $prefix): void
    {
        if ($prefix === '' || !Install::isSchemaReady()) {
            return;
        }
        $like = $prefix . '%';
        $stmt = $pdo->prepare('SELECT id FROM customer_subscriptions WHERE subscription_number LIKE ?');
        $stmt->execute([$like]);
        $subIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($subIds === []) {
            return;
        }
        $idList = implode(',', array_map('intval', $subIds));
        $pdo->exec(
            "DELETE FROM deliveries WHERE order_id IN (
                SELECT o.id FROM orders o
                INNER JOIN subscription_delivery_days d ON d.id = o.subscription_delivery_day_id
                WHERE d.customer_subscription_id IN ({$idList})
            )"
        );
        $pdo->exec(
            "DELETE FROM orders WHERE subscription_delivery_day_id IN (
                SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN ({$idList})
            )"
        );
        $pdo->exec("DELETE FROM customer_subscriptions WHERE id IN ({$idList})");
    }

    /**
     * Pick a delivery_date not yet used on this subscription (uq_sub_delivery_day).
     */
    public static function reserveDeliveryDate(PDO $pdo, int $subscriptionId, int $startOffsetDays = 30): string
    {
        $tz = new DateTimeZone(DeliveryConfig::timezone());
        $usedStmt = $pdo->prepare(
            'SELECT delivery_date FROM subscription_delivery_days WHERE customer_subscription_id = ?'
        );
        $usedStmt->execute([$subscriptionId]);
        $used = [];
        foreach ($usedStmt->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $used[(string) $d] = true;
        }
        $base = DeliveryConfig::now()->modify('+' . max(0, $startOffsetDays) . ' days');
        for ($i = 0; $i < 120; $i++) {
            $candidate = $base->modify('+' . $i . ' days')->format('Y-m-d');
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        throw new RuntimeException('No unused delivery date for test subscription ' . $subscriptionId . '.');
    }

    /** @return array<string, mixed> slot row */
    public static function ensureBookableSlot(PDO $pdo): array
    {
        $slug = self::SLOT_SLUG;
        $row = DeliverySlotRepository::findBySlug($slug);
        if ($row === null) {
            $pdo->prepare(
                'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time,
                 capacity, sort_order, is_active, weekday_mask, allowed_provider_slugs_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $slug,
                'Subscription test fulfilment',
                'Test window',
                'same_day',
                '23:55:00',
                '23:59:00',
                '23:58:00',
                500,
                9990,
                1,
                127,
                json_encode(['manual']),
            ]);
            $row = DeliverySlotRepository::findBySlug($slug);
        } else {
            $pdo->prepare(
                'UPDATE delivery_slots SET is_active = 1, capacity = 500, weekday_mask = 127,
                 start_time = ?, end_time = ?, cutoff_time = ?, delivery_day = ? WHERE slug = ?'
            )->execute(['23:55:00', '23:59:00', '23:58:00', 'same_day', $slug]);
            $row = DeliverySlotRepository::findBySlug($slug);
        }
        if ($row === null) {
            throw new RuntimeException('Could not create subscription test slot.');
        }

        return $row;
    }

    public static function pickDeliveryDateForSlot(array $slotRow): string
    {
        $tz = new DateTimeZone(DeliveryConfig::timezone());
        $now = DeliveryConfig::now();
        Install::ensureConnection();
        $pdo = Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);

        for ($i = 0; $i < 14; $i++) {
            $date = $now->modify('+' . $i . ' days')->format('Y-m-d');
            if (!DeliverySlotService::isWeekdayAllowed($slotRow, $date)) {
                continue;
            }
            if (DeliverySlotService::assertBookableLocked($pdo, $slotRow, $date, null) === null) {
                return $date;
            }
        }

        throw new RuntimeException('No bookable date for subscription test slot within 14 days.');
    }

    public static function prepareDeliveryDay(PDO $pdo, int $dayId): string
    {
        $custStmt = $pdo->prepare(
            'SELECT cs.customer_id FROM subscription_delivery_days d
             INNER JOIN customer_subscriptions cs ON cs.id = d.customer_subscription_id WHERE d.id = ? LIMIT 1'
        );
        $custStmt->execute([$dayId]);
        $customerId = (int) ($custStmt->fetchColumn() ?: 0);
        if ($customerId > 0) {
            self::ensureServiceableAddress($pdo, $customerId);
        }

        $slot = self::ensureBookableSlot($pdo);
        $date = self::pickDeliveryDateForSlot($slot);
        $pdo->prepare(
            'UPDATE subscription_delivery_days SET delivery_date = ?, preferred_delivery_slot_slug = ?,
             preferred_delivery_slot_id = ?, selection_cutoff_at = NOW() - INTERVAL 1 DAY WHERE id = ?'
        )->execute([$date, (string) $slot['slug'], (int) $slot['id'], $dayId]);

        return $date;
    }

    public static function ensureServiceableAddress(PDO $pdo, int $customerId): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, pincode FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $addr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$addr) {
            return;
        }
        $pin = preg_replace('/\D+/', '', (string) ($addr['pincode'] ?? '')) ?: '452001';
        $store = DeliveryConfig::store();
        $lat = (float) ($store['latitude'] ?? 0);
        $lon = (float) ($store['longitude'] ?? 0);
        $mode = DeliveryZoneResolver::zoneResolutionMode();
        if ($mode === 'pincode_legacy') {
            if (!DeliveryService::isPincodeServiceable($pin)) {
                $pin = '452010';
            }
            $pdo->prepare('UPDATE customer_addresses SET pincode = ? WHERE id = ?')->execute([$pin, (int) $addr['id']]);

            return;
        }
        $pdo->prepare(
            'UPDATE customer_addresses SET pincode = ?, latitude = ?, longitude = ? WHERE id = ?'
        )->execute([$pin !== '' ? $pin : '452010', $lat + 0.01, $lon + 0.01, (int) $addr['id']]);
    }
}
