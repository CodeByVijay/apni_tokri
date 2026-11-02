<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/test_cli_runner.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    if ($c) {
        echo "OK: {$m}\n";
    } else {
        echo "FAIL: {$m}\n";
        $fail++;
    }
}

Install::ensureConnection();
$pdo = Database::pdo();

ok(Install::installedSchemaVersion() === Install::SCHEMA_VERSION, 'schema version matches Install::SCHEMA_VERSION');
$pdo->exec("DELETE FROM payment_completion_exceptions WHERE gateway_payment_id LIKE 'd4_gw_%'");
$pdo->exec("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'D4-%')");
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'D4-%'");

$prevMode = AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy');
$storeRow = DeliveryConfig::store();
$prevRequireCoords = (int) ($storeRow['require_coordinates_for_checkout'] ?? 0);
$prevManualFallback = (int) ($storeRow['manual_delivery_when_provider_unavailable'] ?? 0);
$prevRoadProvider = (string) ($storeRow['road_distance_provider'] ?? 'none');
$prevDistanceMode = (string) ($storeRow['default_distance_mode'] ?? 'haversine');

function restoreGlobals(PDO $pdo, string $prevMode, int $req, int $fb, string $road, string $dist): void
{
    AppSettings::set('delivery.zone_resolution_mode', $prevMode);
    $pdo->prepare(
        'UPDATE delivery_store SET require_coordinates_for_checkout = ?, manual_delivery_when_provider_unavailable = ?,
         road_distance_provider = ?, default_distance_mode = ? WHERE id = 1'
    )->execute([$req, $fb, $road, $dist]);
    DeliveryConfig::resetCache();
}

$store = DeliveryConfig::store();
$lat = (float) $store['latitude'] + 0.02;
$lon = (float) $store['longitude'] + 0.02;

$lines = [
    ['variant_id' => 1, 'qty' => 1, 'unit_price' => 100.0, 'grants_free_delivery' => false],
];

$addressNoCoords = [
    'pincode' => '452010',
    'latitude' => null,
    'longitude' => null,
];
$addressWithCoords = [
    'pincode' => '452010',
    'latitude' => $lat,
    'longitude' => $lon,
];

AppSettings::set('delivery.zone_resolution_mode', 'distance_hybrid');
$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 0 WHERE id = 1')->execute();
DeliveryConfig::resetCache();

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
ok($customerId > 0, 'fixture customer');

$band = $pdo->prepare(
    'INSERT INTO delivery_zones (name, match_type, match_value, zone_kind, min_distance_km, max_distance_km, flat_fee, free_above, sort_order, is_active)
     VALUES (?,?,?,?,?,?,?,?,?,1)'
);
$band->execute(['D4 band', 'pincode_prefix', '', 'distance_band', 0, 50, 55, 999, 9100]);
$bandId = (int) $pdo->lastInsertId();

$quoteSlot = 'd4_q_' . substr(Security::randomToken(3), 0, 5);
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity,
     sort_order, is_active, weekday_mask, booking_cutoff_minutes, allowed_provider_slugs_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $quoteSlot, 'D4 Quote', 'Test', 'same_day', '23:00:00', '23:30:00', '22:00:00', 100, 9997, 1, 127, 5,
    json_encode(['manual']),
]);

$farLat = (float) $store['latitude'] + 2.0;
$outside = DeliveryQuoteService::quoteForCheckout(
    ['pincode' => '110001', 'latitude' => $farLat, 'longitude' => $lon],
    $lines,
    $quoteSlot
);
ok(empty($outside['ok']) && ($outside['failure_code'] ?? '') === 'outside_zone', 'outside-zone checkout rejected');

$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 1 WHERE id = 1')->execute();
DeliveryConfig::resetCache();
$missing = DeliveryQuoteService::quoteForCheckout($addressNoCoords, $lines, $quoteSlot);
ok(empty($missing['ok']) && ($missing['failure_code'] ?? '') === 'coordinates_required', 'missing coordinates blocked when policy enabled');

$pdo->prepare('UPDATE delivery_store SET require_coordinates_for_checkout = 0 WHERE id = 1')->execute();
DeliveryConfig::resetCache();
$hybridNoCoords = DeliveryQuoteService::quoteForCheckout(
    ['pincode' => '110001', 'latitude' => null, 'longitude' => null],
    $lines,
    $quoteSlot
);
ok(empty($hybridNoCoords['ok']) && !empty($hybridNoCoords['failure_code']), 'distance band without coords rejected');

$quoted = DeliveryQuoteService::quoteForCheckout(
    ['pincode' => '110001', 'latitude' => $lat, 'longitude' => $lon],
    $lines,
    $quoteSlot
);
ok(!empty($quoted['ok']), 'coordinate-based quote succeeds (distance band)');
if (!empty($quoted['ok'])) {
    ok($quoted['distance_km'] !== null && ($quoted['distance_source'] ?? '') === 'haversine', 'Haversine distance on quote');
    ok((float) ($quoted['delivery_fee'] ?? 0) === 55.0, 'distance-band flat fee applied');
} else {
    ok(false, 'coordinate quote detail: ' . ($quoted['message'] ?? ''));
    ok(false, 'distance-band flat fee applied');
}

$previewTotals = PricingService::totalsFromLines($lines, null, '452010');
$previewMerged = PricingService::applyDeliveryQuotePreview(
    $previewTotals,
    $lines,
    ['pincode' => '110001', 'latitude' => $lat, 'longitude' => $lon],
    $quoteSlot
);
ok(
    !empty($previewMerged['delivery_quote_accurate'])
    && (float) ($previewMerged['delivery_fee'] ?? -1) === (float) ($quoted['delivery_fee'] ?? -1),
    'cart preview fee matches checkout quote'
);

$feeBreak = DeliveryQuoteService::deliveryFeeForZone(
    ['flat_fee' => 40, 'free_above' => 50],
    [['qty' => 2, 'unit_price' => 30, 'grants_free_delivery' => false]],
    60.0
);
ok((float) $feeBreak['fee'] === 0.0, 'free delivery above threshold in band fee helper');

$snap = DeliveryQuoteService::buildSnapshot($quoted);
ok(($snap['schema_version'] ?? '') === DeliveryQuoteService::SNAPSHOT_SCHEMA, 'snapshot schema version set');
ok(isset($snap['zone_id'], $snap['quoted_at'], $snap['fulfilment_mode']), 'immutable snapshot fields present');

$pdo->prepare('UPDATE delivery_zones SET flat_fee = 999 WHERE id = ?')->execute([$bandId]);
$storedSnap = json_encode($snap, JSON_UNESCAPED_UNICODE);
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
     delivery_slot, delivery_zone_id, delivery_snapshot_json, placed_at)
     VALUES ('D4-SNAP', 'pending_payment', 100, 0, ?, ?, 'pending', 'morning', ?, ?, NOW())"
)->execute([(float) $quoted['delivery_fee'], (float) $quoted['grand_total'], $bandId, $storedSnap]);
$snapOrderId = (int) $pdo->lastInsertId();
$decoded = json_decode((string) $pdo->query("SELECT delivery_snapshot_json FROM orders WHERE id = {$snapOrderId}")->fetchColumn(), true);
ok((float) ($decoded['delivery_fee'] ?? 0) === 55.0, 'admin zone fee change does not rewrite existing snapshot');

$morning = DeliverySlotRepository::findBySlug('morning');
ok($morning !== null, 'morning slot for capacity test');
if ($morning !== null) {
    $slotId = (int) $morning['id'];
    $date = DeliveryConfig::now()->format('Y-m-d');
    $pdo->exec("DELETE FROM delivery_slot_reservations WHERE delivery_slot_id = {$slotId} AND delivery_date = '{$date}'");
    $pdo->exec("DELETE FROM orders WHERE order_number LIKE 'D4-CAP-%'");

    $pdo->prepare(
        "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
         delivery_slot, delivery_slot_id, delivery_date, customer_address_id, placed_at)
         VALUES ('D4-CAP-FULL', 'confirmed', 10, 0, 0, 10, 'paid', 'morning', ?, ?, 1, NOW())"
    )->execute([$slotId, $date]);

    $capQuote = DeliveryQuoteService::quoteForCheckout($addressWithCoords, $lines, 'morning', null, $pdo);
    ok(empty($capQuote['ok']), 'slot capacity blocks quote when full');
}

$pdo->prepare('UPDATE delivery_store SET default_distance_mode = ?, road_distance_provider = ?, manual_delivery_when_provider_unavailable = 0 WHERE id = 1')
    ->execute(['road', 'osrm']);
DeliveryConfig::resetCache();
$testSlug = 'd4prov_' . substr(Security::randomToken(3), 0, 5);
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity,
     sort_order, is_active, weekday_mask, booking_cutoff_minutes, allowed_provider_slugs_json)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $testSlug, 'D4 Prov', 'Test', 'same_day', '23:00:00', '23:30:00', '22:00:00', 50, 9998, 1, 127, 30,
    json_encode(['partner_only']),
]);
$provQuote = DeliveryQuoteService::quoteForCheckout($addressWithCoords, $lines, $testSlug);
ok(empty($provQuote['ok']), 'provider unavailable without manual fallback on restricted slot');

$pdo->prepare('UPDATE delivery_store SET manual_delivery_when_provider_unavailable = 1 WHERE id = 1')->execute();
DeliveryConfig::resetCache();
$fallbackQuote = DeliveryQuoteService::quoteForCheckout($addressWithCoords, $lines, $testSlug);
ok(empty($fallbackQuote['ok']), 'manual fallback blocked when manual not in slot allow-list');

$pdo->prepare('UPDATE delivery_slots SET allowed_provider_slugs_json = ? WHERE slug = ?')
    ->execute([json_encode(['manual', 'partner_only']), $testSlug]);
$fallbackOk = DeliveryQuoteService::quoteForCheckout($addressWithCoords, $lines, $testSlug);
ok(!empty($fallbackOk['ok']) && ($fallbackOk['fulfilment_mode'] ?? '') === 'manual', 'manual fallback when manual in allow-list');

$driftSnap = DeliveryQuoteService::buildSnapshot($quoted);
ok(DeliveryOrderSnapshot::detectAddressDrift($driftSnap, ['pincode' => '110002', 'latitude' => $lat, 'longitude' => $lon]) === 'pincode_changed', 'detect pincode drift');
ok(DeliveryOrderSnapshot::detectAddressDrift($driftSnap, ['pincode' => '110001', 'latitude' => $lat + 0.01, 'longitude' => $lon]) === 'coordinates_changed', 'detect coordinate drift');
ok(DeliveryOrderSnapshot::detectAddressDrift($driftSnap, ['pincode' => '110001', 'latitude' => $lat, 'longitude' => $lon]) === null, 'unchanged address no drift');

$variantId = (int) ($pdo->query('SELECT id FROM product_variants WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($variantId > 0 && PaymentCompletionExceptionService::tableReady()) {
    $pdo->prepare('UPDATE product_variants SET stock_qty = 5 WHERE id = ?')->execute([$variantId]);
    $invSnap = json_encode(DeliveryQuoteService::buildSnapshot($quoted), JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE customer_addresses SET pincode = ?, latitude = ?, longitude = ? WHERE id = 1')
        ->execute(['110001', $lat, $lon]);
    $pdo->exec("DELETE FROM orders WHERE order_number = 'D4-INV'");
    $pdo->prepare(
        "INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, discount_total, delivery_fee, grand_total,
         payment_status, delivery_slot, delivery_snapshot_json, placed_at)
         VALUES ('D4-INV', ?, 1, 'pending_payment', 100, 0, 55, 155, 'pending', ?, ?, NOW())"
    )->execute([$customerId, $quoteSlot, $invSnap]);
    $invOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?,?)'
    )->execute([$invOrderId, $variantId, 'D4 test', 'unit', 10, 10.0, 100.0]);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?,?,?,?,?,?)'
    )->execute([$invOrderId, 'simulate', 155, 'INR', 'created', 'pay_D4-INV_1']);
    $pdo->prepare('UPDATE product_variants SET stock_qty = 0 WHERE id = ?')->execute([$variantId]);
    $invPay = PaymentService::completePayment('D4-INV', 'simulate', 'd4_gw_inv_1', 'd4_ord_inv', 155.0, 'INR', 'pay_D4-INV_1', 'evt_inv');
    ok(!empty($invPay['reconciliation']), 'inventory failure routes to reconciliation');
    $exCat = $pdo->query("SELECT failure_category FROM payment_completion_exceptions WHERE gateway_payment_id = 'd4_gw_inv_1' LIMIT 1")->fetchColumn();
    ok((string) $exCat === PaymentCompletionExceptionService::CATEGORY_INVENTORY, 'inventory exception category');
    $dupInv = PaymentService::completePayment('D4-INV', 'simulate', 'd4_gw_inv_1', 'd4_ord_inv', 155.0, 'INR', 'pay_D4-INV_1', 'evt_inv2');
    ok(!empty($dupInv['reconciliation']) || !$dupInv['ok'], 'duplicate webhook after inventory failure is idempotent');
    $pdo->prepare('UPDATE product_variants SET stock_qty = 50 WHERE id = ?')->execute([$variantId]);
    $pdo->prepare('UPDATE customer_addresses SET pincode = ?, latitude = ?, longitude = ? WHERE id = 1')
        ->execute(['452001', null, null]);
    $pdo->exec("DELETE FROM payment_completion_exceptions WHERE gateway_payment_id = 'd4_gw_inv_1'");
    $pdo->exec("DELETE FROM payments WHERE order_id = {$invOrderId}");
    $pdo->exec("DELETE FROM orders WHERE id = {$invOrderId}");
} else {
    echo "SKIP: inventory reconciliation (variant or exceptions table)\n";
}

$report = DeliveryOrderSnapshot::reportingDeliveryQuote([
    'delivery_fee' => 999,
    'delivery_snapshot_json' => json_encode(['delivery_fee' => 55, 'zone_name' => 'D4 band', 'quoted_at' => date('c')], JSON_UNESCAPED_UNICODE),
]);
ok((float) ($report['delivery_fee'] ?? 0) === 55.0 && !empty($report['from_snapshot']), 'admin reporting prefers snapshot fee');

if ($morning !== null && PaymentCompletionExceptionService::tableReady()) {
    $slotId = (int) $morning['id'];
    $date = DeliveryConfig::now()->format('Y-m-d');
    $pdo->exec("DELETE FROM payment_completion_exceptions WHERE gateway_payment_id LIKE 'd4_gw_%'");
    $pdo->exec("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE order_number = 'D4-PAY-1')");
    $pdo->exec("DELETE FROM orders WHERE order_number = 'D4-PAY-1'");

    $pdo->prepare(
        "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
         delivery_slot, delivery_slot_id, delivery_date, customer_address_id, placed_at)
         VALUES ('D4-PAY-1', 'pending_payment', 50, 0, 0, 50, 'pending', 'morning', ?, ?, 1, NOW())"
    )->execute([$slotId, $date]);
    $payOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$payOrderId, 'simulate', 50, 'INR', 'created', 'pay_D4-PAY-1_1']);

    $pdo->prepare(
        "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status,
         delivery_slot, delivery_slot_id, delivery_date, placed_at)
         VALUES ('D4-PAY-BLOCK', 'confirmed', 10, 0, 0, 10, 'paid', 'morning', ?, ?, NOW())"
    )->execute([$slotId, $date]);

    $payResult = PaymentService::completePayment(
        'D4-PAY-1',
        'simulate',
        'd4_gw_cap_1',
        'd4_ord_1',
        50.0,
        'INR',
        'pay_D4-PAY-1_1',
        'evt_d4_1'
    );
    ok(!empty($payResult['reconciliation']) || !$payResult['ok'], 'captured payment path records reconciliation on slot failure');

    $paidSt = $pdo->query("SELECT payment_status FROM orders WHERE order_number = 'D4-PAY-1'")->fetchColumn();
    ok((string) $paidSt !== 'paid', 'order not paid when delivery revalidation fails');

    $dup = PaymentService::completePayment(
        'D4-PAY-1',
        'simulate',
        'd4_gw_cap_1',
        'd4_ord_1',
        50.0,
        'INR',
        'pay_D4-PAY-1_1',
        'evt_d4_2'
    );
    ok(!empty($dup['reconciliation']) || !$dup['ok'], 'webhook retry idempotent on delivery failure');
}

$pdo->prepare('DELETE FROM delivery_zones WHERE id = ?')->execute([$bandId]);
$pdo->exec("DELETE FROM delivery_slots WHERE slug = " . $pdo->quote($testSlug) . " OR slug = " . $pdo->quote($quoteSlot));
$pdo->exec("DELETE FROM orders WHERE order_number IN ('D4-SNAP','D4-CAP-FULL','D4-PAY-1','D4-PAY-BLOCK') OR order_number LIKE 'D4-CAP-%'");
$pdo->exec("DELETE FROM payment_completion_exceptions WHERE gateway_payment_id LIKE 'd4_gw_%'");
restoreGlobals($pdo, $prevMode ?? 'pincode_legacy', $prevRequireCoords, $prevManualFallback, $prevRoadProvider, $prevDistanceMode);

$regressions = [
    'test-delivery-d1.php' => 'D1',
    'test-delivery-d2.php' => 'D2',
    'test-delivery-d2-hardening.php' => 'D2 hardening',
    'test-delivery-d3.php' => 'D3',
    'test-delivery-d3-hardening.php' => 'D3 hardening',
    'test-delivery-stage8.php' => 'Stage 8',
    'test-payment-stage9.php' => 'Stage 9',
    'test-promotions-stage10.php' => 'Stage 10',
    'test-subscription-stage11.php' => 'Stage 11',
    'test-subscription-stage11-hardening.php' => 'Stage 11 hardening',
    'test-subscription-stage11-production-readiness.php' => 'Stage 11 production readiness',
    'test-trust-stage12.php' => 'Stage 12 A',
    'test-trust-stage12-phase-b.php' => 'Stage 12 B',
];

foreach ($regressions as $script => $label) {
    $path = APNI_TOKRI_ROOT . '/scripts/' . $script;
    if (!is_readable($path)) {
        echo "SKIP: {$label} (missing script)\n";
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

if ($fail > 0) {
    echo "\n{$fail} check(s) failed.\n";
    exit(1);
}

echo "\nPASS: Delivery D4 checkout snapshot & payment revalidation.\n";
exit(0);
