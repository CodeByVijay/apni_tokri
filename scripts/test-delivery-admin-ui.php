<?php

declare(strict_types=1);

/**
 * Delivery settings admin UI helpers: labels, explanations, validation, test calculator.
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-delivery-admin-ui.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function okU(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$ins = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, eta_display, max_order_weight_kg, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
);
$pdo->exec('DELETE FROM delivery_zones');
DeliveryConfig::resetCache();
$ins->execute(['UI Pin Zone', 'pincode_prefix', '4520', 'pincode', null, null, 40, 499, 'Within 2 hours', 25, 1, 1]);
$ins->execute(['UI Near Band', 'pincode_prefix', '', 'distance_band', 0, 5, 30, 300, null, null, 2, 1]);
$ins->execute(['UI Far Band', 'pincode_prefix', '', 'distance_band', 5, 10, 60, 800, null, null, 3, 1]);
$ins->execute(['UI Off Zone', 'pincode_prefix', '7000', 'pincode', null, null, 99, 999, null, null, 4, 0]);
DeliveryConfig::resetCache();

echo "\n--- Labels ---\n";
okU(DeliveryAdminUi::modeLabel('pincode_legacy') === 'Pincode-based delivery', 'pincode_legacy label');
okU(DeliveryAdminUi::modeLabel('distance_hybrid') === 'Distance-based delivery', 'distance_hybrid label');
okU(DeliveryAdminUi::kindLabel('distance_band') === 'By distance from our store', 'distance_band label');
okU(DeliveryAdminUi::inr(499) === '₹499' && DeliveryAdminUi::inr(40.5) === '₹40.50', 'inr formatting');
okU(DeliveryAdminUi::km('5.00') === '5 km' && DeliveryAdminUi::km(2.5) === '2.5 km', 'km formatting');

echo "\n--- Pincode-based mode ---\n";
AppSettings::set('delivery.zone_resolution_mode', 'pincode_legacy');
$r = DeliveryAdminUi::testCalculation('452010', '', '', '');
okU($r['ok'] && $r['serviceable'], 'pincode: 452010 serviceable');
okU($r['zone_name'] === 'UI Pin Zone' && $r['method'] === 'Pincode match', 'pincode: zone + method');
okU($r['fee_below'] === 40.0 && $r['fee_at_threshold'] === 0.0, 'pincode: ₹40 below ₹499, free at ₹499');
$r = DeliveryAdminUi::testCalculation('452010', '', '', '498.99');
okU($r['fee_for_amount'] === 40.0, 'pincode: ₹498.99 pays ₹40');
$r = DeliveryAdminUi::testCalculation('452010', '', '', '499');
okU($r['fee_for_amount'] === 0.0, 'pincode: ₹499 free (threshold inclusive)');
$r = DeliveryAdminUi::testCalculation('452010', '', '', '1200');
okU($r['fee_for_amount'] === 0.0, 'pincode: ₹1200 free');
$r = DeliveryAdminUi::testCalculation('110001', '', '', '');
okU($r['ok'] && !$r['serviceable'], 'pincode: 110001 unavailable');
$r = DeliveryAdminUi::testCalculation('700012', '', '', '');
okU(!$r['serviceable'], 'pincode: inactive zone not used');
$r = DeliveryAdminUi::testCalculation('452010', '22.72', '75.86', '');
okU($r['serviceable'] && in_array('Pincode-based delivery is on, so the map location was not used for matching.', $r['notes'], true), 'pincode: map location ignored note');
$legacyDirect = DeliveryService::evaluatePincodeLegacy('452010');
$rr = DeliveryAdminUi::testCalculation('452010', '', '', '');
okU((int) $legacyDirect['zone']['id'] === (int) $rr['raw']['zone']['id'], 'calculator matches engine result');

echo "\n--- Distance-based mode ---\n";
AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
$store = DeliveryConfig::store();
$lat0 = (float) $store['latitude'];
$lon0 = (float) $store['longitude'];
// ~0.01 degree lat ≈ 1.1 km; 0.05 ≈ 5.5 km
$r = DeliveryAdminUi::testCalculation('110001', (string) ($lat0 + 0.02), (string) $lon0, '100');
okU($r['serviceable'] && $r['zone_name'] === 'UI Near Band', 'distance: ~2 km → Near band');
okU($r['method'] === 'Distance from your store (straight-line)', 'distance: method text');
okU($r['distance_km'] !== null && $r['distance_km'] > 1.5 && $r['distance_km'] < 3, 'distance: km computed');
okU($r['fee_for_amount'] === 30.0, 'distance: ₹100 pays ₹30');
$r = DeliveryAdminUi::testCalculation('110001', (string) ($lat0 + 0.07), (string) $lon0, '800');
okU($r['serviceable'] && $r['zone_name'] === 'UI Far Band' && $r['fee_for_amount'] === 0.0, 'distance: ~7.8 km → Far band, ₹800 free');
$r = DeliveryAdminUi::testCalculation('110001', (string) ($lat0 + 0.2), (string) $lon0, '');
okU(!$r['serviceable'], 'distance: ~22 km unavailable');
$r = DeliveryAdminUi::testCalculation('110001', '', '', '');
okU(!$r['serviceable'] && $r['needs_map'], 'distance: no map location asks for map');
$r = DeliveryAdminUi::testCalculation('452010', '', '', '');
okU($r['serviceable'] && $r['zone_name'] === 'UI Pin Zone', 'distance mode: pincode area still works without map');

echo "\n--- Calculator input validation ---\n";
okU(DeliveryAdminUi::testCalculation('123', '', '', '')['errors'] !== [], 'short pincode rejected');
okU(DeliveryAdminUi::testCalculation('452010', '95', '75', '')['errors'] !== [], 'latitude out of range rejected');
okU(DeliveryAdminUi::testCalculation('452010', '22', '', '')['errors'] !== [], 'one coordinate only rejected');
okU(DeliveryAdminUi::testCalculation('452010', '', '', '-5')['errors'] !== [], 'negative amount rejected');

echo "\n--- Zone form validation ---\n";
$base = ['name' => 'X', 'zone_kind' => 'pincode', 'match_value' => '4520', 'flat_fee' => '40', 'free_above' => '499'];
okU(DeliveryAdminUi::validateZoneInput($base) === [], 'valid pincode zone');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['name' => ''])) !== [], 'missing name');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['match_value' => '45ab'])) !== [], 'non-numeric pincode digits');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['match_value' => '1234567'])) !== [], '7 digit prefix rejected');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['match_value' => ''])) !== [], 'missing pincode digits');
$band = ['name' => 'B', 'zone_kind' => 'distance_band', 'min_distance_km' => '0', 'max_distance_km' => '5', 'flat_fee' => '30', 'free_above' => '300'];
okU(DeliveryAdminUi::validateZoneInput($band) === [], 'valid distance zone (no pincode needed)');
okU(DeliveryAdminUi::validateZoneInput(array_merge($band, ['max_distance_km' => '0'])) !== [], 'max must exceed min');
okU(DeliveryAdminUi::validateZoneInput(array_merge($band, ['min_distance_km' => ''])) !== [], 'missing min distance');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['flat_fee' => '-1'])) !== [], 'negative charge rejected');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['max_order_weight_kg' => '0'])) !== [], 'zero weight limit rejected');
okU(DeliveryAdminUi::validateZoneInput(array_merge($base, ['zone_kind' => 'bogus'])) !== [], 'unknown method rejected');

echo "\n--- Save path unchanged (engine overlap rules) ---\n";
$res = DeliveryZoneAdminService::saveFromPost(array_merge($band, ['name' => 'Overlap', 'min_distance_km' => '3', 'max_distance_km' => '8', 'is_active' => '1']), null);
okU(!$res['ok'], 'overlapping band still rejected by engine');
$friendly = DeliveryAdminUi::friendlyError($res['errors'][0]);
okU(strpos($friendly, 'overlaps the enabled delivery area') !== false && strpos($friendly, '[') === false, 'overlap message is plain language');
$res = DeliveryZoneAdminService::saveFromPost(array_merge($band, ['name' => 'Adjacent', 'min_distance_km' => '10', 'max_distance_km' => '15', 'is_active' => '1']), null);
okU($res['ok'], 'adjacent band [10,15) accepted');
okU(DeliveryAdminUi::friendlyError('Distance band requires both min and max km.') !== 'Distance band requires both min and max km.', 'engine error translated');
okU(DeliveryAdminUi::friendlyError('Something else') === 'Something else', 'unknown error passes through');
$saved = $pdo->query("SELECT zone_kind, match_type, flat_fee FROM delivery_zones WHERE name = 'Adjacent'")->fetch(PDO::FETCH_ASSOC);
okU($saved && $saved['zone_kind'] === 'distance_band' && $saved['match_type'] === 'pincode_prefix', 'stored values keep existing keys');

echo "\n--- Explanation uses real configured values ---\n";
DeliveryConfig::resetCache();
$zonesNow = DeliveryZoneRepository::allZones();
$lines = DeliveryAdminUi::explanationLines('pincode_legacy', $zonesNow, DeliveryConfig::store());
$text = implode("\n", $lines);
okU(strpos($text, 'below ₹499 → ₹40 delivery charge') !== false, 'pincode explanation shows ₹499/₹40 from zone');
okU(strpos($text, 'UI Near Band') === false, 'distance areas omitted in pincode-based explanation');
okU(strpos($text, 'Delivery unavailable') !== false, 'explanation states unavailable case');
$lines = DeliveryAdminUi::explanationLines('distance_hybrid', $zonesNow, DeliveryConfig::store());
$text = implode("\n", $lines);
okU(strpos($text, 'below ₹300 → ₹30') !== false && strpos($text, 'below ₹800 → ₹60') !== false, 'distance explanation shows each area values');
okU(strpos($text, 'map location') !== false, 'distance explanation mentions map location');
$pdo->exec('UPDATE delivery_zones SET free_above = 650, flat_fee = 55 WHERE name = \'UI Pin Zone\'');
DeliveryConfig::resetCache();
$text = implode("\n", DeliveryAdminUi::explanationLines('pincode_legacy', DeliveryZoneRepository::allZones(), DeliveryConfig::store()));
okU(strpos($text, 'below ₹650 → ₹55') !== false, 'explanation follows changed config');
okU(strpos(implode("\n", DeliveryAdminUi::explanationLines('pincode_legacy', [], DeliveryConfig::store())), 'No delivery area is enabled') !== false, 'no-zone explanation');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
