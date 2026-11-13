<?php

declare(strict_types=1);

final class DeliveryConfig
{
    /** @var array<string, mixed>|null */
    private static $store = null;

    public static function timezone(): string
    {
        $s = self::store();

        return (string) ($s['timezone'] ?? 'Asia/Kolkata');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::timezone()));
    }

    /** @return array<string, mixed> */
    public static function store(): array
    {
        if (self::$store !== null) {
            return self::$store;
        }
        if (!Install::isSchemaReady()) {
            self::$store = self::fallbackStore();

            return self::$store;
        }
        try {
            Install::ensureConnection();
            $row = Database::pdo()->query('SELECT * FROM delivery_store WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            self::$store = $row ?: self::fallbackStore();
        } catch (Throwable $e) {
            self::$store = self::fallbackStore();
        }

        return self::$store;
    }

    public static function slotsEnabled(): bool
    {
        return (bool) (self::store()['slots_enabled'] ?? true);
    }

    /** @return array<string, mixed> */
    private static function fallbackStore(): array
    {
        return [
            'latitude' => 22.7196,
            'longitude' => 75.8577,
            'max_radius_km' => 12.0,
            'timezone' => 'Asia/Kolkata',
            'slots_enabled' => 1,
            'prep_minutes' => 45,
            'business_open' => '08:00:00',
            'business_close' => '20:00:00',
            'mixed_cart_free_rule' => 'eligible_subtotal',
        ];
    }

    public static function resetCache(): void
    {
        self::$store = null;
    }
}

final class DeliveryZoneRepository
{
    /** @return list<array<string, mixed>> */
    public static function activeZones(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                'SELECT * FROM delivery_zones WHERE is_active = 1 ORDER BY sort_order, id'
            );

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_zones WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function allZones(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                'SELECT * FROM delivery_zones ORDER BY sort_order, id'
            );

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> active bands for overlap checks */
    public static function zonesForOverlapCheck(?int $excludeId): array
    {
        $zones = [];
        foreach (self::allZones() as $z) {
            if ($excludeId !== null && (int) ($z['id'] ?? 0) === $excludeId) {
                continue;
            }
            if (empty($z['is_active'])) {
                continue;
            }
            $zones[] = $z;
        }

        return $zones;
    }

    /** @param array<string, mixed> $payload */
    public static function insert(array $payload): int
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO delivery_zones (
               name, match_type, match_value, zone_kind, min_distance_km, max_distance_km,
               flat_fee, free_above, minimum_order_amount, max_order_weight_kg, eta_display, pincode_required,
               sort_order, is_active
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            (string) $payload['name'],
            (string) ($payload['match_type'] ?? 'pincode_prefix'),
            (string) ($payload['match_value'] ?? ''),
            (string) ($payload['zone_kind'] ?? 'pincode'),
            $payload['min_distance_km'],
            $payload['max_distance_km'],
            (float) ($payload['flat_fee'] ?? 40),
            (float) ($payload['free_above'] ?? 499),
            $payload['minimum_order_amount'],
            $payload['max_order_weight_kg'],
            $payload['eta_display'],
            (int) ($payload['pincode_required'] ?? 0),
            (int) ($payload['sort_order'] ?? 0),
            (int) ($payload['is_active'] ?? 1),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, mixed> $payload */
    public static function update(int $id, array $payload): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE delivery_zones SET
               name = ?, match_type = ?, match_value = ?, zone_kind = ?,
               min_distance_km = ?, max_distance_km = ?,
               flat_fee = ?, free_above = ?, minimum_order_amount = ?, max_order_weight_kg = ?, eta_display = ?,
               pincode_required = ?, sort_order = ?, is_active = ?
             WHERE id = ?'
        )->execute([
            (string) $payload['name'],
            (string) ($payload['match_type'] ?? 'pincode_prefix'),
            (string) ($payload['match_value'] ?? ''),
            (string) ($payload['zone_kind'] ?? 'pincode'),
            $payload['min_distance_km'],
            $payload['max_distance_km'],
            (float) ($payload['flat_fee'] ?? 40),
            (float) ($payload['free_above'] ?? 499),
            $payload['minimum_order_amount'],
            $payload['max_order_weight_kg'],
            $payload['eta_display'],
            (int) ($payload['pincode_required'] ?? 0),
            (int) ($payload['sort_order'] ?? 0),
            (int) ($payload['is_active'] ?? 1),
            $id,
        ]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE delivery_zones SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    public static function hasColumn(string $column): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'delivery_zones\' AND COLUMN_NAME = ?'
            );
            $stmt->execute([$column]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

final class DeliveryPolicies
{
    public static function zoneResolutionMode(): string
    {
        return DeliveryZoneResolver::zoneResolutionMode();
    }

    public static function isDistanceHybridEnabled(): bool
    {
        return self::zoneResolutionMode() === 'distance_hybrid';
    }
}

final class DeliveryContext
{
    private const SESSION_PIN = 'delivery_check_pin';

    public static function setPin(string $pincode): void
    {
        $pin = preg_replace('/\D+/', '', $pincode) ?? '';
        if (preg_match('/^\d{6}$/', $pin)) {
            $_SESSION[self::SESSION_PIN] = $pin;
        }
    }

    public static function sessionPin(): ?string
    {
        $p = $_SESSION[self::SESSION_PIN] ?? null;

        return is_string($p) && preg_match('/^\d{6}$/', $p) ? $p : null;
    }

    /**
     * @return array{
     *   pin: string,
     *   serviceable: bool,
     *   zone: array<string, mixed>|null,
     *   message: string,
     *   distance_km: float|null,
     *   requires_map_pin?: bool,
     *   message_detail?: string
     * }
     */
    public static function forPin(?string $pincode, ?float $latitude = null, ?float $longitude = null): array
    {
        $pin = preg_replace('/\D+/', '', (string) $pincode) ?? '';
        if (!preg_match('/^\d{6}$/', $pin)) {
            return [
                'pin' => $pin,
                'serviceable' => false,
                'zone' => null,
                'message' => 'Enter a valid 6-digit pincode.',
                'distance_km' => null,
                'requires_map_pin' => false,
            ];
        }

        return DeliveryService::evaluatePincode($pin, $latitude, $longitude);
    }
}
