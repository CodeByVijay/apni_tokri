<?php

declare(strict_types=1);

/**
 * Pin check + address validation aligned with distance_hybrid zones (coords required when only bands).
 * Run: php scripts/run-in-disposable-db.php scripts/test-delivery-pin-check-hybrid.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

Install::ensureConnection();
$pdo = Database::pdo();
$prevMode = AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy');

$store = DeliveryConfig::store();
$storeLat = (float) ($store['latitude'] ?? 0);
$storeLon = (float) ($store['longitude'] ?? 0);
if ($storeLat === 0.0 && $storeLon === 0.0) {
    fwrite(STDERR, "Delivery store coordinates required for distance tests.\n");
    exit(1);
}

// Throw-away distance-only config (no hardcoded production pincodes).
$bandIds = [];
$ins = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
);
foreach ([[0, 3, 40, 499, 1], [3, 5, 50, 599, 2], [5, 8, 60, 699, 3], [8, 12, 70, 799, 4]] as [$min, $max, $fee, $free, $sort]) {
    $ins->execute(["TPC band {$min}-{$max}", 'pincode_prefix', '', 'distance_band', $min, $max, $fee, $free, $sort]);
    $bandIds[] = (int) $pdo->lastInsertId();
}

AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
DeliveryConfig::resetCache();

/** Offset km north-east using rough deg conversion at Indore latitude. */
$atKm = static function (float $kmN, float $kmE) use ($storeLat, $storeLon): array {
    $lat = $storeLat + ($kmN / 111.0);
    $lon = $storeLon + ($kmE / (111.0 * cos(deg2rad($storeLat))));

    return [$lat, $lon];
};

$pinInside = '482001';
$pinOutside = '110001';

$noCoords = DeliveryZoneResolver::resolve($pinInside, null, null);
ok(!$noCoords['serviceable'] && !empty($noCoords['requires_map_pin']), 'pin-only: not serviceable, requires map pin');

$copy = DeliveryCustomerMessages::forPinCheckResponse($noCoords);
ok(
    !str_contains($copy['message'], 'Delivery not available at this location'),
    'pin-only: customer copy is not generic unavailable'
);
ok(str_contains($copy['message'], 'exact delivery location'), 'pin-only: asks for delivery location');
ok($copy['message_detail'] !== null && $copy['message_detail'] !== '', 'pin-only: includes detail');

[$lat2, $lon2] = $atKm(1.5, 1.0);
$inBand0 = DeliveryZoneResolver::resolve($pinInside, $lat2, $lon2);
ok($inBand0['serviceable'] && (float) ($inBand0['zone']['min_distance_km'] ?? -1) === 0.0, '~2 km: matches 0–3 km band');

[$lat4, $lon4] = $atKm(3.2, 2.5);
$inBand1 = DeliveryZoneResolver::resolve($pinInside, $lat4, $lon4);
ok($inBand1['serviceable'] && (float) ($inBand1['zone']['min_distance_km'] ?? -1) === 3.0, '~4 km: matches 3–5 km band');

[$lat6, $lon6] = $atKm(5.5, 3.0);
$inBand2 = DeliveryZoneResolver::resolve($pinInside, $lat6, $lon6);
ok($inBand2['serviceable'] && (float) ($inBand2['zone']['min_distance_km'] ?? -1) === 5.0, '~6 km: matches 5–8 km band');

[$lat10, $lon10] = $atKm(8.5, 4.0);
$inBand3 = DeliveryZoneResolver::resolve($pinInside, $lat10, $lon10);
ok($inBand3['serviceable'] && (float) ($inBand3['zone']['min_distance_km'] ?? -1) === 8.0, '~9 km: matches 8–12 km band');

[$latFar, $lonFar] = $atKm(15.0, 8.0);
$outside = DeliveryZoneResolver::resolve($pinOutside, $latFar, $lonFar);
ok(!$outside['serviceable'] && empty($outside['requires_map_pin']), 'outside bands: not serviceable, no map prompt');
ok(str_contains((string) $outside['message'], 'km delivery radius'), 'outside bands: radius message when beyond max_radius');

$v = AddressInput::validate([
    'label_type' => 'Home',
    'house_number' => '1',
    'building_name' => 'Test',
    'street' => 'Road',
    'area' => 'Area',
    'pincode' => $pinInside,
    'receiver_name' => 'Test User',
    'receiver_phone' => '9876543210',
    'latitude' => (string) $lat2,
    'longitude' => (string) $lon2,
    'location_confirmed' => '1',
    'address_confirmed' => '1',
]);
ok($v['errors'] === [], 'address validation: serviceable when pin + coords provided');
ok(!isset($v['errors']['pincode']), 'address validation: pincode not rejected when coords match a band');

$vNoPin = AddressInput::validate([
    'label_type' => 'Home',
    'house_number' => '1',
    'building_name' => 'Test',
    'street' => 'Road',
    'area' => 'Area',
    'pincode' => $pinInside,
    'receiver_name' => 'Test User',
    'receiver_phone' => '9876543210',
    'latitude' => '',
    'longitude' => '',
    'address_confirmed' => '1',
]);
ok(!isset($vNoPin['errors']['pincode']), 'address validation: pin not rejected when only map pin is missing (distance mode)');

$quotePin = DeliveryQuoteService::deliveryFeeForZone($inBand0['zone'], [['qty' => 1, 'unit_price' => 100, 'grants_free_delivery' => false]], 100.0);
ok((float) $quotePin['fee'] === 40.0, 'fee: 0–3 km band flat_fee applied');

AppSettings::set('delivery.zone_resolution_mode', $prevMode ?? 'pincode_legacy');
foreach ($bandIds as $id) {
    $pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$id]);
}
DeliveryConfig::resetCache();

if ($fail > 0) {
    echo "\n{$fail} failure(s).\n";
    exit(1);
}

echo "\nPASS: delivery pin check + hybrid band matching.\n";
