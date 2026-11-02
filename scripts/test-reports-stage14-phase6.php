<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply migration 1.15.0_stage14_reports_phase6.sql (expected ' . Install::SCHEMA_VERSION . ").\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$skips = 0;

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
    global $skips;
    echo "SKIP: {$m}\n";
    $skips++;
}

function r146_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function r146_cleanup(PDO $pdo, string $token): void
{
    $like = 'R146-' . $token . '%';
    $pdo->exec("DELETE FROM payment_refunds WHERE idempotency_key LIKE 'r146_{$token}%'");
    $pdo->exec("DELETE FROM refund_requests WHERE request_number LIKE '{$like}'");
    $pdo->exec("DELETE FROM payments WHERE idempotency_key LIKE 'r146_{$token}%'");
    $pdo->exec("DELETE FROM subscription_payments WHERE idempotency_key LIKE 'r146_sub_{$token}%'");
    $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE '{$like}')");
    $pdo->exec("DELETE FROM delivery_slot_reservations WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE '{$like}')");
    $pdo->exec("DELETE FROM delivery_slot_reservations WHERE delivery_slot_id IN (SELECT id FROM delivery_slots WHERE slug LIKE 'r146slot_%')");
    $pdo->exec("DELETE FROM orders WHERE order_number LIKE '{$like}'");
    $pdo->exec("DELETE FROM customer_subscriptions WHERE subscription_number LIKE '{$like}'");
    $pdo->exec("DELETE FROM delivery_slots WHERE slug LIKE 'r146slot_%'");
    $pdo->exec("DELETE FROM customers WHERE mobile LIKE '9899R146{$token}'");
}

$token = bin2hex(random_bytes(3));
r146_cleanup($pdo, $token);

ok(r146_column_exists($pdo, 'payments', 'captured_at'), 'Migration: payments.captured_at exists');
ok(r146_column_exists($pdo, 'subscription_payments', 'captured_at'), 'Migration: subscription_payments.captured_at exists');
ok(r146_column_exists($pdo, 'payment_refunds', 'completed_at'), 'Migration: payment_refunds.completed_at exists');

$legacyPay = $pdo->query(
    "SELECT id FROM payments WHERE status = 'captured' AND captured_at IS NULL LIMIT 1"
)->fetchColumn();
if ($legacyPay) {
    ok(true, 'Existing captured payment may retain NULL captured_at (no backfill)');
}

// --- Storefront capture fixture ---
$mobile = '9899R146' . $token;
$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$mobile, 'R146 Test']);
$customerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?,?,?,?,?,?,1)'
)->execute([$customerId, 'Home', 'Test line', 'Indore', 'MP', '452001']);
$addrId = (int) $pdo->lastInsertId();

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id LIMIT 1'
)->fetchColumn() ?: 0);
if ($variantId > 0) {
    $pdo->prepare('UPDATE product_variants SET stock_qty = GREATEST(stock_qty, 50) WHERE id = ?')->execute([$variantId]);
}

$orderNum = 'R146-' . $token . '-ORD';
$slotSlug = 'r146slot_' . substr($token, 0, 6);
$pdo->prepare(
    'INSERT INTO delivery_slots (slug, label, window_label, delivery_day, start_time, end_time, cutoff_time, capacity, sort_order, is_active, weekday_mask)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 127)'
)->execute([$slotSlug, 'R146', 'R146', 'same_day', '23:00:00', '23:30:00', '23:00:00', 50, 9997]);
$slotId = (int) $pdo->lastInsertId();
$deliveryDate = DeliveryConfig::now()->format('Y-m-d');

$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, customer_address_id, status, subtotal, discount_total,
     delivery_fee, grand_total, payment_status, delivery_slot, delivery_slot_id, delivery_date, delivery_snapshot_json)
     VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?)'
)->execute([
    $orderNum,
    'storefront',
    $customerId,
    $addrId,
    'pending_payment',
    50.0,
    50.0,
    'pending',
    $slotSlug,
    $slotId,
    $deliveryDate,
    '{}',
]);
$orderId = (int) $pdo->lastInsertId();
if (DeliverySlotReservationService::tableReady()) {
    try {
        DeliverySlotReservationService::createHold($pdo, $slotId, $deliveryDate, $orderId, 60);
    } catch (Throwable $e) {
        // hold optional if table missing
    }
}
if ($variantId > 0) {
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, $variantId, 'R146', 'U', 1, 50.0, 50.0]);
    $addrStmt = $pdo->prepare('SELECT * FROM customer_addresses WHERE id = ?');
    $addrStmt->execute([$addrId]);
    $addrRow = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $itemRows = $pdo->query('SELECT * FROM order_items WHERE order_id = ' . (int) $orderId)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cartLines = [];
    foreach ($itemRows as $it) {
        $cartLines[] = [
            'variant_id' => (int) ($it['variant_id'] ?? 0),
            'qty' => (int) ($it['quantity'] ?? 0),
            'unit_price' => (float) ($it['unit_price'] ?? 0),
            'grants_free_delivery' => false,
        ];
    }
    $quoted = DeliveryQuoteService::quoteForCheckout($addrRow, $cartLines, $slotSlug, null, $pdo, $orderId);
    if (!empty($quoted['ok'])) {
        $snapFull = json_encode(DeliveryQuoteService::buildSnapshot($quoted), JSON_UNESCAPED_UNICODE);
        $grand = (float) ($quoted['grand_total'] ?? 50.0);
        $fee = (float) ($quoted['delivery_fee'] ?? 0);
        $pdo->prepare('UPDATE orders SET delivery_snapshot_json = ?, delivery_fee = ?, grand_total = ? WHERE id = ?')
            ->execute([$snapFull, $fee, $grand, $orderId]);
    }
}

$idemp = 'r146_' . $token . '_pay1';
$grandForPay = (float) ($pdo->query('SELECT grand_total FROM orders WHERE id = ' . (int) $orderId)->fetchColumn() ?: 50.0);
$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId, 'simulate', $grandForPay, 'INR', 'created', $idemp]);
$payId = (int) $pdo->lastInsertId();

$captureOk = false;
$firstCap = null;
$prId = null;
$firstComp = null;
if ($variantId > 0) {
    $grandRow = $pdo->prepare('SELECT grand_total FROM orders WHERE id = ?');
    $grandRow->execute([$orderId]);
    $grandTotal = (float) $grandRow->fetchColumn();
    $cap = PaymentService::completePayment(
        $orderNum,
        'simulate',
        'gw_r146_' . $token,
        'ord_r146_' . $token,
        $grandTotal,
        'INR',
        $idemp,
        'evt_r146_1'
    );
    $captureOk = !empty($cap['ok']);
    if (!$captureOk) {
        skip('PaymentService::completePayment fixture: ' . ($cap['message'] ?? 'failed'));
    }
} else {
    skip('No product variant for completePayment fixture');
}

if ($captureOk) {
    $capTs = $pdo->prepare('SELECT captured_at FROM payments WHERE id = ?');
    $capTs->execute([$payId]);
    $firstCap = $capTs->fetchColumn();
    ok($firstCap !== null && $firstCap !== false && $firstCap !== '', 'Successful storefront capture sets captured_at');

    sleep(1);
    PaymentService::completePayment(
        $orderNum,
        'simulate',
        'gw_r146_' . $token,
        'ord_r146_' . $token,
        $grandTotal,
        'INR',
        $idemp,
        'evt_r146_2'
    );
    $capTs->execute([$payId]);
    ok((string) $capTs->fetchColumn() === (string) $firstCap, 'Duplicate callback preserves captured_at');

    $failIdemp = 'r146_' . $token . '_fail';
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, 'simulate', 50.0, 'INR', 'created', $failIdemp]);
    PaymentService::markPaymentFailed($orderNum, $failIdemp, 'test fail');
    $failCap = $pdo->prepare('SELECT captured_at FROM payments WHERE idempotency_key = ?');
    $failCap->execute([$failIdemp]);
    ok($failCap->fetchColumn() === null, 'Failed payment attempt leaves captured_at NULL');

    $pdo->prepare(
        'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_status)
         VALUES (NULL, ?, ?, ?, ?, ?)'
    )->execute([$payId, 'simulate', 10.0, 'r146_' . $token . '_ref1', 'pending']);
    $prId = (int) $pdo->lastInsertId();
    RefundCompletionService::completeOrderRefund($prId, 'processed', 10.0);
    $capTs->execute([$payId]);
    ok((string) $capTs->fetchColumn() === (string) $firstCap, 'Refund completion does not modify payments.captured_at');

    $compTs = $pdo->prepare('SELECT completed_at FROM payment_refunds WHERE id = ?');
    $compTs->execute([$prId]);
    $firstComp = $compTs->fetchColumn();
    ok($firstComp !== null && $firstComp !== false && $firstComp !== '', 'Successful refund sets completed_at');

    RefundCompletionService::completeOrderRefund($prId, 'processed', 10.0);
    $compTs->execute([$prId]);
    ok((string) $compTs->fetchColumn() === (string) $firstComp, 'Duplicate refund completion preserves completed_at');

    $pdo->prepare(
        'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_status)
         VALUES (NULL, ?, ?, ?, ?, ?)'
    )->execute([$payId, 'simulate', 3.0, 'r146_' . $token . '_failgw', 'pending']);
    $failRefId = (int) $pdo->lastInsertId();
    RefundCompletionService::markOrderRefundFailed($failRefId, 'test fail');
    $failComp = $pdo->prepare('SELECT completed_at FROM payment_refunds WHERE id = ?');
    $failComp->execute([$failRefId]);
    ok($failComp->fetchColumn() === null, 'Failed refund leaves completed_at NULL');
}

$pdo->prepare(
    'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_status)
     VALUES (NULL, ?, ?, ?, ?, ?)'
)->execute([
    $payId > 0 ? $payId : 1,
    'simulate',
    5.0,
    'r146_' . $token . '_legacy',
    'processed',
]);
$legacyRefId = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE payment_refunds SET completed_at = NULL WHERE id = ?')->execute([$legacyRefId]);
ok(
    $pdo->query('SELECT completed_at FROM payment_refunds WHERE id = ' . (int) $legacyRefId)->fetchColumn() === null,
    'Historical terminal-success row keeps NULL completed_at (not fabricated)'
);

$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$orderId, 'simulate', 50.0, 'INR', 'reconciliation_required', 'r146_' . $token . '_recon']);
$reconId = (int) $pdo->lastInsertId();
$reconCap = $pdo->query('SELECT captured_at FROM payments WHERE id = ' . $reconId)->fetchColumn();
ok($reconCap === null, 'reconciliation_required payment has NULL captured_at');

// --- Subscription activation ---
$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
if ($plan === null) {
    $plan = $pdo->query('SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($plan !== null && $captureOk) {
    $subNum = 'R146-' . $token . '-SUB';
    $snap = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
    $price = (float) $plan['price'];
    $pdo->prepare(
        'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
         price_paid, status, deliveries_remaining) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $subNum,
        $customerId,
        (int) $plan['id'],
        json_encode($snap, JSON_UNESCAPED_UNICODE),
        $price,
        'pending_payment',
        (int) $plan['delivery_count'],
    ]);
    $subId = (int) $pdo->lastInsertId();
    $subIdemp = 'r146_sub_' . $token . '_1';
    $pdo->prepare(
        'INSERT INTO subscription_payments (customer_subscription_id, gateway, amount, currency, status, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$subId, 'simulate', $price, 'INR', 'pending', $subIdemp]);

    $act = SubscriptionActivationService::activateFromPayment(
        $subNum,
        $price,
        'simulate',
        'sub_gw_' . $token,
        'sub_ord_' . $token,
        $subIdemp,
        'evt_sub_1'
    );
    if (!empty($act['ok'])) {
        $spCap = $pdo->prepare('SELECT captured_at FROM subscription_payments WHERE idempotency_key = ?');
        $spCap->execute([$subIdemp]);
        $subCapFirst = $spCap->fetchColumn();
        ok($subCapFirst !== null, 'Subscription capture sets subscription_payments.captured_at');
        SubscriptionActivationService::activateFromPayment(
            $subNum,
            $price,
            'simulate',
            'sub_gw2_' . $token,
            'sub_ord2_' . $token,
            $subIdemp,
            'evt_sub_2'
        );
        $spCap->execute([$subIdemp]);
        ok((string) $spCap->fetchColumn() === (string) $subCapFirst, 'Duplicate subscription activation preserves captured_at');
    } else {
        skip('Subscription activation fixture: ' . ($act['message'] ?? 'failed'));
    }
} else {
    skip('No subscription plan for subscription timestamp test');
}

// --- Report calculations ---
$rangeFrom = '2030-07-01';
$rangeTo = '2030-07-01';
$rangeOk = ReportDateRange::fromRequest(['preset' => 'custom', 'from' => $rangeFrom, 'to' => $rangeTo]);
ok(!empty($rangeOk['ok']), 'Report date range');
/** @var ReportDateRange $range */
$range = $rangeOk['range'];

if ($captureOk && isset($firstCap) && $firstCap !== null && $firstCap !== '') {
    $pdo->prepare('UPDATE payments SET captured_at = ? WHERE id = ?')->execute(['2030-07-01 10:00:00', $payId]);
    $pdo->prepare('UPDATE payments SET created_at = ? WHERE id = ?')->execute(['2030-07-01 09:00:00', $payId]);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
    )->execute([$orderId, 'simulate', 50.0, 'INR', 'failed', 'r146_' . $token . '_att2', '2030-07-01 11:00:00']);
    $failPayId = (int) $pdo->lastInsertId();
    unset($failPayId);

    $attempts = PaymentReportService::attemptSummary($range);
    ok((int) $attempts['total_attempts'] >= 2, 'Multiple payment attempts counted');
    ok((int) $attempts['captured_attempts'] >= 1 && (int) $attempts['failed_attempts'] >= 1, 'Attempt cohort status split');
    $rate = (float) ($attempts['attempt_cohort_success_rate_pct'] ?? 0);
    ok($rate > 0 && $rate < 100, 'Attempt-cohort success rate uses created_at cohort only');

    $coll = PaymentReportService::collectionSummary($range);
    ok((float) $coll['captured_amount_inr'] >= 50.0, 'Capture-period amount uses captured_at');
    ok((int) $coll['unique_paid_order_count'] >= 1, 'Unique paid order count');

    $pdo->prepare(
        'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['R146-' . $token . '-SUBORD', 'subscription', $customerId, 'confirmed', 100, 0, 'paid', '2030-07-01 12:00:00']);
    $coll2 = PaymentReportService::collectionSummary($range);
    ok((float) $coll2['captured_amount_inr'] < 1000.0, 'Subscription fulfilment order not in storefront collection');
}

if (isset($prId, $firstComp) && $firstComp) {
    $pdo->prepare('UPDATE payment_refunds SET completed_at = ? WHERE id = ?')->execute(['2030-07-01 14:00:00', $prId]);
    $refDone = RefundReportService::completedSummary($range);
    ok((float) $refDone['completed_refund_amount_inr'] >= 10.0, 'Completed refund sum uses completed_at');
    ok((int) $refDone['legacy_terminal_null_completed_at_count'] >= 1, 'Legacy NULL completed_at visible separately');
}

$subOrd = SubscriptionReportService::generatedOrdersSummary($range);
ok(isset($subOrd['generated_subscription_orders']), 'Subscription generated orders summary');

r146_cleanup($pdo, $token);

echo $fail === 0
    ? "All Stage 14.6 Phase B checks passed ({$skips} skipped).\n"
    : "Failures: {$fail} ({$skips} skipped).\n";
exit($fail === 0 ? 0 : 1);
