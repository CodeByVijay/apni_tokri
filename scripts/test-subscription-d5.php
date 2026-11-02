<?php

declare(strict_types=1);

/**
 * D5 — subscription fulfilment aligned with DeliveryQuoteService + immutable delivery_snapshot_json.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
$skipped = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function skip(string $m): void
{
    global $skipped;
    echo "SKIP: {$m}\n";
    $skipped++;
}

Install::ensureConnection();
$pdo = Database::pdo();

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'schema version matches Install::SCHEMA_VERSION (D5)');

require_once __DIR__ . '/includes/subscription_test_fulfilment_fixture.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

$prevMode = AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy');
$storeRow = DeliveryConfig::store();
$prevRequireCoords = (int) ($storeRow['require_coordinates_for_checkout'] ?? 0);

function restoreDeliverySettings(PDO $pdo, string $mode, int $reqCoords): void
{
    AppSettings::set('delivery.zone_resolution_mode', $mode);
    $pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = ? WHERE id = 1')->execute([$reqCoords]);
    DeliveryConfig::resetCache();
}

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' LIMIT 1'
)->fetchColumn() ?: 0);
ok($variantId > 0, 'fixture variant');
$pdo->prepare('UPDATE product_variants SET stock_qty = 200 WHERE id = ?')->execute([$variantId]);

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
ok($customerId > 0, 'fixture customer');
$addrCheck = $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?');
$addrCheck->execute([$customerId]);
if ((int) $addrCheck->fetchColumn() === 0) {
    $pdo->prepare(
        'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?,?,?,?,?,?,1)'
    )->execute([$customerId, 'Home', 'Line', 'Indore', 'MP', '452001']);
}
SubscriptionTestFulfilmentFixture::ensureServiceableAddress($pdo, $customerId);

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
ok($plan !== null, 'veg_7 plan');
$snapshotPlan = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);

function createSubDay(
    PDO $pdo,
    int $customerId,
    array $planSnapshot,
    int $planId,
    float $price,
    int $variantId,
    ?string $preferredSlot = null
): array {
    $subNum = 'D5-' . strtoupper(substr(Security::randomToken(4), 0, 6));
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
         price_paid, status, deliveries_remaining, start_date, calendar_end_date, preferred_delivery_slot_slug)
         VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY, ?)'
    )->execute([
        $subNum,
        $customerId,
        $planId,
        json_encode($planSnapshot, JSON_UNESCAPED_UNICODE),
        $price,
        'active',
        7,
        $preferredSlot,
    ]);
    $subId = (int) $pdo->lastInsertId();
    $tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $pdo->prepare(
        'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at,
         basket_status, fulfilment_status, preferred_delivery_slot_slug)
         VALUES (?, ?, 1, ?, ?, ?, ?, ?)'
    )->execute([
        $subId,
        $today,
        'scheduled',
        (new DateTimeImmutable('-1 day', $tz))->format('Y-m-d H:i:s'),
        'confirmed',
        'none',
        $preferredSlot,
    ]);
    $dayId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, confirmed_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    )->execute([$dayId, 'suggested', 'confirmed', 200, 40]);
    $basketId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO subscription_delivery_basket_items (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$basketId, $variantId, 'D5', '500g', 'kg', 1, 40, 40]);

    return ['sub_id' => $subId, 'day_id' => $dayId, 'sub_num' => $subNum];
}

// 1–6: quote + snapshot on fulfilment (legacy mode)
AppSettings::set('delivery.zone_resolution_mode', 'pincode_legacy');
$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 0 WHERE id = 1')->execute();
DeliveryConfig::resetCache();

$ctx = createSubDay($pdo, $customerId, $snapshotPlan, (int) $plan['id'], (float) $plan['price'], $variantId, null);
$dayId = $ctx['day_id'];
SubscriptionTestFulfilmentFixture::prepareDeliveryDay($pdo, $dayId);
$runId = 'd5_' . time();
$gen = SubscriptionFulfilmentService::generateForDay($dayId, $runId);
ok($gen['ok'] === true, 'subscription quote generation via fulfilment' . (empty($gen['message']) ? '' : ' (' . $gen['message'] . ')'));

$orderId = (int) ($pdo->query('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ' . $dayId)->fetchColumn() ?: 0);
ok($orderId > 0, 'order created');
$snapRaw = (string) ($pdo->query('SELECT delivery_snapshot_json FROM orders WHERE id = ' . $orderId)->fetchColumn() ?: '');
$snap = DeliveryOrderSnapshot::parse($snapRaw);
ok($snap !== null && ($snap['schema_version'] ?? '') === DeliveryQuoteService::SNAPSHOT_SCHEMA, 'immutable D4 snapshot schema on subscription order');
ok(($snap['source'] ?? '') === 'subscription', 'snapshot source subscription');
ok(isset($snap['zone_id'], $snap['quoted_at'], $snap['fulfilment_mode']), 'snapshot zone/slot/fulfilment fields');
ok((int) ($snap['subscription_delivery_day_id'] ?? 0) === $dayId, 'snapshot links delivery day');

$orderFee = (float) ($pdo->query('SELECT delivery_fee FROM orders WHERE id = ' . $orderId)->fetchColumn() ?: -1);
ok($orderFee === 0.0, 'subscription order does not charge delivery fee again (prepaid)');

$gen2 = SubscriptionFulfilmentService::generateForDay($dayId, $runId);
ok(!empty($gen2['idempotent']), 'cron retry idempotent');

$delCount = (int) $pdo->query('SELECT COUNT(*) FROM deliveries WHERE order_id = ' . $orderId)->fetchColumn();
ok($delCount === 1, 'single delivery row (no duplicate on retry)');

// Hybrid + distance band pricing in snapshot
AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 0 WHERE id = 1')->execute();
DeliveryConfig::resetCache();
SubscriptionTestFulfilmentFixture::ensureServiceableAddress($pdo, $customerId);

$store = DeliveryConfig::store();
$lat = (float) $store['latitude'] + 0.02;
$pdo->exec("DELETE FROM delivery_zones WHERE name LIKE 'D4 band%' OR name LIKE 'D5 sub band%'");
$band = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
);
$band->execute(['D5 sub band', 'pincode_prefix', '', 'distance_band', 0, 50, 33, 999, 9201]);
$bandId = (int) $pdo->lastInsertId();

$ctxH = createSubDay($pdo, $customerId, $snapshotPlan, (int) $plan['id'], (float) $plan['price'], $variantId, SubscriptionTestFulfilmentFixture::SLOT_SLUG);
$dayH = $ctxH['day_id'];
SubscriptionTestFulfilmentFixture::prepareDeliveryDay($pdo, $dayH);
$pdo->prepare('UPDATE customer_addresses SET latitude = ?, longitude = ?, pincode = ? WHERE customer_id = ?')
    ->execute([$lat, (float) $store['longitude'] + 0.02, '110001', $customerId]);
$genH = SubscriptionFulfilmentService::generateForDay($dayH, 'd5hy_' . time());
ok($genH['ok'] === true, 'hybrid zone subscription fulfilment' . (empty($genH['message']) ? '' : ' (' . $genH['message'] . ')'));
$ordH = (int) ($pdo->query('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ' . $dayH)->fetchColumn() ?: 0);
$snapH = DeliveryOrderSnapshot::parse((string) $pdo->query('SELECT delivery_snapshot_json FROM orders WHERE id = ' . $ordH)->fetchColumn());
$quotedFee = (float) ($snapH['delivery_fee'] ?? -1);
ok($snapH !== null && ($snapH['zone_name'] ?? '') === 'D5 sub band', 'subscription snapshot uses D5 distance band');
ok($quotedFee === 33.0, 'distance-band fee recorded in snapshot (fee=' . $quotedFee . ')');
ok(!empty($snapH['distance_km']), 'distance recorded in subscription snapshot');

$pdo->prepare('UPDATE delivery_zones SET flat_fee = 999 WHERE id = ?')->execute([$bandId]);
$snapAfter = DeliveryOrderSnapshot::parse((string) $pdo->query('SELECT delivery_snapshot_json FROM orders WHERE id = ' . $ordH)->fetchColumn());
ok((float) ($snapAfter['delivery_fee'] ?? 0) === 33.0, 'admin zone change does not rewrite subscription snapshot');

// Coordinate requirement failure
$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 1 WHERE id = 1')->execute();
DeliveryConfig::resetCache();
$pdo->prepare('UPDATE customer_addresses SET latitude = NULL, longitude = NULL WHERE customer_id = ?')->execute([$customerId]);
$ctxC = createSubDay($pdo, $customerId, $snapshotPlan, (int) $plan['id'], (float) $plan['price'], $variantId, SubscriptionTestFulfilmentFixture::SLOT_SLUG);
$dayC = $ctxC['day_id'];
$slotC = SubscriptionTestFulfilmentFixture::ensureBookableSlot($pdo);
$dateC = SubscriptionTestFulfilmentFixture::pickDeliveryDateForSlot($slotC);
$pdo->prepare(
    'UPDATE subscription_delivery_days SET delivery_date = ?, preferred_delivery_slot_slug = ?, preferred_delivery_slot_id = ?,
     selection_cutoff_at = NOW() - INTERVAL 1 DAY WHERE id = ?'
)->execute([$dateC, (string) $slotC['slug'], (int) $slotC['id'], $dayC]);
$genC = SubscriptionFulfilmentService::generateForDay($dayC, 'd5coord_' . time());
ok($genC['ok'] === false && (($genC['message'] ?? '') !== ''), 'coordinate requirement blocks fulfilment');
$evC = (string) ($pdo->query('SELECT fulfilment_status FROM subscription_delivery_days WHERE id = ' . $dayC)->fetchColumn() ?: '');
ok($evC === 'failed', 'failed quote marks day failed');
$holdC = (int) $pdo->query('SELECT COUNT(*) FROM subscription_stock_reservations WHERE subscription_delivery_day_id = ' . $dayC . " AND status = 'held'")->fetchColumn();
ok($holdC === 0, 'inventory hold released on quote failure');

SubscriptionTestFulfilmentFixture::ensureServiceableAddress($pdo, $customerId);
$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 0 WHERE id = 1')->execute();
DeliveryConfig::resetCache();

// Slot capacity exhaustion
$slotRow = SubscriptionTestFulfilmentFixture::ensureBookableSlot($pdo);
$slotId = (int) $slotRow['id'];
$capDate = SubscriptionTestFulfilmentFixture::pickDeliveryDateForSlot($slotRow);
$pdo->prepare('UPDATE delivery_slots SET capacity = 1 WHERE id = ?')->execute([$slotId]);
$pdo->exec("DELETE FROM delivery_slot_reservations WHERE delivery_slot_id = {$slotId} AND delivery_date = " . $pdo->quote($capDate));
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
     delivery_slot, delivery_slot_id, delivery_date, placed_at)
     VALUES ('D5-CAP-BLOCK', 'confirmed', 1, 0, 0, 1, 'paid', ?, ?, ?, NOW())"
)->execute([(string) $slotRow['slug'], $slotId, $capDate]);

$ctxCap = createSubDay($pdo, $customerId, $snapshotPlan, (int) $plan['id'], (float) $plan['price'], $variantId, (string) $slotRow['slug']);
$dayCap = $ctxCap['day_id'];
$pdo->prepare(
    'UPDATE subscription_delivery_days SET delivery_date = ?, preferred_delivery_slot_slug = ?, preferred_delivery_slot_id = ?,
     selection_cutoff_at = NOW() - INTERVAL 1 DAY WHERE id = ?'
)->execute([$capDate, (string) $slotRow['slug'], $slotId, $dayCap]);
$genCap = SubscriptionFulfilmentService::generateForDay($dayCap, 'd5cap_' . time());
ok($genCap['ok'] === false, 'slot capacity blocks subscription fulfilment');
$stCap = (string) ($pdo->query('SELECT fulfilment_status FROM subscription_delivery_days WHERE id = ' . $dayCap)->fetchColumn() ?: '');
ok($stCap === 'slot_unavailable', 'slot_unavailable status on capacity failure');
$pdo->prepare('UPDATE delivery_slots SET capacity = 500 WHERE id = ?')->execute([$slotId]);
$pdo->exec("DELETE FROM orders WHERE order_number = 'D5-CAP-BLOCK'");

// Outside zone quote failure
AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
$pdo->prepare('UPDATE customer_addresses SET pincode = ?, latitude = ?, longitude = ? WHERE customer_id = ?')
    ->execute(['110001', (float) $store['latitude'] + 5.0, (float) $store['longitude'], $customerId]);
$ctxOut = createSubDay($pdo, $customerId, $snapshotPlan, (int) $plan['id'], (float) $plan['price'], $variantId, SubscriptionTestFulfilmentFixture::SLOT_SLUG);
$dayOut = $ctxOut['day_id'];
$slotOut = SubscriptionTestFulfilmentFixture::ensureBookableSlot($pdo);
$dateOut = SubscriptionTestFulfilmentFixture::pickDeliveryDateForSlot($slotOut);
$pdo->prepare(
    'UPDATE subscription_delivery_days SET delivery_date = ?, preferred_delivery_slot_slug = ?, preferred_delivery_slot_id = ?,
     selection_cutoff_at = NOW() - INTERVAL 1 DAY WHERE id = ?'
)->execute([$dateOut, (string) $slotOut['slug'], (int) $slotOut['id'], $dayOut]);
$genOut = SubscriptionFulfilmentService::generateForDay($dayOut, 'd5out_' . time());
ok($genOut['ok'] === false, 'outside-zone subscription quote fails safely');

$pdo->prepare("UPDATE customer_subscriptions SET status = 'paused' WHERE id = ?")->execute([$ctxOut['sub_id']]);
$genPause = SubscriptionFulfilmentService::generateForDay($dayOut, 'd5pause_day');
ok(!empty($genPause['idempotent']), 'paused subscription day skipped (idempotent)');

SubscriptionTestFulfilmentFixture::ensureServiceableAddress($pdo, $customerId);
restoreDeliverySettings($pdo, $prevMode, $prevRequireCoords);
$pdo->prepare('UPDATE customer_addresses SET pincode = ?, latitude = NULL, longitude = NULL WHERE customer_id = ?')
    ->execute(['452001', $customerId]);
AppSettings::set('delivery.zone_resolution_mode', 'pincode_legacy');
$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 0 WHERE id = 1')->execute();
DeliveryConfig::resetCache();
$pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$bandId]);

try {
    $phpBin = test_cli_php_binary();
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Regression runner: ' . $e->getMessage() . "\n");
    exit(1);
}

$regressions = [
    'test-delivery-d1.php' => 'D1',
    'test-delivery-d2.php' => 'D2',
    'test-delivery-d2-hardening.php' => 'D2 hardening',
    'test-delivery-d3.php' => 'D3',
    'test-delivery-d3-hardening.php' => 'D3 hardening',
    'test-delivery-d4.php' => 'D4',
    'test-delivery-stage8.php' => 'Stage 8',
    'test-payment-stage9.php' => 'Stage 9',
    'test-promotions-stage10.php' => 'Stage 10',
    'test-subscription-stage11.php' => 'Stage 11',
    'test-subscription-stage11-hardening.php' => 'Stage 11 hardening',
    'test-subscription-stage11-production-readiness.php' => 'Stage 11 production readiness',
    'test-trust-stage12.php' => 'Stage 12 A',
    'test-trust-stage12-phase-b.php' => 'Stage 12 B',
    'test-delivery-slot-concurrency.php' => 'Slot concurrency',
];

echo 'Regression runner: PHP_BINARY=' . $phpBin . ' PHP_SAPI=' . PHP_SAPI . ' bundled=' . count($regressions) . "\n";

foreach ($regressions as $script => $label) {
    $path = APNI_TOKRI_ROOT . '/scripts/' . $script;
    if (!is_readable($path)) {
        skip("{$label} (missing script)");
        continue;
    }
    $out = [];
    $code = test_cli_exec_script($path, $out);
    $joined = implode("\n", $out);
    $failed = $code !== 0
        || preg_match('/\bFAIL:/', $joined)
        || preg_match('/\bFAILURES:\s*[1-9]/', $joined)
        || preg_match('/\d+ SUITE\(S\) FAILED/', $joined);
    ok(!$failed, "{$label} regression (exit {$code})");
}

if ($skipped > 0) {
    echo "\nSkipped: {$skipped}\n";
}

if ($fail > 0) {
    echo "\n{$fail} check(s) failed.\n";
    exit(1);
}

echo "\nPASS: Subscription D5 fulfilment alignment.\n";
exit(0);
