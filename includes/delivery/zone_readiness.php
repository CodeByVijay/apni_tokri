<?php

declare(strict_types=1);

/**
 * Phase 5 — read-only readiness report + non-destructive legacy↔distance preview
 * for switching `delivery.zone_resolution_mode` from pincode_legacy to
 * distance_hybrid. This class never writes settings, zones, addresses or orders.
 */
final class DeliveryZoneReadiness
{
    /**
     * @return array{
     *   mode: string,
     *   store: array<string, mixed>,
     *   zones: list<array<string, mixed>>,
     *   integrity: array{errors: list<string>, warnings: list<string>, gaps: list<string>},
     *   coverage: array<string, int>,
     *   pending: array<string, int|string>,
     *   ready: bool
     * }
     */
    public static function report(): array
    {
        $zones = DeliveryZoneRepository::allZones();
        $store = DeliveryConfig::store();

        return [
            'mode' => DeliveryZoneResolver::zoneResolutionMode(),
            'store' => [
                'latitude' => $store['latitude'] ?? null,
                'longitude' => $store['longitude'] ?? null,
                'max_radius_km' => $store['max_radius_km'] ?? null,
            ],
            'zones' => $zones,
            'integrity' => self::bandIntegrity($zones, $store),
            'coverage' => self::addressCoverage(),
            'pending' => self::pendingWarnings(),
            'ready' => true,
        ];
    }

    /**
     * Validates active distance bands: malformed bounds, pairwise overlaps and
     * coverage gaps inside the configured store radius. Pure read-only analysis
     * of the rows already in delivery_zones — nothing is mutated.
     *
     * @param list<array<string, mixed>> $zones
     * @param array<string, mixed> $store
     * @return array{errors: list<string>, warnings: list<string>, gaps: list<string>}
     */
    public static function bandIntegrity(array $zones, array $store): array
    {
        $errors = [];
        $warnings = [];
        $gaps = [];

        $bands = [];
        $pincodeZones = 0;
        foreach ($zones as $z) {
            $kind = (string) ($z['zone_kind'] ?? 'pincode');
            if ($kind === 'pincode') {
                if (!empty($z['is_active'])) {
                    $pincodeZones++;
                }
                continue;
            }
            if (empty($z['is_active'])) {
                continue; // disabled zones can't match — no integrity impact
            }
            $min = isset($z['min_distance_km']) && $z['min_distance_km'] !== null && $z['min_distance_km'] !== ''
                ? (float) $z['min_distance_km'] : null;
            $max = isset($z['max_distance_km']) && $z['max_distance_km'] !== null && $z['max_distance_km'] !== ''
                ? (float) $z['max_distance_km'] : null;
            $name = (string) ($z['name'] ?? ('#' . (int) ($z['id'] ?? 0)));
            if ($min === null || $max === null) {
                $errors[] = 'Band "' . $name . '" is missing min/max km.';
                continue;
            }
            if ($min < 0 || $max <= 0 || $max <= $min) {
                $errors[] = 'Band "' . $name . '" has invalid bounds [' . $min . ', ' . $max . ') km (needs 0 ≤ min < max).';
                continue;
            }
            $bands[] = ['name' => $name, 'min' => $min, 'max' => $max, 'sort' => (int) ($z['sort_order'] ?? 0)];
        }

        usort($bands, static function (array $a, array $b): int {
            return $a['min'] <=> $b['min'];
        });
        for ($i = 0; $i < count($bands); $i++) {
            for ($j = $i + 1; $j < count($bands); $j++) {
                if (DeliveryZoneOverlapValidator::rangesOverlap($bands[$i]['min'], $bands[$i]['max'], $bands[$j]['min'], $bands[$j]['max'])) {
                    $errors[] = 'Bands "' . $bands[$i]['name'] . '" [' . $bands[$i]['min'] . ',' . $bands[$i]['max']
                        . ') and "' . $bands[$j]['name'] . '" [' . $bands[$j]['min'] . ',' . $bands[$j]['max'] . ') km overlap.';
                }
            }
        }

        // Coverage gaps: walk sorted bands, detect holes within [0, max_radius].
        $radius = isset($store['max_radius_km']) && is_numeric($store['max_radius_km']) ? (float) $store['max_radius_km'] : null;
        if ($bands === []) {
            $warnings[] = 'No active distance bands exist — distance_hybrid would make every address unserviceable.';
        } else {
            $cursor = 0.0;
            foreach ($bands as $b) {
                if ($b['min'] > $cursor + 0.0001) {
                    $gaps[] = 'No band covers ' . round($cursor, 2) . '–' . round(min($b['min'], $radius ?? $b['min']), 2) . ' km.';
                }
                $cursor = max($cursor, $b['max']);
            }
            if ($radius !== null && $cursor < $radius - 0.0001) {
                $gaps[] = 'No band covers ' . round($cursor, 2) . '–' . round($radius, 2) . ' km (up to the store radius).';
            }
            if ($radius === null) {
                $warnings[] = 'Store max radius is not configured; far-boundary behaviour depends only on band maxima.';
            }
        }
        if ($pincodeZones === 0 && $bands === []) {
            $errors[] = 'No usable zones at all.';
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'gaps' => $gaps];
    }

    /**
     * Coordinate readiness over non-archived customer addresses.
     * Never fabricates or mutates coordinates.
     *
     * @return array<string, int>
     */
    public static function addressCoverage(): array
    {
        $c = [
            'total_active' => 0,
            'with_valid_coords' => 0,
            'confirmed_coords' => 0,
            'missing_coords' => 0,
            'invalid_coords' => 0,
            'partial_coords' => 0,
        ];
        if (!Install::isSchemaReady()) {
            return $c;
        }
        try {
            Install::ensureConnection();
            $st = Database::pdo()->query(
                "SELECT latitude, longitude, location_confirmed_at FROM customer_addresses WHERE archived_at IS NULL"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $c['total_active']++;
                $lat = $row['latitude'];
                $lng = $row['longitude'];
                if ($lat === null && $lng === null) {
                    $c['missing_coords']++;
                    continue;
                }
                if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
                    $c['partial_coords']++;
                    continue;
                }
                $la = (float) $lat;
                $lo = (float) $lng;
                if (abs($la) > 90 || abs($lo) > 180 || ($la === 0.0 && $lo === 0.0)) {
                    $c['invalid_coords']++;
                    continue;
                }
                $c['with_valid_coords']++;
                if (!empty($row['location_confirmed_at'])) {
                    $c['confirmed_coords']++;
                }
            }
        } catch (Throwable $e) {
            // leave zeros
        }

        return $c;
    }

    /**
     * Orders/subscriptions that could be affected by an eligibility change.
     * Read-only counts only.
     *
     * @return array<string, int|string>
     */
    public static function pendingWarnings(): array
    {
        $out = [
            'orders_unpaid' => 0,
            'orders_in_fulfilment' => 0,
            'subscriptions_active' => 0,
            'note' => '',
        ];
        if (!Install::isSchemaReady()) {
            $out['note'] = 'Database not ready.';

            return $out;
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            $out['orders_unpaid'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM orders WHERE payment_status IN ('pending','unpaid','initiated','awaiting_payment')"
            )->fetchColumn();
            $out['orders_in_fulfilment'] = (int) $pdo->query(
                "SELECT COUNT(*) FROM orders WHERE status IN ('confirmed','processing','packed','out_for_delivery')"
            )->fetchColumn();
        } catch (Throwable $e) {
            $out['note'] = 'Order counts unavailable.';
        }
        try {
            $out['subscriptions_active'] = (int) Database::pdo()->query(
                "SELECT COUNT(*) FROM customer_subscriptions WHERE status IN ('active','paused')"
            )->fetchColumn();
        } catch (Throwable $e) {
            $out['subscriptions_active'] = 0;
            $out['note'] = trim((string) $out['note'] . ' Subscriptions table not queryable.');
        }

        return $out;
    }

    /**
     * Non-destructive preview: compares pincode_legacy vs distance_hybrid
     * resolution for saved addresses. Uses a mode override — no setting,
     * zone or address row is mutated.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, preview_mode: string}
     */
    public static function previewComparison(int $limit = 200): array
    {
        $rows = [];
        $summary = [
            'compared' => 0,
            'same' => 0,
            'still_serviceable_different_zone' => 0,
            'becomes_unserviceable' => 0,
            'newly_serviceable' => 0,
            'needs_location_confirm' => 0,
        ];
        if (!Install::isSchemaReady()) {
            return ['rows' => $rows, 'summary' => $summary, 'preview_mode' => 'distance_hybrid'];
        }
        try {
            Install::ensureConnection();
            $st = Database::pdo()->prepare(
                "SELECT a.id, a.pincode, a.latitude, a.longitude, a.location_confirmed_at, a.line1, a.city,
                        c.mobile
                 FROM customer_addresses a
                 JOIN customers c ON c.id = a.customer_id
                 WHERE a.archived_at IS NULL
                 ORDER BY a.id DESC LIMIT ?"
            );
            $st->bindValue(1, max(1, min(2000, $limit)), PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $addr) {
                $pin = preg_replace('/\D+/', '', (string) ($addr['pincode'] ?? '')) ?? '';
                $lat = isset($addr['latitude']) && is_numeric($addr['latitude']) ? (float) $addr['latitude'] : null;
                $lng = isset($addr['longitude']) && is_numeric($addr['longitude']) ? (float) $addr['longitude'] : null;
                $hasCoords = $lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180;

                $legacy = DeliveryService::evaluatePincodeLegacy($pin);
                $hybrid = DeliveryZoneResolver::resolve($pin, $lat, $lng, 'distance_hybrid');

                $legacyOk = !empty($legacy['serviceable']);
                $hybridOk = !empty($hybrid['serviceable']);
                if (!$hasCoords) {
                    $class = 'needs_location_confirm';
                } elseif ($legacyOk && $hybridOk) {
                    $lz = (int) ($legacy['zone']['id'] ?? 0);
                    $hz = (int) ($hybrid['zone']['id'] ?? 0);
                    $class = $lz === $hz ? 'same' : 'still_serviceable_different_zone';
                } elseif ($legacyOk && !$hybridOk) {
                    $class = 'becomes_unserviceable';
                } elseif (!$legacyOk && $hybridOk) {
                    $class = 'newly_serviceable';
                } else {
                    $class = 'same';
                }
                $summary['compared']++;
                $summary[$class]++;
                $rows[] = [
                    'address_id' => (int) $addr['id'],
                    'pincode' => $pin,
                    'city' => (string) ($addr['city'] ?? ''),
                    'has_coords' => $hasCoords,
                    'confirmed' => !empty($addr['location_confirmed_at']),
                    'legacy_serviceable' => $legacyOk,
                    'legacy_zone' => $legacy['zone']['name'] ?? null,
                    'hybrid_serviceable' => $hybridOk,
                    'hybrid_zone' => $hybrid['zone']['name'] ?? null,
                    'distance_km' => $hybrid['distance_km'] ?? null,
                    'requires_map_pin' => !empty($hybrid['requires_map_pin']),
                    'class' => $class,
                ];
            }
        } catch (Throwable $e) {
            $summary['compared'] = -1;
        }

        return ['rows' => $rows, 'summary' => $summary, 'preview_mode' => 'distance_hybrid'];
    }
}
