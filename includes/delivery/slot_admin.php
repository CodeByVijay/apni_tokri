<?php

declare(strict_types=1);

final class DeliverySlotRepository
{
    /** @var list<int> */
    public const LIST_PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public static function normalizeListPerPage(int $perPage): int
    {
        return in_array($perPage, self::LIST_PER_PAGE_OPTIONS, true) ? $perPage : 25;
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query('SELECT * FROM delivery_slots ORDER BY sort_order, id');

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<int> Slot IDs in current display order (all slots, including inactive). */
    public static function orderedIdList(): array
    {
        $ids = [];
        foreach (self::all() as $row) {
            $ids[] = (int) ($row['id'] ?? 0);
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public static function listForAdmin(?string $search, int $page, int $perPage): array
    {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => self::normalizeListPerPage($perPage)];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $perPage = self::normalizeListPerPage($perPage);
        $page = max(1, $page);
        $where = '';
        $params = [];
        $q = trim($search ?? '');
        if ($q !== '') {
            $where = ' WHERE (label LIKE ? OR slug LIKE ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like];
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_slots' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT * FROM delivery_slots' . $where . ' ORDER BY sort_order, id LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** All orders ever linked to this slot (slug lock / delete guards only — not capacity). */
    public static function orderReferenceCount(int $slotId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM orders WHERE delivery_slot_id = ?');
        $stmt->execute([$slotId]);

        return (int) $stmt->fetchColumn();
    }

    /** Orders currently consuming slot capacity (same rules as checkout). */
    public static function capacityConsumingOrderCount(int $slotId): int
    {
        return DeliverySlotReservationService::countOrdersConsumingSlot($slotId);
    }

    /**
     * Reference counts used to decide whether a slot may be hard-deleted.
     *
     * @return array{orders: int, reservations: int, subscription_days: int, subscriptions_by_slug: int, completion_exceptions: int}
     */
    public static function referenceCounts(int $slotId): array
    {
        $slot = self::find($slotId);
        if ($slot === null) {
            return [
                'orders' => 0,
                'reservations' => 0,
                'subscription_days' => 0,
                'subscriptions_by_slug' => 0,
                'completion_exceptions' => 0,
            ];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $slug = (string) ($slot['slug'] ?? '');

        $orders = self::orderReferenceCount($slotId);

        $reservations = 0;
        if (DeliverySlotReservationService::tableReady()) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM delivery_slot_reservations WHERE delivery_slot_id = ?');
            $stmt->execute([$slotId]);
            $reservations = (int) $stmt->fetchColumn();
        }

        $subDays = 0;
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM subscription_delivery_days WHERE preferred_delivery_slot_id = ?');
            $stmt->execute([$slotId]);
            $subDays = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $subDays = 0;
        }

        $subsBySlug = 0;
        if ($slug !== '') {
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM customer_subscriptions WHERE preferred_delivery_slot_slug = ?');
                $stmt->execute([$slug]);
                $subsBySlug = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                $subsBySlug = 0;
            }
        }

        $exceptions = 0;
        if (PaymentCompletionExceptionService::tableReady()) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_completion_exceptions WHERE delivery_slot_id = ?');
            $stmt->execute([$slotId]);
            $exceptions = (int) $stmt->fetchColumn();
        }

        return [
            'orders' => $orders,
            'reservations' => $reservations,
            'subscription_days' => $subDays,
            'subscriptions_by_slug' => $subsBySlug,
            'completion_exceptions' => $exceptions,
        ];
    }

    public static function hasBlockingReferences(int $slotId): bool
    {
        $c = self::referenceCounts($slotId);

        return $c['orders'] > 0
            || $c['reservations'] > 0
            || $c['subscription_days'] > 0
            || $c['subscriptions_by_slug'] > 0;
    }

    public static function slugInUse(string $slug, ?int $excludeId = null): bool
    {
        Install::ensureConnection();
        $sql = 'SELECT id FROM delivery_slots WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }
}

final class DeliverySlotAdminService
{
    /**
     * @return array{ok: bool, errors: list<string>, slug?: string}
     */
    public static function validatePayload(array $post, ?int $excludeId = null): array
    {
        $errors = [];
        $name = trim((string) ($post['label'] ?? ''));
        if ($name === '') {
            $errors[] = 'Slot name is required.';
        }
        $existing = $excludeId !== null ? DeliverySlotRepository::find($excludeId) : null;
        if ($excludeId !== null && $existing !== null && DeliverySlotRepository::orderReferenceCount($excludeId) > 0) {
            $resolvedSlug = (string) ($existing['slug'] ?? '');
        } else {
            $slugResult = CatalogueSlug::resolveForAdminSave(
                'delivery_slot',
                $name,
                $post,
                $existing,
                $excludeId
            );
            if (!$slugResult['ok']) {
                foreach ($slugResult['errors'] as $msg) {
                    $errors[] = (string) $msg;
                }
                $resolvedSlug = '';
            } else {
                $resolvedSlug = (string) $slugResult['slug'];
            }
        }
        if ($resolvedSlug !== '' && strlen($resolvedSlug) > 32) {
            $errors[] = 'Slug must be 32 characters or fewer.';
        }
        if ($resolvedSlug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $resolvedSlug)) {
            $errors[] = 'Use lowercase letters, numbers and hyphens only.';
        }
        if ($excludeId !== null && $existing !== null && $resolvedSlug !== ''
            && (string) $existing['slug'] !== $resolvedSlug
            && DeliverySlotRepository::orderReferenceCount($excludeId) > 0) {
            $errors[] = 'Cannot change slug — orders reference this slot. Deactivate and add a new slot instead.';
        }
        $cap = (int) ($post['capacity'] ?? 0);
        if ($cap < 1) {
            $errors[] = 'Capacity must be at least 1.';
        }
        $start = (string) ($post['start_time'] ?? '');
        $end = (string) ($post['end_time'] ?? '');
        if ($start === '' || $end === '') {
            $errors[] = 'Start and end times are required.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'errors' => [], 'slug' => $resolvedSlug];
    }

    /** @return array{ok: bool, errors: list<string>} */
    public static function saveFromPost(array $post, ?int $id = null): array
    {
        $validation = self::validatePayload($post, $id);
        if (!$validation['ok']) {
            return ['ok' => false, 'errors' => $validation['errors']];
        }
        $payload = self::normalize($post, (string) ($validation['slug'] ?? ''));
        if ($id === null) {
            self::insert($payload);
        } else {
            self::update($id, $payload);
        }

        return ['ok' => true, 'errors' => []];
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteForAdmin(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid slot.'];
        }
        $slot = DeliverySlotRepository::find($id);
        if ($slot === null) {
            return ['ok' => false, 'message' => 'Slot not found.'];
        }
        if (DeliverySlotRepository::hasBlockingReferences($id)) {
            $c = DeliverySlotRepository::referenceCounts($id);
            $parts = [];
            if ($c['orders'] > 0) {
                $parts[] = $c['orders'] . ' order(s)';
            }
            if ($c['reservations'] > 0) {
                $parts[] = $c['reservations'] . ' reservation(s)';
            }
            if ($c['subscription_days'] > 0) {
                $parts[] = $c['subscription_days'] . ' subscription delivery day(s)';
            }
            if ($c['subscriptions_by_slug'] > 0) {
                $parts[] = $c['subscriptions_by_slug'] . ' subscription(s) prefer this slot';
            }

            return [
                'ok' => false,
                'message' => 'This slot is referenced by ' . implode(', ', $parts)
                    . '. It cannot be deleted. Deactivate it instead to stop new bookings while keeping history intact.',
            ];
        }
        Install::ensureConnection();
        Database::pdo()->prepare('DELETE FROM delivery_slots WHERE id = ? LIMIT 1')->execute([$id]);

        return ['ok' => true, 'message' => 'Delivery slot deleted.'];
    }

    public static function setActive(int $id, bool $active): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE delivery_slots SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Persist a new display sequence for every delivery slot (sort_order 1..N).
     * Inactive slots stay in the admin reorder list; storefront lists still filter is_active = 1.
     *
     * @param list<int> $orderedIds     Desired ID sequence (complete set).
     * @param list<int> $baselineIds    ID sequence when the reorder UI was loaded (optimistic concurrency).
     * @return array{ok: bool, message: string}
     */
    public static function reorderAllSlots(array $orderedIds, array $baselineIds): array
    {
        $orderedIds = array_values(array_unique(array_filter(array_map('intval', $orderedIds), static fn (int $id): bool => $id > 0)));
        $baselineIds = array_values(array_filter(array_map('intval', $baselineIds), static fn (int $id): bool => $id > 0));

        if ($orderedIds === []) {
            return ['ok' => false, 'message' => 'No slots to reorder.'];
        }

        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database is not available.'];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $currentIds = DeliverySlotRepository::orderedIdList();

        if ($currentIds === []) {
            return ['ok' => false, 'message' => 'No slots to reorder.'];
        }

        if (count($orderedIds) !== count($currentIds)) {
            return ['ok' => false, 'message' => 'Reorder must include every delivery slot. Refresh the page and try again.'];
        }

        $currentSet = $currentIds;
        sort($currentSet);
        $orderedSet = $orderedIds;
        sort($orderedSet);
        if ($orderedSet !== $currentSet) {
            return ['ok' => false, 'message' => 'Invalid slot selection. Refresh the page and try again.'];
        }

        $baselineFiltered = array_values(array_filter($baselineIds, static fn (int $id): bool => in_array($id, $currentIds, true)));
        if ($baselineFiltered !== $currentIds) {
            return [
                'ok' => false,
                'message' => 'Slot order was changed elsewhere. Reload this page to see the latest sequence, then save again.',
            ];
        }

        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE delivery_slots SET sort_order = ? WHERE id = ?');
            foreach ($orderedIds as $index => $id) {
                $update->execute([$index + 1, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Could not save slot order. No changes were applied.'];
        }

        return ['ok' => true, 'message' => 'Delivery slot order saved.'];
    }

    /** @return array<int, array<string, array{booked: int, remaining: int, capacity: int}>> keyed by slot id */
    public static function nextSevenDayCapacity(): array
    {
        $out = [];
        if (!Install::isSchemaReady()) {
            return $out;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $start = DeliveryConfig::now();
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $start->modify('+' . $i . ' days')->format('Y-m-d');
        }
        foreach (DeliverySlotRepository::all() as $slot) {
            $sid = (int) $slot['id'];
            $out[$sid] = [];
            foreach ($dates as $date) {
                $out[$sid][$date] = DeliverySlotReservationService::capacityBreakdown($pdo, $sid, $date);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $post */
    private static function normalize(array $post, string $resolvedSlug): array
    {
        $mask = 0;
        for ($d = 1; $d <= 7; $d++) {
            if (!empty($post['weekday_' . $d])) {
                $mask |= 1 << ($d - 1);
            }
        }
        if ($mask === 0) {
            $mask = 127;
        }
        $providers = [];
        if (isset($post['providers']) && is_array($post['providers'])) {
            foreach ($post['providers'] as $p) {
                $p = trim((string) $p);
                if ($p !== '' && $p === 'manual') {
                    $providers[] = $p;
                }
            }
        }
        if ($providers === []) {
            $providers = ['manual'];
        }

        return [
            'slug' => $resolvedSlug !== '' ? $resolvedSlug : self::normalizeSlug((string) ($post['slug'] ?? '')),
            'label' => trim((string) ($post['label'] ?? '')),
            'window_label' => trim((string) ($post['window_label'] ?? '')),
            'delivery_day' => (string) ($post['delivery_day'] ?? 'same_day') === 'next_day' ? 'next_day' : 'same_day',
            'start_time' => self::timeHms((string) ($post['start_time'] ?? '09:00')),
            'end_time' => self::timeHms((string) ($post['end_time'] ?? '12:00')),
            'cutoff_time' => self::timeHms((string) ($post['cutoff_time'] ?? '08:30')),
            'capacity' => max(1, (int) ($post['capacity'] ?? 25)),
            'sort_order' => (int) ($post['sort_order'] ?? 0),
            'is_active' => !empty($post['is_active']) ? 1 : 0,
            'weekday_mask' => $mask,
            'prep_buffer_minutes' => ($post['prep_buffer_minutes'] ?? '') !== '' ? (int) $post['prep_buffer_minutes'] : null,
            'booking_cutoff_minutes' => ($post['booking_cutoff_minutes'] ?? '') !== '' ? (int) $post['booking_cutoff_minutes'] : null,
            'max_distance_km' => ($post['max_distance_km'] ?? '') !== '' ? (float) $post['max_distance_km'] : null,
            'allowed_provider_slugs_json' => json_encode(array_values(array_unique($providers)), JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function insert(array $payload): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'INSERT INTO delivery_slots (
               slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity,
               sort_order, is_active, weekday_mask, prep_buffer_minutes, booking_cutoff_minutes,
               max_distance_km, allowed_provider_slugs_json
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $payload['slug'],
            $payload['label'],
            $payload['window_label'] !== '' ? $payload['window_label'] : $payload['label'],
            $payload['delivery_day'],
            $payload['start_time'],
            $payload['end_time'],
            $payload['cutoff_time'],
            $payload['capacity'],
            $payload['sort_order'],
            $payload['is_active'],
            $payload['weekday_mask'],
            $payload['prep_buffer_minutes'],
            $payload['booking_cutoff_minutes'],
            $payload['max_distance_km'],
            $payload['allowed_provider_slugs_json'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private static function update(int $id, array $payload): void
    {
        $existing = DeliverySlotRepository::find($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Slot not found.');
        }
        if ((string) $existing['slug'] !== $payload['slug'] && DeliverySlotRepository::orderReferenceCount($id) > 0) {
            throw new InvalidArgumentException('Cannot change slug — orders reference this slot. Deactivate and add a new slot instead.');
        }
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE delivery_slots SET
               slug = ?, label = ?, window_label = ?, delivery_day = ?, start_time = ?, end_time = ?,
               cutoff_time = ?, capacity = ?, sort_order = ?, is_active = ?, weekday_mask = ?,
               prep_buffer_minutes = ?, booking_cutoff_minutes = ?, max_distance_km = ?,
               allowed_provider_slugs_json = ?
             WHERE id = ?'
        )->execute([
            $payload['slug'],
            $payload['label'],
            $payload['window_label'] !== '' ? $payload['window_label'] : $payload['label'],
            $payload['delivery_day'],
            $payload['start_time'],
            $payload['end_time'],
            $payload['cutoff_time'],
            $payload['capacity'],
            $payload['sort_order'],
            $payload['is_active'],
            $payload['weekday_mask'],
            $payload['prep_buffer_minutes'],
            $payload['booking_cutoff_minutes'],
            $payload['max_distance_km'],
            $payload['allowed_provider_slugs_json'],
            $id,
        ]);
    }

    private static function normalizeSlug(string $slug): string
    {
        return strtolower(preg_replace('/[^a-z0-9_-]+/', '-', trim($slug)) ?? '');
    }

    private static function timeHms(string $t): string
    {
        $t = trim($t);
        if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
            return $t . ':00';
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) {
            return $t;
        }

        return '09:00:00';
    }

    /** @return list<string> */
    public static function decodeProviders(?string $json): array
    {
        if ($json === null || $json === '') {
            return ['manual'];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : ['manual'];
    }
}
