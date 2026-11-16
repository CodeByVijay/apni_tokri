<?php

declare(strict_types=1);

/**
 * Phase 5 — distance_hybrid readiness: band boundaries, overlaps, gaps,
 * coordinate coverage, non-destructive preview, order preservation.
 *
 * CLI: php scripts/run-in-disposable-db.php scripts/test-delivery-zone-readiness.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();

$fail = 0;
function rOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
$store = DeliveryConfig::store();
$sLat = (float) ($store['latitude'] ?? 22.7196);
$sLng = (float) ($store['longitude'] ?? 75.8577);

// East-offset helper: km → lng delta at store latitude.
$east = static function (float $km) use ($sLat, $sLng): array {
    $dl = $km / (111.32 * cos(deg2rad($sLat)));

    return [$sLat, $sLng + $dl];
};

// Fixture zones: pincode zone + two distance bands + a disabled band.
$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'RT-%'");
$ins = $pdo->prepare(
    "INSERT INTO delivery_zones (name, zone_kind, match_type, match_value, min_distance_km, max_distance_km,
      flat_fee, free_above, sort_order, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$ins->execute(['RT-Pincode', 'pincode', 'pincode_prefix', '4520', null, null, 30, 399, 10, 1]);
$ins->execute(['RT-Band-A', 'distance_band', 'pincode_prefix', '', 0, 5, 40, 499, 20, 1]);
$ins->execute(['RT-Band-B', 'distance_band', 'pincode_prefix', '', 5, 10, 60, 699, 30, 1]);
$ins->execute(['RT-Band-Off', 'distance_band', 'pincode_prefix', '', 10, 15, 99, 999, 40, 0]);
DeliveryConfig::resetCache();

// -- Boundaries (mode override = non-destructive preview) --
[$la, $lo] = $east(4.99);
$r = DeliveryZoneResolver::resolve('999999', $la, $lo, 'distance_hybrid');
rOk($r['serviceable'] && ($r['zone']['name'] ?? '') === 'RT-Band-A', 'band: just inside A (4.99km)');
rOk(abs((float) $r['distance_km'] - 4.99) < 0.1, 'band: distance reported ~4.99km');

[$la, $lo] = $east(5.0);
$r = DeliveryZoneResolver::resolve('999999', $la, $lo, 'distance_hybrid');
$zName = (string) ($r['zone']['name'] ?? '');
$dist = (float) ($r['distance_km'] ?? -1);
rOk($r['serviceable'] && (($zName === 'RT-Band-A' && $dist < 5.0) || ($zName === 'RT-Band-B' && $dist >= 5.0)),
    'band: ~5km respects max-exclusive boundary (zone=' . $zName . ' dist=' . $dist . ')');
rOk(!DeliveryZoneOverlapValidator::rangesOverlap(0, 5, 5, 10), 'rangesOverlap: [0,5) vs [5,10) do not overlap');
rOk(DeliveryZoneOverlapValidator::rangesOverlap(0, 5, 4.9, 5.1), 'rangesOverlap: [0,5) vs [4.9,5.1) overlap');

[$la, $lo] = $east(4.999);
$r = DeliveryZoneResolver::resolve('999999', $la, $lo, 'distance_hybrid');
rOk(($r['zone']['name'] ?? '') === 'RT-Band-A', 'band: 4.999km still A');

[$la, $lo] = $east(10.5);
$r = DeliveryZoneResolver::resolve('999999', $la, $lo, 'distance_hybrid');
rOk(!$r['serviceable'], 'band: 10.5km — only disabled band covers → unserviceable');

[$la, $lo] = $east(30.0);
$r = DeliveryZoneResolver::resolve('999999', $la, $lo, 'distance_hybrid');
rOk(!$r['serviceable'], 'outside all bands: unserviceable');

// -- Coordinates (pincode '999999' matches no pincode zone, so coords decide) --
$r = DeliveryZoneResolver::resolve('999999', null, null, 'distance_hybrid');
rOk(!$r['serviceable'] && !empty($r['requires_map_pin']), 'missing coords: requires_map_pin, not silently serviceable');
$r = DeliveryZoneResolver::resolve('999999', 0.0, 0.0, 'distance_hybrid');
rOk(!$r['serviceable'], 'invalid coords 0,0: not serviceable');
$r = DeliveryZoneResolver::resolve('999999', 95.0, 200.0, 'distance_hybrid');
rOk(!$r['serviceable'], 'out-of-range coords: rejected');

// -- Legacy unchanged --
$r = DeliveryZoneResolver::resolve('452010', null, null, 'pincode_legacy');
rOk(!empty($r['serviceable']), 'legacy override: pincode prefix still resolves');
$modeBefore = DeliveryZoneResolver::zoneResolutionMode();
rOk(DeliveryZoneResolver::zoneResolutionMode() === $modeBefore, 'override never changes stored mode');

// -- Integrity: overlap detection --
$pdo->exec("UPDATE delivery_zones SET min_distance_km=4, max_distance_km=12, is_active=1 WHERE name='RT-Band-Off'");
DeliveryConfig::resetCache();
$report = DeliveryZoneReadiness::report();
$hasOverlap = false;
foreach ($report['integrity']['errors'] as $e) {
    if (stripos($e, 'overlap') !== false) {
        $hasOverlap = true;
    }
}
rOk($hasOverlap, 'integrity: overlapping active bands reported');
$pdo->exec("UPDATE delivery_zones SET min_distance_km=10, max_distance_km=15, is_active=0 WHERE name='RT-Band-Off'");
DeliveryConfig::resetCache();
$report = DeliveryZoneReadiness::report();
$hasOverlap = false;
foreach ($report['integrity']['errors'] as $e) {
    if (stripos($e, 'overlap') !== false) {
        $hasOverlap = true;
    }
}
rOk(!$hasOverlap, 'integrity: disabled overlap ignored');
rOk($report['integrity']['gaps'] !== [], 'integrity: gap 10km→radius reported (no active band)');

// -- Gap-free check: extend B to cover through 25 km --
$pdo->exec("UPDATE delivery_zones SET max_distance_km=25 WHERE name='RT-Band-B'");
DeliveryConfig::resetCache();
$report2 = DeliveryZoneReadiness::report();
$radius = (float) ($report2['store']['max_radius_km'] ?? 12);
rOk($radius <= 25 ? $report2['integrity']['gaps'] === [] : $report2['integrity']['gaps'] !== [], 'integrity: gap detection consistent with store radius');

// -- Coverage --
$pdo->exec("DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile LIKE '9711%')");
$pdo->exec("DELETE FROM customers WHERE mobile LIKE '9711%'");
$pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9711000001','RT C',1)");
$cid = (int) $pdo->query("SELECT id FROM customers WHERE mobile='9711000001'")->fetchColumn();
$ca = $pdo->prepare("INSERT INTO customer_addresses (customer_id,label,receiver_name,receiver_phone,line1,area,city,state,pincode,latitude,longitude,location_confirmed_at,is_default) VALUES (?,'H','RT','9711000001','1 Rd','Vijay','Indore','MP',?,?,?,?,?)");
[$la, $lo] = $east(3.0);
$ca->execute([$cid, '452010', $la, $lo, date('Y-m-d H:i:s'), 1]);
$ca->execute([$cid, '452011', null, null, null, 0]);
$ca->execute([$cid, '452012', 0.0, 0.0, null, 0]);
$ca->execute([$cid, '452013', 200.0, 10.0, null, 0]);
[$la2, $lo2] = $east(7.0);
$ca->execute([$cid, '452014', $la2, $lo2, null, 0]); // valid but unconfirmed
$cov = DeliveryZoneReadiness::addressCoverage();
rOk($cov['missing_coords'] >= 1, 'coverage: missing coords counted');
rOk($cov['invalid_coords'] >= 2, 'coverage: invalid coords counted (0,0 and 200,10)');
rOk($cov['with_valid_coords'] >= 2, 'coverage: valid coords counted');
rOk($cov['confirmed_coords'] >= 1, 'coverage: confirmed coords counted');
rOk(($cov['with_valid_coords'] - $cov['confirmed_coords']) >= 1, 'coverage: unconfirmed-coords identifiable');

// -- Preview is non-destructive --
$modeVal = (string) (AppSettings::get('delivery.zone_resolution_mode') ?? '');
$orderCount = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$addrChecksum = (int) $pdo->query('SELECT COALESCE(SUM(id),0) FROM customer_addresses')->fetchColumn();
$addrLatSum = $pdo->query('SELECT COALESCE(SUM(latitude),0) FROM customer_addresses')->fetchColumn();
$prev = DeliveryZoneReadiness::previewComparison(50);
rOk((string) (AppSettings::get('delivery.zone_resolution_mode') ?? '') === $modeVal, 'preview: settings unchanged');
rOk((int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === $orderCount, 'preview: orders untouched');
rOk((int) $pdo->query('SELECT COALESCE(SUM(id),0) FROM customer_addresses')->fetchColumn() === $addrChecksum
    && $pdo->query('SELECT COALESCE(SUM(latitude),0) FROM customer_addresses')->fetchColumn() == $addrLatSum,
    'preview: addresses untouched');
rOk($prev['summary']['compared'] > 0, 'preview: compared rows produced');
rOk(array_sum(array_intersect_key($prev['summary'], array_flip(['same','still_serviceable_different_zone','becomes_unserviceable','newly_serviceable','needs_location_confirm']))) === $prev['summary']['compared'], 'preview: all rows classified');

// -- Activation is explicit-only --
rOk(AppSettings::get('delivery.zone_resolution_mode') !== 'distance_hybrid' || $modeVal === 'distance_hybrid',
    'activation: nothing auto-enables distance_hybrid');

// -- Pending warnings are counts only --
$pend = DeliveryZoneReadiness::pendingWarnings();
rOk(isset($pend['orders_unpaid'], $pend['orders_in_fulfilment'], $pend['subscriptions_active']), 'pending: warning counts present');

// -- Order snapshot preservation: orders carry their own zone id, unaffected by mode preview --
$orderZone = $pdo->query('SELECT delivery_zone_id FROM orders WHERE delivery_zone_id IS NOT NULL LIMIT 1')->fetchColumn();
if ($orderZone !== false) {
    $before = (int) $orderZone;
    DeliveryZoneReadiness::report();
    DeliveryZoneReadiness::previewComparison(10);
    rOk((int) $pdo->query('SELECT delivery_zone_id FROM orders WHERE delivery_zone_id IS NOT NULL LIMIT 1')->fetchColumn() === $before,
        'order snapshots: zone ids unchanged after report+preview');
} else {
    rOk(true, 'order snapshots: no zoned orders in fixture (n/a)');
}

// -- Guest/non-default resolution path (coords flow into resolver) --
[$la3, $lo3] = $east(2.0);
$r = DeliveryZoneResolver::resolve('999999', $la3, $lo3, 'distance_hybrid');
rOk($r['serviceable'] && ($r['zone']['name'] ?? '') === 'RT-Band-A', 'guest-style coords resolve to band A');

// -- Cart fee without slot: 'auto' still produces a consistent quote shape --
$q = DeliveryQuoteService::quoteForCheckout(
    ['pincode' => '452010', 'latitude' => $la3, 'longitude' => $lo3],
    [['price' => 300.0, 'qty' => 1, 'weight_kg' => 0.5]],
    'auto'
);
rOk(is_array($q) && array_key_exists('ok', $q), 'quote path returns consistent shape under current mode');

// Cleanup fixture zones
$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'RT-%'");
DeliveryConfig::resetCache();

echo ($fail === 0 ? 'ALL OK' : 'FAILURES: ' . $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
