<?php

declare(strict_types=1);

/**
 * Integration checks for delivery-test-result partial (disposable DB).
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-delivery-test-result-integration.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0;
$fail = 0;

function okI(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

Install::ensureConnection();
$pdo = Database::pdo();
$pdo->exec("UPDATE admin_users SET password_hash = '" . password_hash('qa', PASSWORD_BCRYPT) . "' WHERE id = 1");

$longName = 'Indore Central — Premium Same-Day Corridor (4520xx extended service area with long descriptive title)';

// Distance band for hybrid/distance scenario
$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'INT-%'");
$pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
)->execute(['INT-Near', 'pincode_prefix', '', 'distance_band', 0, 5, 30, 300, 9000]);
DeliveryConfig::resetCache();
$store = DeliveryConfig::store();
$lat = (float) $store['latitude'] + 0.02;
$lon = (float) $store['longitude'];

function renderPartial(array $test): string
{
    ob_start();
    require dirname(__DIR__) . '/includes/partials/admin/delivery-test-result.php';

    return (string) ob_get_clean();
}

echo "--- Calculator + partial scenarios ---\n";

$r40 = DeliveryAdminUi::testCalculation('452010', '', '', '350');
okI($r40['ok'] && $r40['serviceable'] && (float) $r40['fee_for_amount'] === 40.0, '₹40 charge for ₹350 order');
$h40 = renderPartial($r40);
okI(strpos($h40, 'at-delivery-test-result__fee') !== false && strpos($h40, '₹40') !== false, 'partial shows prominent ₹40');
okI(strpos($h40, 'Just below') === false, 'no duplicate threshold example rows');

$rFree = DeliveryAdminUi::testCalculation('452010', '', '', '650');
okI($rFree['serviceable'] && (float) $rFree['fee_for_amount'] === 0.0, 'free delivery for ₹650');
$hFree = renderPartial($rFree);
okI(strpos($hFree, 'Free delivery') !== false && strpos($hFree, 'at-delivery-test-result--ok') !== false, 'partial shows Free delivery headline');

$rNo = DeliveryAdminUi::testCalculation('110001', '', '', '');
okI(!$rNo['serviceable'], '110001 unavailable');
$hNo = renderPartial($rNo);
okI(strpos($hNo, 'at-delivery-test-result--no') !== false && strpos($hNo, 'Delivery unavailable') !== false, 'partial unavailable state');
okI(strpos($hNo, 'at-delivery-test-result__fee') === false, 'no fee block when unavailable');

$rLong = $r40;
$rLong['zone_name'] = $longName;
$hLong = renderPartial($rLong);
okI(strpos($hLong, Security::escape($longName)) !== false || strpos($hLong, $longName) !== false, 'long delivery area name in output');
okI(strpos($hLong, 'at-delivery-test-result__value') !== false, 'long name uses value wrapper class');

AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
DeliveryConfig::resetCache();
$rDist = DeliveryAdminUi::testCalculation('110001', (string) $lat, (string) $lon, '100');
okI($rDist['serviceable'] && strpos((string) $rDist['method'], 'Distance') !== false, 'distance-based matching');
$hDist = renderPartial($rDist);
okI(strpos($hDist, 'Matching method') !== false && strpos($hDist, 'Distance') !== false, 'partial shows distance matching method');
okI(strpos($hDist, 'About') !== false && strpos($hDist, 'km') !== false, 'partial shows distance when present');

AppSettings::set('delivery.zone_resolution_mode', 'pincode_legacy');

echo "--- zones.php wiring ---\n";
$zonesSrc = (string) file_get_contents(dirname(__DIR__) . '/admin/settings/delivery/zones.php');
okI(strpos($zonesSrc, "require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-test-result.php'") !== false, 'zones.php requires partial');
okI(substr_count($zonesSrc, 'delivery-test-result.php') === 1, 'single partial include in zones.php');
okI(strpos($zonesSrc, 'grid-cols-[auto') === false, 'no legacy auto/1fr result grid in zones.php');
okI(strpos($zonesSrc, 'fee_below') === false && strpos($zonesSrc, 'fee_at_threshold') === false, 'no inline fee example markup in zones.php');
okI(strpos($zonesSrc, 'DeliveryAdminUi::testCalculation') !== false, 'zones still uses testCalculation only');

echo "--- CSS on admin + cache bust ---\n";
$headAdmin = (string) file_get_contents(dirname(__DIR__) . '/includes/layout/head-admin.php');
okI(strpos($headAdmin, "asset_url('assets/css/apni-tokri.css')") !== false, 'head-admin loads apni-tokri.css via asset_url');
$cssUrl = asset_url('assets/css/apni-tokri.css');
okI(strpos($cssUrl, '?v=') !== false, 'asset_url cache-bust query on CSS');
$cssFile = dirname(__DIR__) . '/assets/css/apni-tokri.css';
okI(strpos((string) file_get_contents($cssFile), '.at-delivery-test-result') !== false, 'delivery test result rules in apni-tokri.css');

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
