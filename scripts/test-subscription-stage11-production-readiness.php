<?php

declare(strict_types=1);

/**
 * Stage 11 production-readiness checks (simulated + unit; live Razorpay NOT TESTED unless env configured).
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$tz = new DateTimeZone(SubscriptionScheduleGenerator::TZ);

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function section(string $title): void
{
    echo "\n--- $title ---\n";
}

section('1. Razorpay substitution verification (simulated/unit)');
$variantId = (int) ($pdo->query('SELECT v.id FROM product_variants v LIMIT 1')->fetchColumn() ?: 0);
$customerId = (int) ($pdo->query('SELECT id FROM customers LIMIT 1')->fetchColumn() ?: 0);
$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json, price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY)'
)->execute(['SUB-PV-' . time(), $customerId, (int) $plan['id'], json_encode($snapshot), (float) $plan['price'], 'active', 5]);
$subId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 1, ?, ?, ?)'
)->execute([$subId, (new DateTimeImmutable('+3 days', $tz))->format('Y-m-d'), 'scheduled', (new DateTimeImmutable('+2 days', $tz))->format('Y-m-d H:i:s'), 'confirmed']);
$dayId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_basket_substitutions (subscription_delivery_day_id, original_variant_id, original_qty, original_unit_price_inr, proposed_variant_id, proposed_qty, proposed_unit_price_inr, price_difference_inr, status)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([$dayId, $variantId, 1, 10, $variantId, 1, 25, 15, 'pending_customer']);
$substId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_substitution_payments (substitution_id, customer_subscription_id, gateway, amount, currency, status, idempotency_key, gateway_order_id)
     VALUES (?,?,?,?,?,?,?,?)'
)->execute([$substId, $subId, 'razorpay', 15, 'INR', 'pending', 'substpay_test_' . $substId, 'order_subst_test']);
$badOrder = SubscriptionSubstitutionPaymentService::verifyRazorpayCapture(
    $substId,
    $customerId,
    'wrong_order',
    'pay_x',
    'substpay_test_' . $substId
);
ok($badOrder['ok'] === false, 'Gateway order mismatch rejected');
$cap = SubscriptionSubstitutionPaymentService::verifyRazorpayCapture(
    $substId,
    $customerId,
    'order_subst_test',
    'pay_test_123',
    'substpay_test_' . $substId
);
ok($cap['ok'] === true, 'verifyRazorpayCapture accepts valid pending payment');
$cap2 = SubscriptionSubstitutionPaymentService::verifyRazorpayCapture(
    $substId,
    $customerId,
    'order_subst_test',
    'pay_test_456',
    'substpay_test_' . $substId
);
ok(!empty($cap2['idempotent']), 'Duplicate verify does not double-apply basket');

section('2. Refund production safety');
ok(SubscriptionRefundService::gatewayRefundAllowed() === GatewayRefundService::liveGatewayRefundsEnabled(), 'Gateway refund allowed matches config');
$reconLive = SubscriptionRefundService::reconcilePendingRefunds();
if (PaymentGatewayConfig::useSimulatedCheckout()) {
    ok(is_int($reconLive['processed']), 'Simulated reconcile runs in simulate mode');
} else {
    ok(($reconLive['processed'] ?? -1) === 0, 'Live mode does not auto-complete refunds');
}

section('3. Delivery entitlement transitions');
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json, price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY)'
)->execute(['SUB-ENT-' . time(), $customerId, (int) $plan['id'], json_encode($snapshot), (float) $plan['price'], 'active', 5]);
$subEnt = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, basket_status, fulfilment_status, generated_order_id)
     VALUES (?, ?, 1, ?, ?, ?, NULL)'
)->execute([$subEnt, $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d'), 'scheduled', 'confirmed', 'generated']);
$dayEnt = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, subscription_delivery_day_id, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot, placed_at)
     VALUES (?, ?, ?, ?, ?, 0,0,0,0, ?, ?, NOW())'
)->execute(['AT-ENT-' . time(), 'subscription', $dayEnt, $customerId, 'confirmed', 'paid', 'auto']);
$ordEnt = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE subscription_delivery_days SET generated_order_id = ? WHERE id = ?')->execute([$ordEnt, $dayEnt]);
$pdo->prepare('INSERT INTO deliveries (order_id, status) VALUES (?, ?)')->execute([$ordEnt, 'pending_dispatch']);
$delEnt = (int) $pdo->lastInsertId();
$rem0 = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subEnt)->fetchColumn();
DeliveryAssignmentService::updateStatus($delEnt, 'delivered', null, 't1');
DeliveryAssignmentService::updateStatus($delEnt, 'delivered', null, 't2');
$rem1 = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subEnt)->fetchColumn();
ok($rem1 === $rem0 - 1, 'Delivered decrements once (repeat delivered ignored)');
DeliveryAssignmentService::updateStatus($delEnt, 'failed', null, 'fail');
DeliveryAssignmentService::updateStatus($delEnt, 'failed', null, 'fail2');
$rem2 = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subEnt)->fetchColumn();
ok($rem2 === $rem0, 'Delivered→failed restores once');
$pdo->prepare('UPDATE deliveries SET status = ? WHERE id = ?')->execute([DeliveryStatus::PENDING_DISPATCH, $delEnt]);
DeliveryAssignmentService::updateStatus($delEnt, 'delivered', null, 'again');
$rem3 = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subEnt)->fetchColumn();
ok($rem3 === $rem0 - 1, 'Failed→delivered decrements once again');

section('4. Cron CLI-only');
foreach (glob(dirname(__DIR__) . '/cron/subscription*.php') as $cronFile) {
    $src = (string) file_get_contents($cronFile);
    ok(strpos($src, "PHP_SAPI !== 'cli'") !== false, basename($cronFile) . ' is CLI guarded');
}

section('5. Production configuration');
$liveReady = PaymentGatewayConfig::assertLivePaymentsReady();
ok(is_bool($liveReady['ok']), 'assertLivePaymentsReady returns structured result');
ok(PaymentGatewayConfig::currency() === 'INR', 'Currency is INR');

section('6. Live Razorpay substitution (environment)');
if (PaymentGatewayConfig::isProductionGatewayMode() && RazorpayGateway::isConfigured()) {
    echo "NOT TESTED: Live Razorpay substitution E2E (requires manual test-mode checkout).\n";
} else {
    echo "NOT TESTED: Live Razorpay substitution (simulate mode or credentials not configured).\n";
}

echo $fail === 0 ? "\nPASS: production readiness (automated).\n" : "\nFAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
