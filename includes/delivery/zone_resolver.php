<?php

declare(strict_types=1);

final class DeliveryZoneOverlapValidator
{
    /**
     * Active distance bands use [min_distance_km, max_distance_km) — min inclusive, max exclusive.
     *
     * @param list<array<string, mixed>> $activeZones
     * @return list<string> error messages
     */
    public static function validateBand(int $zoneId, ?float $min, ?float $max, array $activeZones): array
    {
        $errors = [];
        if ($min === null || $max === null) {
            $errors[] = 'Distance band requires both min and max km.';

            return $errors;
        }
        if ($min < 0 || $max <= 0 || $max <= $min) {
            $errors[] = 'Distance band must have 0 ≤ min < max (max is exclusive).';
        }

        foreach ($activeZones as $z) {
            if ((int) ($z['id'] ?? 0) === $zoneId) {
                continue;
            }
            if (empty($z['is_active'])) {
                continue;
            }
            $kind = (string) ($z['zone_kind'] ?? 'pincode');
            if (!in_array($kind, ['distance_band', 'hybrid'], true)) {
                continue;
            }
            $zMin = isset($z['min_distance_km']) ? (float) $z['min_distance_km'] : null;
            $zMax = isset($z['max_distance_km']) ? (float) $z['max_distance_km'] : null;
            if ($zMin === null || $zMax === null) {
                continue;
            }
            if (self::rangesOverlap($min, $max, $zMin, $zMax)) {
                $errors[] = 'Overlaps active band “' . (string) ($z['name'] ?? '') . '” '
                    . "[$zMin, $zMax) km.";
            }
        }

        return $errors;
    }

    /** [min, max) overlap test */
    public static function rangesOverlap(float $aMin, float $aMax, float $bMin, float $bMax): bool
    {
        return $aMin < $bMax && $bMin < $aMax;
    }

    /** @return list<string> */
    public static function validateSavePayload(array $payload, ?int $excludeId = null): array
    {
        $kind = (string) ($payload['zone_kind'] ?? 'pincode');
        $errors = [];

        if (in_array($kind, ['distance_band', 'hybrid'], true)) {
            $min = isset($payload['min_distance_km']) && $payload['min_distance_km'] !== ''
                ? (float) $payload['min_distance_km'] : null;
            $max = isset($payload['max_distance_km']) && $payload['max_distance_km'] !== ''
                ? (float) $payload['max_distance_km'] : null;
            $others = DeliveryZoneRepository::zonesForOverlapCheck($excludeId);
            $errors = array_merge($errors, self::validateBand((int) ($excludeId ?? 0), $min, $max, $others));
        }

        if ($kind === 'pincode' || ($kind === 'hybrid' && !empty($payload['pincode_required']))) {
            $val = trim((string) ($payload['match_value'] ?? ''));
            if ($val === '') {
                $errors[] = 'Pincode prefix is required for pincode / hybrid zones with pin restriction.';
            }
        }

        $store = DeliveryConfig::store();
        if ((string) ($store['overlap_policy'] ?? 'reject') !== 'reject' && in_array($kind, ['distance_band', 'hybrid'], true)) {
            $errors[] = 'Only overlap policy “reject” is supported.';
        }

        return $errors;
    }
}

final class DeliveryZoneResolver
{
    public static function zoneResolutionMode(): string
    {
        $mode = (string) (AppSettings::get('delivery.zone_resolution_mode') ?? '');

        return $mode !== '' ? $mode : 'pincode_legacy';
    }

    /**
     * Resolve serviceability for hybrid/distance mode. Never uses pincode centroid as customer location.
     *
     * @return array{
     *   pin: string,
     *   serviceable: bool,
     *   zone: array<string, mixed>|null,
     *   message: string,
     *   distance_km: float|null,
     *   distance_source: string|null,
     *   requires_map_pin: bool,
     *   message_detail?: string
     * }
     */
    public static function resolve(string $pin, ?float $customerLat, ?float $customerLon): array
    {
        $base = [
            'pin' => $pin,
            'serviceable' => false,
            'zone' => null,
            'message' => 'Sorry — we do not deliver to this pincode yet.',
            'distance_km' => null,
            'distance_source' => null,
            'requires_map_pin' => false,
        ];

        if (self::zoneResolutionMode() === 'pincode_legacy') {
            $legacy = DeliveryService::evaluatePincodeLegacy($pin);
            $base['serviceable'] = $legacy['serviceable'];
            $base['zone'] = $legacy['zone'];
            $base['message'] = $legacy['message'];
            $base['distance_km'] = $legacy['distance_km'];

            return $base;
        }

        $distance = null;
        $distanceSource = null;
        $hasCoords = $customerLat !== null && $customerLon !== null
            && abs($customerLat) <= 90 && abs($customerLon) <= 180;
        if ($hasCoords) {
            $d = DeliveryDistanceService::distanceFromStore($customerLat, $customerLon);
            if ($d !== null) {
                $distance = round($d['km'], 2);
                $distanceSource = (string) $d['source'];
                $base['distance_km'] = $distance;
                $base['distance_source'] = $distanceSource;
            }
        }

        $candidates = [];
        foreach (DeliveryZoneRepository::activeZones() as $zone) {
            if (!self::zoneMatches($zone, $pin, $distance, $hasCoords)) {
                continue;
            }
            $candidates[] = $zone;
        }

        if ($candidates === []) {
            if (!$hasCoords && self::anyActiveDistanceBand()) {
                $base['requires_map_pin'] = true;
                $copy = DeliveryCustomerMessages::mapPinRequired();
                $base['message'] = $copy['message'];
                $base['message_detail'] = $copy['message_detail'];

                return $base;
            }

            return $base;
        }

        usort($candidates, static function (array $a, array $b): int {
            $so = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
            if ($so !== 0) {
                return $so;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $zone = $candidates[0];
        $base['serviceable'] = true;
        $base['zone'] = $zone;
        $base['message'] = 'Good news — we deliver to your address (' . (string) $zone['name'] . ').';
        if ($distance !== null) {
            $base['message'] .= ' About ' . $distance . ' km from our store (straight-line; not road distance).';
        }

        return $base;
    }

    /** @param array<string, mixed> $zone */
    private static function zoneMatches(array $zone, string $pin, ?float $distanceKm, bool $hasCoords): bool
    {
        $kind = (string) ($zone['zone_kind'] ?? 'pincode');
        if ($kind === 'pincode') {
            return self::pincodePrefixMatches($zone, $pin);
        }

        if ($kind === 'distance_band') {
            if (!$hasCoords || $distanceKm === null) {
                return false;
            }

            return self::distanceBandMatches($zone, $distanceKm);
        }

        if ($kind === 'hybrid') {
            if (!self::pincodePrefixMatches($zone, $pin)) {
                return false;
            }
            if (!$hasCoords || $distanceKm === null) {
                return false;
            }

            return self::distanceBandMatches($zone, $distanceKm);
        }

        return self::pincodePrefixMatches($zone, $pin);
    }

    /** @param array<string, mixed> $zone */
    private static function pincodePrefixMatches(array $zone, string $pin): bool
    {
        $type = (string) ($zone['match_type'] ?? '');
        $val = (string) ($zone['match_value'] ?? '');
        if ($type !== 'pincode_prefix' || $val === '') {
            return false;
        }

        return strpos($pin, $val) === 0;
    }

    /** @param array<string, mixed> $zone */
    private static function distanceBandMatches(array $zone, float $distanceKm): bool
    {
        if (!isset($zone['min_distance_km'], $zone['max_distance_km'])) {
            return false;
        }
        $min = (float) $zone['min_distance_km'];
        $max = (float) $zone['max_distance_km'];

        return $distanceKm >= $min && $distanceKm < $max;
    }

    private static function anyActiveDistanceBand(): bool
    {
        foreach (DeliveryZoneRepository::activeZones() as $zone) {
            $kind = (string) ($zone['zone_kind'] ?? 'pincode');
            if (in_array($kind, ['distance_band', 'hybrid'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin test tool: same as resolve but includes debug candidate count.
     *
     * @return array<string, mixed>
     */
    public static function resolveForAdminTest(string $pin, ?float $lat, ?float $lon): array
    {
        $r = self::resolve($pin, $lat, $lon);
        $r['mode'] = self::zoneResolutionMode();

        return $r;
    }
}

final class DeliveryZoneAdminService
{
    /** @return list<array<string, mixed>> */
    public static function listAll(): array
    {
        return DeliveryZoneRepository::allZones();
    }

    /** @param array<string, mixed> $post */
    public static function saveFromPost(array $post, ?int $id = null): array
    {
        $payload = self::normalizePayload($post);
        $errors = DeliveryZoneOverlapValidator::validateSavePayload($payload, $id);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ($id === null) {
            DeliveryZoneRepository::insert($payload);
        } else {
            DeliveryZoneRepository::update($id, $payload);
        }
        DeliveryConfig::resetCache();

        return ['ok' => true, 'errors' => []];
    }

    public static function setActive(int $id, bool $active): void
    {
        $zone = DeliveryZoneRepository::find($id);
        if ($zone === null) {
            return;
        }
        if ($active) {
            $payload = $zone;
            $errors = DeliveryZoneOverlapValidator::validateSavePayload($payload, $id);
            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }
        }
        DeliveryZoneRepository::setActive($id, $active);
        DeliveryConfig::resetCache();
    }

    /** @return array<string, mixed> */
    private static function normalizePayload(array $post): array
    {
        $kind = (string) ($post['zone_kind'] ?? 'pincode');

        return [
            'name' => trim((string) ($post['name'] ?? '')),
            'zone_kind' => $kind,
            'match_type' => 'pincode_prefix',
            'match_value' => trim((string) ($post['match_value'] ?? '')),
            'min_distance_km' => in_array($kind, ['distance_band', 'hybrid'], true)
                ? ($post['min_distance_km'] ?? null) : null,
            'max_distance_km' => in_array($kind, ['distance_band', 'hybrid'], true)
                ? ($post['max_distance_km'] ?? null) : null,
            'flat_fee' => (float) ($post['flat_fee'] ?? 40),
            'free_above' => (float) ($post['free_above'] ?? 499),
            'max_order_weight_kg' => ($post['max_order_weight_kg'] ?? '') !== ''
                ? (float) $post['max_order_weight_kg'] : null,
            'eta_display' => trim((string) ($post['eta_display'] ?? '')) ?: null,
            'pincode_required' => !empty($post['pincode_required']) ? 1 : 0,
            'sort_order' => (int) ($post['sort_order'] ?? 0),
            'is_active' => !empty($post['is_active']) ? 1 : 0,
        ];
    }

    public static function countVariantsMissingWeight(): int
    {
        if (!Install::isSchemaReady()) {
            return 0;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                'SELECT COUNT(*) FROM product_variants WHERE weight_kg IS NULL'
            );

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
