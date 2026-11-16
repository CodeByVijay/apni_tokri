<?php

declare(strict_types=1);

/**
 * Presentation-only helpers for the admin Delivery Settings pages (plain-language labels,
 * explanations, input pre-validation and a read-only test calculator).
 *
 * This class never changes delivery rules: matching, fees, priority and overlap checks stay in
 * DeliveryZoneResolver / DeliveryQuoteService / DeliveryZoneOverlapValidator.
 */
final class DeliveryAdminUi
{
    /** Stored zone_kind value => admin wording. */
    public const KIND_LABELS = [
        'pincode' => 'By pincode',
        'distance_band' => 'By distance from our store',
        'hybrid' => 'By pincode and distance together',
    ];

    /** Stored delivery.zone_resolution_mode value => admin wording. */
    public const MODE_LABELS = [
        'pincode_legacy' => 'Pincode-based delivery',
        'distance_hybrid' => 'Distance-based delivery',
    ];

    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? self::KIND_LABELS['pincode'];
    }

    public static function modeLabel(string $mode): string
    {
        return self::MODE_LABELS[$mode] ?? self::MODE_LABELS['pincode_legacy'];
    }

    public static function inr($amount): string
    {
        $n = (float) $amount;
        $s = abs($n - round($n)) < 0.005 ? number_format($n, 0) : number_format($n, 2);

        return '₹' . $s;
    }

    public static function km($value): string
    {
        $s = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return ($s === '' ? '0' : $s) . ' km';
    }

    /** MySQL TIME or HH:MM → 12-hour clock (e.g. 8:30 AM). */
    public static function formatClockTime12h(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '—';
        }
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            $time .= ':00';
        }
        $ts = strtotime('1970-01-01 ' . $time);
        if ($ts === false) {
            return $time;
        }

        return date('g:i A', $ts);
    }

    /** "Pincodes starting with 4520" / "0 km up to 5 km" for a zone row. */
    public static function coverage(array $zone): string
    {
        $kind = (string) ($zone['zone_kind'] ?? 'pincode');
        $pin = trim((string) ($zone['match_value'] ?? ''));
        $hasBand = isset($zone['min_distance_km'], $zone['max_distance_km'])
            && $zone['min_distance_km'] !== '' && $zone['max_distance_km'] !== '';
        $band = $hasBand
            ? self::km($zone['min_distance_km']) . ' up to ' . self::km($zone['max_distance_km'])
            : '';

        if ($kind === 'distance_band') {
            return $band !== '' ? $band : '—';
        }
        if ($kind === 'hybrid') {
            return trim(($pin !== '' ? 'Pincodes starting with ' . $pin : 'Any pincode') . ($band !== '' ? ', ' . $band : ''));
        }

        return $pin !== '' ? 'Pincodes starting with ' . $pin : '—';
    }

    /** "₹40 charge, free on orders of ₹499 or more" (+ "min order ₹149" when configured) */
    public static function chargeSummary(array $zone): string
    {
        $s = self::inr($zone['flat_fee'] ?? 0) . ' charge, free on orders of '
            . self::inr($zone['free_above'] ?? 0) . ' or more';
        $min = isset($zone['minimum_order_amount']) && $zone['minimum_order_amount'] !== null
            && $zone['minimum_order_amount'] !== '' ? (float) $zone['minimum_order_amount'] : 0.0;
        if ($min > 0) {
            $s .= '; min order ' . self::inr($min);
        }

        return $s;
    }

    /**
     * Plain-language explanation built from the real saved configuration.
     *
     * @param list<array<string, mixed>> $zones all zones
     * @param array<string, mixed> $store delivery_store row
     * @return list<string>
     */
    public static function explanationLines(string $mode, array $zones, array $store): array
    {
        $active = [];
        foreach ($zones as $z) {
            if (!empty($z['is_active'])) {
                $active[] = $z;
            }
        }
        usort($active, static function (array $a, array $b): int {
            return [(int) ($a['sort_order'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['sort_order'] ?? 0), (int) ($b['id'] ?? 0)];
        });

        $lines = [];
        $isDistance = $mode === 'distance_hybrid';
        $lines[] = $isDistance
            ? 'Each customer address is matched to an active delivery area by pincode and/or its distance from your store (using the map location the customer selects).'
            : 'Each customer address is matched to an active delivery area by its pincode.';

        if ($active === []) {
            $lines[] = 'No delivery area is enabled yet, so delivery is currently unavailable until you add one.';
        } else {
            $shown = 0;
            foreach ($active as $z) {
                if ($shown >= 4) {
                    $lines[] = 'And ' . (count($active) - $shown) . ' more enabled delivery area(s) — see the list on the Delivery zones page.';
                    break;
                }
                $kind = (string) ($z['zone_kind'] ?? 'pincode');
                if (!$isDistance && $kind !== 'pincode') {
                    continue; // distance areas are not used in pincode-based delivery
                }
                $name = (string) ($z['name'] ?? 'Delivery area');
                $lines[] = $name . ' (' . self::coverage($z) . '): order below ' . self::inr($z['free_above'] ?? 0)
                    . ' → ' . self::inr($z['flat_fee'] ?? 0) . ' delivery charge; order '
                    . self::inr($z['free_above'] ?? 0) . ' or above → free delivery.';
                $shown++;
            }
            if ($shown === 0) {
                $lines[] = 'None of the enabled delivery areas can be used in pincode-based delivery (they are distance-based). Delivery would be unavailable except for nearby addresses described below.';
            }
        }

        if (!$isDistance) {
            $radius = (float) ($store['max_radius_km'] ?? 0);
            if ($radius > 0 && !empty($store['latitude']) && !empty($store['longitude'])) {
                $lines[] = 'If a pincode is not listed but the address is within ' . self::km($radius)
                    . ' of your store, delivery is still offered at the backup charge (see Advanced settings).';
            }
        } else {
            foreach ($active as $z) {
                if (in_array((string) ($z['zone_kind'] ?? 'pincode'), ['distance_band', 'hybrid'], true)) {
                    $lines[] = 'Distance-based areas need the customer\'s map location; without it delivery cannot be confirmed.';
                    break;
                }
            }
        }

        $lines[] = 'If the address does not match any enabled delivery area → Delivery unavailable.';

        return $lines;
    }

    /**
     * Friendly pre-validation for the zone form. Returns messages; empty = looks fine.
     * (Overlap and priority rules are still enforced by DeliveryZoneOverlapValidator on save.)
     *
     * @param array<string, mixed> $post
     * @return list<string>
     */
    public static function validateZoneInput(array $post): array
    {
        $errors = [];
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Please enter a Delivery Area Name, for example “Indore City”.';
        }
        $kind = (string) ($post['zone_kind'] ?? 'pincode');
        if (!isset(self::KIND_LABELS[$kind])) {
            $errors[] = 'Please choose how this delivery area is identified.';
            $kind = 'pincode';
        }

        $pin = trim((string) ($post['match_value'] ?? ''));
        if (in_array($kind, ['pincode', 'hybrid'], true) && $pin === '') {
            $errors[] = 'Please enter the Pincode Starting Digits, for example 4520.';
        }
        if ($pin !== '' && in_array($kind, ['pincode', 'hybrid'], true) && !preg_match('/^\d{1,6}$/', $pin)) {
            $errors[] = 'Pincode Starting Digits must be 1 to 6 digits only, for example 4520.';
        }

        if (in_array($kind, ['distance_band', 'hybrid'], true)) {
            $minRaw = trim((string) ($post['min_distance_km'] ?? ''));
            $maxRaw = trim((string) ($post['max_distance_km'] ?? ''));
            if ($minRaw === '' || $maxRaw === '' || !is_numeric($minRaw) || !is_numeric($maxRaw)) {
                $errors[] = 'Please enter both a Minimum Distance and a Maximum Distance (in km), for example 0 and 5.';
            } elseif ((float) $minRaw < 0 || (float) $maxRaw <= (float) $minRaw) {
                $errors[] = 'Maximum Distance must be greater than Minimum Distance (and Minimum Distance cannot be below 0).';
            }
        }

        foreach (['flat_fee' => 'Delivery Charge', 'free_above' => 'Free Delivery on Orders Above'] as $field => $label) {
            $v = trim((string) ($post[$field] ?? ''));
            if ($v === '' || !is_numeric($v) || (float) $v < 0) {
                $errors[] = $label . ' must be an amount of 0 or more.';
            }
        }
        $minOrder = trim((string) ($post['minimum_order_amount'] ?? ''));
        if ($minOrder !== '' && (!is_numeric($minOrder) || (float) $minOrder <= 0)) {
            $errors[] = 'Minimum Order Amount must be a number above 0, or left empty to use the global marketing minimum.';
        }
        $w = trim((string) ($post['max_order_weight_kg'] ?? ''));
        if ($w !== '' && (!is_numeric($w) || (float) $w <= 0)) {
            $errors[] = 'Maximum Order Weight must be a number above 0, or left empty for no limit.';
        }

        return $errors;
    }

    /** Translate engine validation wording into plain language (engine rules unchanged). */
    public static function friendlyError(string $message): string
    {
        $map = [
            'Distance band requires both min and max km.' => 'Please enter both a Minimum Distance and a Maximum Distance (in km).',
            'Distance band must have 0 ≤ min < max (max is exclusive).' => 'Maximum Distance must be greater than Minimum Distance (and Minimum Distance cannot be below 0).',
            'Pincode prefix is required for pincode / hybrid zones with pin restriction.' => 'Please enter the Pincode Starting Digits for this delivery area.',
            'Only overlap policy “reject” is supported.' => 'Overlapping delivery areas are not allowed.',
        ];
        if (isset($map[$message])) {
            return $map[$message];
        }
        if (preg_match('/^Overlaps active band “(.*)” \[([^,]+), ([^)]+)\) km\.$/u', $message, $m)) {
            return 'This distance range overlaps the enabled delivery area “' . $m[1] . '” (' . self::km($m[2]) . ' up to ' . self::km($m[3])
                . '). Change the distances so the two areas do not share any distance, or disable the other area first.';
        }

        return $message;
    }

    /**
     * Read-only test calculator. Uses the real resolver and the real fee function.
     *
     * @return array<string, mixed>
     */
    public static function testCalculation(string $pinRaw, ?string $latRaw, ?string $lonRaw, ?string $amountRaw): array
    {
        $out = ['ok' => false, 'errors' => [], 'notes' => []];
        $pin = preg_replace('/\D+/', '', $pinRaw) ?? '';
        if (!preg_match('/^\d{6}$/', $pin)) {
            $out['errors'][] = 'Enter a valid 6-digit pincode to run the test.';

            return $out;
        }

        $lat = null;
        $lon = null;
        $latRaw = trim((string) $latRaw);
        $lonRaw = trim((string) $lonRaw);
        if ($latRaw !== '' || $lonRaw !== '') {
            if (!is_numeric($latRaw) || !is_numeric($lonRaw) || abs((float) $latRaw) > 90 || abs((float) $lonRaw) > 180) {
                $out['errors'][] = 'Map location must have a latitude between -90 and 90 and a longitude between -180 and 180. Leave both empty to test without a map location.';

                return $out;
            }
            $lat = (float) $latRaw;
            $lon = (float) $lonRaw;
        }

        $amount = null;
        $amountRaw = trim((string) $amountRaw);
        if ($amountRaw !== '') {
            if (!is_numeric($amountRaw) || (float) $amountRaw < 0) {
                $out['errors'][] = 'Order amount must be a number of 0 or more.';

                return $out;
            }
            $amount = (float) $amountRaw;
        }

        $mode = DeliveryZoneResolver::zoneResolutionMode();
        $store = DeliveryConfig::store();
        $r = DeliveryZoneResolver::resolveForAdminTest($pin, $lat, $lon);
        $zone = is_array($r['zone'] ?? null) ? $r['zone'] : null;

        $out['ok'] = true;
        $out['pin'] = $pin;
        $out['mode'] = $mode;
        $out['mode_label'] = self::modeLabel($mode);
        $out['serviceable'] = !empty($r['serviceable']) && $zone !== null;
        $out['message'] = (string) ($r['message'] ?? '');
        $out['distance_km'] = $r['distance_km'] ?? null;
        $out['raw'] = $r;
        $out['needs_map'] = !empty($r['requires_map_pin']);

        if ($mode === 'pincode_legacy' && $lat !== null) {
            $out['notes'][] = 'Pincode-based delivery is on, so the map location was not used for matching.';
        }
        if (!empty($store['require_coordinates_for_checkout']) && $lat === null) {
            $out['notes'][] = 'Your settings require customers to share a map location at checkout, so a real checkout without one would be stopped.';
        }
        if ($out['needs_map']) {
            $out['notes'][] = 'This pincode needs a map location to be matched to a distance-based area. Enter a latitude and longitude and test again.';
        }

        if (!$out['serviceable']) {
            $out['method'] = '—';

            return $out;
        }

        $zoneId = (int) ($zone['id'] ?? 0);
        $kind = (string) ($zone['zone_kind'] ?? 'pincode');
        if ($mode === 'pincode_legacy' && $r['distance_km'] !== null) {
            $out['method'] = 'Nearby-area backup (address is close enough to your store)';
        } elseif ($kind === 'distance_band') {
            $out['method'] = 'Distance from your store (straight-line)';
        } elseif ($kind === 'hybrid') {
            $out['method'] = 'Pincode and distance together (straight-line)';
        } else {
            $out['method'] = 'Pincode match';
        }
        $out['zone_name'] = (string) ($zone['name'] ?? 'Delivery area');
        $out['zone_active_note'] = $zoneId > 0 ? '' : 'Built-in backup area';
        $out['flat_fee'] = (float) ($zone['flat_fee'] ?? 40);
        $out['free_above'] = (float) ($zone['free_above'] ?? 499);
        $out['eta'] = (string) ($zone['eta_display'] ?? '');
        $out['max_weight'] = isset($zone['max_order_weight_kg']) && $zone['max_order_weight_kg'] !== null && $zone['max_order_weight_kg'] !== ''
            ? (float) $zone['max_order_weight_kg'] : null;
        $out['min_order_zone'] = MarketingSettings::zoneMinimumOrderAmount($zone);
        $out['min_order_effective'] = $out['min_order_zone'] ?? MarketingSettings::minOrderAmountInr();

        $feeFor = static function (float $amt) use ($zone): float {
            $fee = DeliveryQuoteService::deliveryFeeForZone(
                $zone,
                [['unit_price' => $amt, 'qty' => 1, 'grants_free_delivery' => false]],
                $amt
            );

            return (float) $fee['fee'];
        };
        if ($amount !== null) {
            $out['amount'] = $amount;
            $out['fee_for_amount'] = $feeFor($amount);
        }
        $out['fee_below'] = $feeFor(max(0.0, $out['free_above'] - 1));
        $out['fee_at_threshold'] = $feeFor($out['free_above']);

        return $out;
    }
}
