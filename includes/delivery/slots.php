<?php

declare(strict_types=1);

final class DeliverySlotService
{
    /** @return list<array<string, mixed>> */
    public static function availableForCheckout(): array
    {
        if (!DeliveryConfig::slotsEnabled()) {
            return [];
        }
        if (AppEnvironment::usePersistedStorefront() && !CustomerAuth::check()) {
            return [];
        }
        if (!Install::isSchemaReady()) {
            return self::fallbackSlots();
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            DeliverySlotReservationService::releaseExpiredHolds($pdo);
            $now = DeliveryConfig::now();
            $today = $now->format('Y-m-d');
            $tomorrow = $now->modify('+1 day')->format('Y-m-d');
            $stmt = $pdo->query(
                'SELECT * FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id'
            );
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
                $date = ($slot['delivery_day'] ?? '') === 'next_day' ? $tomorrow : $today;
                if (!self::isWeekdayAllowed($slot, $date)) {
                    continue;
                }
                if (!self::isBeforeCutoff($slot, $now, $date)) {
                    continue;
                }
                $remaining = self::remainingCapacity((int) $slot['id'], $date, $pdo);
                if ($remaining <= 0) {
                    continue;
                }
                $out[] = [
                    'id' => (int) $slot['id'],
                    'slug' => (string) $slot['slug'],
                    'label' => (string) $slot['label'],
                    'window' => (string) $slot['window_label'],
                    'delivery_date' => $date,
                    'remaining' => $remaining,
                ];
            }

            return $out;
        } catch (Throwable $e) {
            return self::fallbackSlots();
        }
    }

    public static function resolveBySlug(string $slug): ?array
    {
        if (!Install::isSchemaReady()) {
            foreach (self::fallbackSlots() as $s) {
                if ($s['slug'] === $slug) {
                    return $s;
                }
            }

            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $now = DeliveryConfig::now();
        $date = ($row['delivery_day'] ?? '') === 'next_day'
            ? $now->modify('+1 day')->format('Y-m-d')
            : $now->format('Y-m-d');
        if (!self::isWeekdayAllowed($row, $date)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'label' => (string) $row['label'],
            'window' => (string) $row['window_label'],
            'delivery_date' => $date,
            'row' => $row,
        ];
    }

    /** @param array<string, mixed> $slotRow */
    public static function assertBookable(array $slotRow, string $deliveryDate): ?string
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);

        return self::assertBookableLocked($pdo, $slotRow, $deliveryDate, null);
    }

    /** Validate checkout POST of slot slug + calendar delivery date. */
    public static function validateCustomerSlotDate(string $slug, string $deliveryDateYmd): ?string
    {
        if (!DeliveryConfig::slotsEnabled()) {
            return null;
        }
        $slug = trim($slug);
        if ($slug === '' || $slug === 'auto') {
            return 'Choose a valid delivery slot.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDateYmd)) {
            return 'Choose a valid delivery date for this slot.';
        }
        $today = DeliveryConfig::now()->format('Y-m-d');
        if ($deliveryDateYmd < $today) {
            return 'This delivery date is no longer available.';
        }
        if (!Install::isSchemaReady()) {
            return 'Delivery slots are unavailable.';
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'Choose a valid delivery slot.';
        }

        return self::assertBookable($row, $deliveryDateYmd);
    }

    /**
     * @param array<string, mixed> $slotRow
     */
    public static function assertBookableLocked(
        PDO $pdo,
        array $slotRow,
        string $deliveryDate,
        ?int $excludeOrderId,
        bool $skipCustomerCutoff = false
    ): ?string {
        $now = DeliveryConfig::now();
        if (empty($slotRow['is_active'])) {
            return 'This delivery slot is not available.';
        }
        if (!self::isWeekdayAllowed($slotRow, $deliveryDate)) {
            return 'This delivery slot is not available on that day.';
        }
        if (!$skipCustomerCutoff && !self::isBeforeCutoff($slotRow, $now, $deliveryDate)) {
            return 'This delivery slot is past cutoff time.';
        }
        $slotId = (int) $slotRow['id'];
        $cap = (int) ($slotRow['capacity'] ?? 0);
        $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $deliveryDate, $excludeOrderId);
        if ($used >= $cap) {
            return 'This delivery slot is full. Choose another window.';
        }

        return null;
    }

    /**
     * Active slots with capacity on a fixed calendar date (admin reschedule).
     *
     * @return list<array{id: int, slug: string, label: string, window: string, delivery_date: string, remaining: int}>
     */
    public static function listOptionsForDeliveryDate(
        PDO $pdo,
        string $deliveryDate,
        ?int $excludeOrderId = null,
        bool $skipCustomerCutoff = false
    ): array {
        if (!DeliveryConfig::slotsEnabled() || !Install::isSchemaReady()) {
            return [];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            return [];
        }
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $stmt = $pdo->query('SELECT * FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id');
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $slot) {
            if (!self::isWeekdayAllowed($slot, $deliveryDate)) {
                continue;
            }
            if (!$skipCustomerCutoff && !self::isBeforeCutoff($slot, DeliveryConfig::now(), $deliveryDate)) {
                continue;
            }
            $slotId = (int) $slot['id'];
            $cap = (int) ($slot['capacity'] ?? 0);
            $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $deliveryDate, $excludeOrderId);
            $remaining = max(0, $cap - $used);
            if ($remaining <= 0) {
                continue;
            }
            $err = self::assertBookableLocked($pdo, $slot, $deliveryDate, $excludeOrderId, $skipCustomerCutoff);
            if ($err !== null) {
                continue;
            }
            $out[] = [
                'id' => $slotId,
                'slug' => (string) $slot['slug'],
                'label' => (string) $slot['label'],
                'window' => (string) $slot['window_label'],
                'delivery_date' => $deliveryDate,
                'remaining' => $remaining,
            ];
        }

        return $out;
    }

    public static function remainingCapacity(int $slotId, string $deliveryDate, ?PDO $pdo = null): int
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $slot = $pdo->prepare('SELECT capacity FROM delivery_slots WHERE id = ?');
        $slot->execute([$slotId]);
        $cap = (int) ($slot->fetchColumn() ?: 0);
        $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $deliveryDate, null);

        return max(0, $cap - $used);
    }

    /**
     * @return array{booked: int, remaining: int, capacity: int}
     */
    public static function capacityStats(int $slotId, string $deliveryDate, ?PDO $pdo = null): array
    {
        Install::ensureConnection();
        $pdo = $pdo ?? Database::pdo();
        $stmt = $pdo->prepare('SELECT capacity FROM delivery_slots WHERE id = ?');
        $stmt->execute([$slotId]);
        $cap = (int) ($stmt->fetchColumn() ?: 0);
        $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $deliveryDate, null);

        return ['booked' => $used, 'remaining' => max(0, $cap - $used), 'capacity' => $cap];
    }

    public static function automaticEta(): string
    {
        $store = DeliveryConfig::store();
        $now = DeliveryConfig::now();
        $prep = (int) ($store['prep_minutes'] ?? 45);
        $eta = $now->modify('+' . $prep . ' minutes');
        $close = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $now->format('Y-m-d') . ' ' . ($store['business_close'] ?? '20:00:00'),
            new DateTimeZone(DeliveryConfig::timezone())
        );
        if ($close && $eta > $close) {
            $eta = $close;
        }

        return 'Estimated delivery by ' . $eta->format('g:i A') . ' (Indore time)';
    }

    /** @param array<string, mixed> $slot */
    public static function isWeekdayAllowed(array $slot, string $deliveryDateYmd): bool
    {
        $mask = (int) ($slot['weekday_mask'] ?? 127);
        if ($mask <= 0) {
            return false;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $deliveryDateYmd, new DateTimeZone(DeliveryConfig::timezone()));
        if (!$dt) {
            return true;
        }
        $iso = (int) $dt->format('N');
        $bit = 1 << ($iso - 1);

        return ($mask & $bit) !== 0;
    }

    /** @param array<string, mixed> $slot */
    public static function isBeforeCutoff(array $slot, DateTimeImmutable $now, string $deliveryDate): bool
    {
        $deadline = self::bookingDeadline($slot, $deliveryDate);
        if ($deadline === null) {
            return true;
        }

        return $now < $deadline;
    }

    /** @param array<string, mixed> $slot */
    public static function bookingDeadline(array $slot, string $deliveryDate): ?DateTimeImmutable
    {
        $tz = new DateTimeZone(DeliveryConfig::timezone());
        $candidates = [];

        $legacyCutoff = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $deliveryDate . ' ' . ($slot['cutoff_time'] ?? '23:59:59'),
            $tz
        );
        if ($legacyCutoff) {
            $candidates[] = $legacyCutoff;
        }

        $start = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $deliveryDate . ' ' . ($slot['start_time'] ?? '00:00:00'),
            $tz
        );
        if ($start) {
            if (isset($slot['booking_cutoff_minutes']) && $slot['booking_cutoff_minutes'] !== null && $slot['booking_cutoff_minutes'] !== '') {
                $mins = max(0, (int) $slot['booking_cutoff_minutes']);
                $candidates[] = $start->modify('-' . $mins . ' minutes');
            }
            $store = DeliveryConfig::store();
            $prep = isset($slot['prep_buffer_minutes']) && $slot['prep_buffer_minutes'] !== null && $slot['prep_buffer_minutes'] !== ''
                ? (int) $slot['prep_buffer_minutes']
                : (int) ($store['prep_minutes'] ?? 45);
            if ($prep > 0) {
                $candidates[] = $start->modify('-' . $prep . ' minutes');
            }
        }

        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
            return $a <=> $b;
        });

        return $candidates[0];
    }

    /**
     * Pick the first active slot with capacity for a fixed delivery date (subscription fulfilment).
     *
     * @return array{id: int, slug: string, label: string, window: string, delivery_date: string, row: array<string, mixed>}|null
     */
    public static function firstBookableForDate(string $deliveryDate): ?array
    {
        if (!DeliveryConfig::slotsEnabled() || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $now = DeliveryConfig::now();
        $stmt = $pdo->query(
            'SELECT * FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slotRow) {
            if (!self::isWeekdayAllowed($slotRow, $deliveryDate)) {
                continue;
            }
            if (self::assertBookableLocked($pdo, $slotRow, $deliveryDate, null) !== null) {
                continue;
            }
            if (!self::isBeforeCutoff($slotRow, $now, $deliveryDate)) {
                continue;
            }

            return [
                'id' => (int) $slotRow['id'],
                'slug' => (string) $slotRow['slug'],
                'label' => (string) $slotRow['label'],
                'window' => (string) $slotRow['window_label'],
                'delivery_date' => $deliveryDate,
                'row' => $slotRow,
            ];
        }

        return null;
    }

    /** @return list<string> known provider slugs for admin (D3 expands) */
    public static function knownProviderSlugs(): array
    {
        return [
            'manual' => 'Manual / in-house delivery',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function fallbackSlots(): array
    {
        $today = DeliveryConfig::now()->format('Y-m-d');
        $tomorrow = DeliveryConfig::now()->modify('+1 day')->format('Y-m-d');

        return [
            ['id' => 0, 'slug' => 'morning', 'label' => 'Today', 'window' => '9 AM – 12 PM', 'delivery_date' => $today, 'remaining' => 99],
            ['id' => 0, 'slug' => 'afternoon', 'label' => 'Today', 'window' => '3 PM – 6 PM', 'delivery_date' => $today, 'remaining' => 99],
            ['id' => 0, 'slug' => 'tomorrow', 'label' => 'Tomorrow', 'window' => 'Morning', 'delivery_date' => $tomorrow, 'remaining' => 99],
        ];
    }
}
