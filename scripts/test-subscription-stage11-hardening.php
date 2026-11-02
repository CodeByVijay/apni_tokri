<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migration 1.6.5.\n");
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

require_once __DIR__ . '/includes/subscription_test_fulfilment_fixture.php';
SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix(
    $pdo,
    SubscriptionTestFulfilmentFixture::STAGE11_HARDENING_SUB_PREFIX
);

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' LIMIT 1'
)->fetchColumn() ?: 0);
ok($variantId > 0, 'Variant');
$pdo->prepare('UPDATE product_variants SET stock_qty = 100 WHERE id = ?')->execute([$variantId]);

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$stmtAddr = $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?');
$stmtAddr->execute([$customerId]);
if ((int) $stmtAddr->fetchColumn() === 0) {
    $pdo->prepare(
        'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default) VALUES (?,?,?,?,?,?,1)'
    )->execute([$customerId, 'Home', 'Line', 'Indore', 'MP', '452001']);
}

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
$snapshot['refund_policy_json'] = [
    'cancelled_day_credit' => 'restore_entitlement',
    'allow_partial_on_substitution_reject' => true,
];
$subNum = SubscriptionTestFulfilmentFixture::STAGE11_HARDENING_SUB_PREFIX . strtoupper(substr(Security::randomToken(4), 0, 6));
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
     price_paid, status, deliveries_remaining, start_date, calendar_end_date, preferred_delivery_slot_slug)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY, ?)'
)->execute([
    $subNum,
    $customerId,
    (int) $plan['id'],
    json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    (float) $plan['price'],
    'active',
    7,
    null,
]);
$subId = (int) $pdo->lastInsertId();
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status, fulfilment_status, preferred_delivery_slot_slug)
     VALUES (?, ?, 1, ?, ?, ?, ?, ?)'
)->execute([$subId, $today, 'scheduled', (new DateTimeImmutable('-1 day', $tz))->format('Y-m-d H:i:s'), 'confirmed', 'none', null]);
$dayId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, confirmed_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
)->execute([$dayId, 'suggested', 'confirmed', 200, 40]);
$basketId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_basket_items (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$basketId, $variantId, 'V', '500g', 'kg', 1, 40, 40]);

$fulfilDeliveryDate = SubscriptionTestFulfilmentFixture::prepareDeliveryDay($pdo, $dayId);
ok($fulfilDeliveryDate !== '', 'Fulfilment delivery date assigned');

$runId = 'hard_' . time();
$gen = SubscriptionFulfilmentService::generateForDay($dayId, $runId);
ok($gen['ok'] === true, 'Fulfilment generation' . (empty($gen['message']) ? '' : ' (' . $gen['message'] . ')'));
$gen2 = SubscriptionFulfilmentService::generateForDay($dayId, $runId);
ok(!empty($gen2['idempotent']), 'Concurrent/idempotent fulfilment');

$orderId = (int) ($pdo->query('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ' . (int) $dayId)->fetchColumn() ?: 0);
$stDel = $pdo->prepare('SELECT id FROM deliveries WHERE order_id = ?');
$stDel->execute([$orderId]);
$delId = (int) ($stDel->fetchColumn() ?: 0);
ok($delId > 0, 'Delivery row');
if ($delId > 0) {
    $remBefore = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subId)->fetchColumn();
    DeliveryAssignmentService::updateStatus($delId, 'delivered', null, 'Test');
    DeliveryAssignmentService::updateStatus($delId, 'delivered', null, 'Duplicate');
    $remAfter = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subId)->fetchColumn();
    ok($remAfter === $remBefore - 1, 'Entitlement consumed once on delivered');
}

$orphanDeliveryDate = SubscriptionTestFulfilmentFixture::reserveDeliveryDate($pdo, $subId, 40);
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status, fulfilment_status)
     VALUES (?, ?, 9, ?, ?, ?, ?)'
)->execute([
    $subId,
    $orphanDeliveryDate,
    'scheduled',
    (new DateTimeImmutable($orphanDeliveryDate . ' 10:00:00', $tz))->modify('-1 day')->format('Y-m-d H:i:s'),
    'confirmed',
    'none',
]);
$orphanDay = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_stock_reservations (subscription_delivery_day_id, variant_id, qty, status) VALUES (?, ?, ?, ?)'
)->execute([$orphanDay, $variantId, 1, 'held']);
$pdo->prepare('UPDATE subscription_stock_reservations SET updated_at = NOW() - INTERVAL 3 HOUR WHERE subscription_delivery_day_id = ?')->execute([$orphanDay]);
$rec = SubscriptionReservationRecoveryService::recoverOrphanedHolds(60);
ok(($rec['released'] ?? 0) >= 1, 'Orphan hold recovery releases stale hold');

$day2DeliveryDate = SubscriptionTestFulfilmentFixture::reserveDeliveryDate($pdo, $subId, 41);
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 2, ?, ?, ?)'
)->execute([
    $subId,
    $day2DeliveryDate,
    'scheduled',
    (new DateTimeImmutable($day2DeliveryDate . ' 10:00:00', $tz))->modify('-1 day')->format('Y-m-d H:i:s'),
    'confirmed',
]);
$day2 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, status, allowance_inr, total_inr, confirmed_at) VALUES (?,?,?,?,NOW())'
)->execute([$day2, 'confirmed', 200, 40]);
$bid2 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_basket_items (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $bid2, $variantId, 'V1', '500g', 'kg', 1, 20, 20,
    $bid2, $variantId, 'V2', '500g', 'kg', 1, 20, 20,
]);
$pdo->prepare(
    'INSERT INTO subscription_basket_substitutions
     (subscription_delivery_day_id, original_variant_id, original_qty, original_unit_price_inr, proposed_variant_id, proposed_qty, proposed_unit_price_inr, price_difference_inr, status)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([$day2, $variantId, 1, 20, $variantId, 1, 50, 30, 'pending_customer']);
$substId = (int) $pdo->lastInsertId();
$dec = SubscriptionSubstitutionService::customerDecision($substId, $customerId, 'reject', 'partial_delivery');
ok($dec['ok'] === true, 'Substitution reject partial delivery');
$stI = $pdo->prepare('SELECT COUNT(*) FROM subscription_delivery_basket_items WHERE basket_id = ?');
$stI->execute([$bid2]);
ok((int) $stI->fetchColumn() === 1, 'Partial delivery leaves remaining line');

$pdo->prepare(
    'INSERT INTO subscription_basket_substitutions
     (subscription_delivery_day_id, original_variant_id, original_qty, original_unit_price_inr, proposed_variant_id, proposed_qty, proposed_unit_price_inr, price_difference_inr, status)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([$day2, $variantId, 1, 20, $variantId, 1, 45, 25, 'pending_customer']);
$substPaid = (int) $pdo->lastInsertId();
$prevActiveGateway = AppSettings::get('payment.active_gateway', 'simulate') ?? 'simulate';
AppSettings::set('payment.active_gateway', 'simulate');
try {
    $payInit = SubscriptionSubstitutionPaymentService::initiateForSubstitution($substPaid, $customerId);
    ok($payInit['ok'] === true && !empty($payInit['checkout']), 'Paid substitution payment initiated');
    $idemp = (string) ($payInit['checkout']['idempotency_key'] ?? '');
    $complete = SubscriptionSubstitutionPaymentService::completePayment($substPaid, 25.0, 'simulate', 'sim_x', 'sim_o', $idemp);
    ok($complete['ok'] === true, 'Paid substitution payment complete');
    $complete2 = SubscriptionSubstitutionPaymentService::completePayment($substPaid, 25.0, 'simulate', 'sim_x2', 'sim_o2', $idemp);
    ok(!empty($complete2['idempotent']), 'Substitution payment retry idempotent');
} finally {
    AppSettings::set('payment.active_gateway', $prevActiveGateway);
}

$day3DeliveryDate = SubscriptionTestFulfilmentFixture::reserveDeliveryDate($pdo, $subId, 42);
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 3, ?, ?, ?)'
)->execute([
    $subId,
    $day3DeliveryDate,
    'scheduled',
    (new DateTimeImmutable($day3DeliveryDate . ' 10:00:00', $tz))->modify('-1 day')->format('Y-m-d H:i:s'),
    'confirmed',
]);
$day3 = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_basket_substitutions
     (subscription_delivery_day_id, original_variant_id, original_qty, original_unit_price_inr, proposed_variant_id, proposed_qty, proposed_unit_price_inr, price_difference_inr, status)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([$day3, $variantId, 1, 40, $variantId, 1, 45, 5, 'pending_customer']);
$substCancel = (int) $pdo->lastInsertId();
$remC = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subId)->fetchColumn();
$decCancel = SubscriptionSubstitutionService::customerDecision($substCancel, $customerId, 'reject', 'cancel_day');
ok($decCancel['ok'] === true, 'Substitution reject cancel day');
$remC2 = (int) $pdo->query('SELECT deliveries_remaining FROM customer_subscriptions WHERE id = ' . $subId)->fetchColumn();
ok($remC2 === $remC + 1, 'Cancel day restores entitlement credit');

$ref = SubscriptionRefundService::reconcilePendingRefunds();
ok(is_int($ref['processed']), 'Refund reconciliation runnable');

echo $fail === 0 ? "\nPASS: Stage 11 hardening.\n" : "\nFAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
