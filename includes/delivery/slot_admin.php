<?php

declare(strict_types=1);

final class DeliverySlotRepository
{
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

    public static function orderReferenceCount(int $slotId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM orders WHERE delivery_slot_id = ?');
        $stmt->execute([$slotId]);

        return (int) $stmt->fetchColumn();
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
    /** @return list<string> */
    public static function validatePayload(array $post, ?int $excludeId = null): array
    {
        $errors = [];
        $name = trim((string) ($post['label'] ?? ''));
        $slug = self::normalizeSlug((string) ($post['slug'] ?? ''));
        if ($name === '') {
            $errors[] = 'Slot name is required.';
        }
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $slug)) {
            $errors[] = 'Slug must be 2–32 chars: lowercase letters, numbers, underscore, hyphen.';
        } elseif (DeliverySlotRepository::slugInUse($slug, $excludeId)) {
            $errors[] = 'Slug is already in use.';
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

        return $errors;
    }

    /** @return array{ok: bool, errors: list<string>} */
    public static function saveFromPost(array $post, ?int $id = null): array
    {
        $errors = self::validatePayload($post, $id);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $payload = self::normalize($post);
        if ($id === null) {
            self::insert($payload);
        } else {
            self::update($id, $payload);
        }

        return ['ok' => true, 'errors' => []];
    }

    public static function setActive(int $id, bool $active): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE delivery_slots SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
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
    private static function normalize(array $post): array
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
            'slug' => self::normalizeSlug((string) $post['slug']),
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
