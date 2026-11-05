<?php

declare(strict_types=1);

final class DeliveryService
{
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
    public static function evaluatePincode(string $pin, ?float $customerLat = null, ?float $customerLon = null): array
    {
        if (Install::isSchemaReady()
            && (AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy') ?? 'pincode_legacy') === 'distance_hybrid') {
            $r = DeliveryZoneResolver::resolve($pin, $customerLat, $customerLon);
            unset($r['distance_source']);

            return $r;
        }

        return self::evaluatePincodeLegacy($pin);
    }

    /**
     * Pincode-first serviceability (Stage 8 legacy). Radius fallback uses approximate pincode centroid — not customer GPS.
     *
     * @return array{pin: string, serviceable: bool, zone: array<string, mixed>|null, message: string, distance_km: float|null}
     */
    public static function evaluatePincodeLegacy(string $pin): array
    {
        $base = [
            'pin' => $pin,
            'serviceable' => false,
            'zone' => null,
            'message' => 'Sorry — we do not deliver to this pincode yet.',
            'distance_km' => null,
        ];

        if (Install::isSchemaReady()) {
            $zone = self::matchZoneFromDb($pin);
            if ($zone !== null) {
                $base['serviceable'] = true;
                $base['zone'] = $zone;
                $base['message'] = 'Good news — we deliver to ' . $pin . ' (' . $zone['name'] . ').';

                return $base;
            }
            $store = DeliveryConfig::store();
            if (!empty($store['latitude']) && !empty($store['longitude'])) {
                $centre = self::pincodeApproxCoords($pin);
                if ($centre !== null) {
                    $km = self::haversineKm(
                        (float) $store['latitude'],
                        (float) $store['longitude'],
                        $centre[0],
                        $centre[1]
                    );
                    $base['distance_km'] = round($km, 1);
                    $max = (float) ($store['max_radius_km'] ?? 12);
                    if ($km <= $max) {
                        $base['serviceable'] = true;
                        $base['zone'] = self::syntheticRadiusZone($store);
                        $base['message'] = 'We deliver to your area (about ' . round($km, 1) . ' km from our store).';

                        return $base;
                    }
                    $base['message'] = 'This address is outside our ' . $max . ' km delivery radius.';
                }
            }

            return $base;
        }

        foreach (['4520', '4530'] as $prefix) {
            if (strpos($pin, $prefix) === 0) {
                $base['serviceable'] = true;
                $base['zone'] = ['id' => 0, 'name' => 'Indore', 'flat_fee' => 40.0, 'free_above' => 499.0];
                $base['message'] = 'Good news — we deliver to ' . $pin . ' (Indore).';

                return $base;
            }
        }

        return $base;
    }

    /** @param array<string, mixed> $store */
    private static function syntheticRadiusZone(array $store): array
    {
        $fallbackId = (int) ($store['fallback_radius_zone_id'] ?? 0);
        if ($fallbackId > 0) {
            $row = DeliveryZoneRepository::find($fallbackId);
            if ($row !== null && !empty($row['is_active'])) {
                return $row;
            }
        }

        return [
            'id' => 0,
            'name' => 'Radius zone',
            'flat_fee' => 40.0,
            'free_above' => 499.0,
            'match_type' => 'radius',
        ];
    }

    public static function isPincodeServiceable(string $pincode): bool
    {
        $pin = preg_replace('/\D+/', '', $pincode) ?? '';

        return self::evaluatePincode($pin)['serviceable'];
    }

    /**
     * Mixed-cart rule: free-delivery threshold uses only lines where product grants_free_delivery = 0.
     *
     * @param list<array<string, mixed>> $cartLines with keys qty, unit_price, grants_free_delivery (bool)
     * @return array{fee: float, threshold: float, eligible_subtotal: float, free_delivery_eligible: bool, zone: array<string, mixed>|null}
     */
    public static function computeDeliveryFee(array $cartLines, float $subtotalAfterCoupon, ?string $pincode): array
    {
        $eval = self::evaluatePincode(preg_replace('/\D+/', '', (string) $pincode) ?? '000000');
        if (!$eval['serviceable'] || $eval['zone'] === null) {
            return [
                'fee' => 0.0,
                'threshold' => 0.0,
                'eligible_subtotal' => 0.0,
                'free_delivery_eligible' => false,
                'zone' => null,
                'serviceable' => false,
            ];
        }
        $zone = $eval['zone'];
        $flat = (float) ($zone['flat_fee'] ?? 40);
        $threshold = (float) ($zone['free_above'] ?? 499);

        $eligibleSubtotal = 0.0;
        $allGrantFree = $cartLines !== [];
        foreach ($cartLines as $line) {
            $grants = !empty($line['grants_free_delivery']);
            if (!$grants) {
                $allGrantFree = false;
                $eligibleSubtotal += (float) ($line['unit_price'] ?? 0) * (int) ($line['qty'] ?? 0);
            }
        }
        if ($cartLines === []) {
            $allGrantFree = false;
        }

        if ($allGrantFree) {
            return [
                'fee' => 0.0,
                'threshold' => $threshold,
                'eligible_subtotal' => 0.0,
                'free_delivery_eligible' => true,
                'zone' => $zone,
                'serviceable' => true,
            ];
        }

        $fee = ($eligibleSubtotal >= $threshold) ? 0.0 : $flat;

        return [
            'fee' => round($fee, 2),
            'threshold' => $threshold,
            'eligible_subtotal' => round($eligibleSubtotal, 2),
            'free_delivery_eligible' => true,
            'zone' => $zone,
            'serviceable' => true,
        ];
    }

    /** @deprecated use computeDeliveryFee */
    public static function deliveryFeeForSubtotal(float $subtotalAfterDiscount): float
    {
        $pin = DeliveryContext::sessionPin() ?? '452001';
        $r = self::computeDeliveryFee(
            [['qty' => 1, 'unit_price' => $subtotalAfterDiscount, 'grants_free_delivery' => false]],
            $subtotalAfterDiscount,
            $pin
        );

        return (float) $r['fee'];
    }

    public static function freeDeliveryThreshold(): float
    {
        $pin = DeliveryContext::sessionPin() ?? '452001';
        $eval = self::evaluatePincode($pin);

        return (float) ($eval['zone']['free_above'] ?? 499.0);
    }

    public static function standardDeliveryFee(): float
    {
        $pin = DeliveryContext::sessionPin() ?? '452001';
        $eval = self::evaluatePincode($pin);

        return (float) ($eval['zone']['flat_fee'] ?? 40.0);
    }

    public static function validateSlot(string $slug): bool
    {
        if (!DeliveryConfig::slotsEnabled()) {
            return $slug === 'auto' || $slug === '';
        }

        return DeliverySlotService::resolveBySlug($slug) !== null
            || in_array($slug, ['morning', 'afternoon', 'tomorrow'], true);
    }

    /** @return array<string, mixed>|null */
    private static function matchZoneFromDb(string $pin): ?array
    {
        foreach (DeliveryZoneRepository::activeZones() as $zone) {
            $kind = DeliveryZoneRepository::hasColumn('zone_kind')
                ? (string) ($zone['zone_kind'] ?? 'pincode')
                : 'pincode';
            if ($kind !== 'pincode' && $kind !== '') {
                continue;
            }
            $type = (string) ($zone['match_type'] ?? '');
            $val = (string) ($zone['match_value'] ?? '');
            if ($type === 'pincode_prefix' && $val !== '' && strpos($pin, $val) === 0) {
                return $zone;
            }
        }

        return null;
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Rough Indore pincode centroid for radius fallback (not geocoding API). */
    private static function pincodeApproxCoords(string $pin): ?array
    {
        if (strpos($pin, '452') === 0) {
            return [22.7196, 75.8577];
        }
        if (strpos($pin, '453') === 0) {
            return [22.75, 75.85];
        }

        return null;
    }
}
