<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migrations through 1.8.0.\n");
    exit(1);
}

if (!DeliveryZoneRepository::hasColumn('zone_kind')) {
    fwrite(STDERR, "Missing 1.8.0 columns — apply database/migrations/1.8.0_delivery_zones_slots.sql\n");
    exit(1);
}

$fail = 0;

function assertTrue(bool $cond, string $label): void
{
    global $fail;
    if ($cond) {
        echo "OK: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $fail++;
    }
}

Install::ensureConnection();
$pdo = Database::pdo();

$prevMode = AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy');
AppSettings::set('delivery.zone_resolution_mode', 'pincode_legacy');

$legacy = DeliveryService::evaluatePincode('452010');
assertTrue($legacy['serviceable'], 'legacy 452010 still serviceable');
$outside = DeliveryService::evaluatePincode('110001');
assertTrue(!$outside['serviceable'], 'legacy 110001 not serviceable');

assertTrue(!DeliveryZoneOverlapValidator::rangesOverlap(0.0, 5.0, 5.0, 10.0), '[0,5) and [5,10) do not overlap');
assertTrue(DeliveryZoneOverlapValidator::rangesOverlap(0.0, 5.0, 4.0, 10.0), '[0,5) and [4,10) overlap');

$bandA = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
);
$bandA->execute(['D1 test band A', 'pincode_prefix', '', 'distance_band', 0, 5, 50, 500, 9001]);
$idA = (int) $pdo->lastInsertId();

$errors = DeliveryZoneOverlapValidator::validateSavePayload([
    'zone_kind' => 'distance_band',
    'min_distance_km' => 4,
    'max_distance_km' => 8,
    'match_value' => '',
], null);
assertTrue($errors !== [], 'overlapping active band rejected on validate');

AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');

$store = DeliveryConfig::store();
$lat = (float) $store['latitude'] + 0.01;
$lon = (float) $store['longitude'] + 0.01;
$resolved = DeliveryZoneResolver::resolve('452010', $lat, $lon);
assertTrue($resolved['serviceable'], 'hybrid: pincode zone matches without distance band');
assertTrue($resolved['distance_km'] !== null, 'hybrid: distance computed from coords');

$noCoords = DeliveryZoneResolver::resolve('452010', null, null);
assertTrue($noCoords['serviceable'], 'hybrid: pincode-only zone without coords');

$onlyBand = DeliveryZoneResolver::resolve('110001', $lat, $lon);
assertTrue($onlyBand['serviceable'] && (int) ($onlyBand['zone']['id'] ?? 0) === $idA, 'hybrid: distance band matches by km');

$noCoordsBand = DeliveryZoneResolver::resolve('110001', null, null);
assertTrue(!$noCoordsBand['serviceable'] && !empty($noCoordsBand['requires_map_pin']), 'distance band requires map pin when coords missing');

$pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$idA]);
AppSettings::set('delivery.zone_resolution_mode', $prevMode ?? 'pincode_legacy');

$stage8Out = [];
$code = test_cli_exec_script(APNI_TOKRI_ROOT . '/scripts/test-delivery-stage8.php', $stage8Out);
$out = $stage8Out;
if ($code !== 0) {
    echo "FAIL: test-delivery-stage8.php exit {$code}\n" . implode("\n", $out) . "\n";
    $fail++;
} else {
    echo "OK: Stage 8 regression (via test-delivery-stage8.php)\n";
}

if ($fail > 0) {
    echo "\n{$fail} assertion(s) failed.\n";
    exit(1);
}

echo "\nPASS: Delivery D1 zone checks.\n";
exit(0);
