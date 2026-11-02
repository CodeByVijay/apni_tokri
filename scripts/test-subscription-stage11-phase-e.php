<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migration 1.6.4.\n");
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

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' LIMIT 1'
)->fetchColumn() ?: 0);
ok($variantId > 0, 'Variant fixture');
$pdo->prepare('UPDATE product_variants SET stock_qty = 50 WHERE id = ?')->execute([$variantId]);

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['97' . random_int(10000000, 99999999), 'Phase E']);
    $customerId = (int) $pdo->lastInsertId();
}
$stmtAddr = $pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?');
$stmtAddr->execute([$customerId]);
if ((int) $stmtAddr->fetchColumn() === 0) {
    $pdo->prepare(
        'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    )->execute([$customerId, 'Home', 'Test line', 'Indore', 'MP', '452001']);
}

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
ok($plan !== null, 'Plan fixture');
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
$subNum = 'SUB-E-' . strtoupper(substr(Security::randomToken(4), 0, 6));
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
     price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 30 DAY)'
)->execute([
    $subNum,
    $customerId,
    (int) $plan['id'],
    json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    (float) $plan['price'],
    'active',
    (int) $plan['delivery_count'],
]);
$subId = (int) $pdo->lastInsertId();

$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status, fulfilment_status)
     VALUES (?, ?, 1, ?, ?, ?, ?)'
)->execute([$subId, $today, 'scheduled', (new DateTimeImmutable('-1 day', $tz))->format('Y-m-d H:i:s'), 'confirmed', 'none']);
$dayId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr, confirmed_at)
     VALUES (?, ?, ?, ?, ?, NOW())'
)->execute([$dayId, 'suggested', 'confirmed', 200, 40]);
$basketId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_basket_items
     (basket_id, variant_id, product_name, variant_name, unit_label, qty, unit_price_inr, line_total_inr)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$basketId, $variantId, 'Test veg', '500g', 'kg', 1, 40, 40]);

require_once __DIR__ . '/includes/subscription_test_fulfilment_fixture.php';
SubscriptionTestFulfilmentFixture::prepareDeliveryDay($pdo, $dayId);

$runId = 'test_e_' . time();
$gen = SubscriptionFulfilmentService::generateForDay($dayId, $runId);
ok($gen['ok'] === true && !empty($gen['order_number']), 'Generate order from confirmed basket');

$gen2 = SubscriptionFulfilmentService::generateForDay($dayId, $runId);
ok(!empty($gen2['idempotent']), 'Idempotent second generation');

$orderId = (int) ($pdo->prepare('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ?')->execute([$dayId]) ? 0 : 0);
$stmtO = $pdo->prepare('SELECT generated_order_id FROM subscription_delivery_days WHERE id = ?');
$stmtO->execute([$dayId]);
$orderId = (int) $stmtO->fetchColumn();
ok($orderId > 0, 'Day linked to order');
$stmtPay = $pdo->prepare('SELECT payment_status, order_source, subscription_delivery_day_id FROM orders WHERE id = ?');
$stmtPay->execute([$orderId]);
$ord = $stmtPay->fetch(PDO::FETCH_ASSOC);
ok($ord && $ord['payment_status'] === 'paid' && $ord['order_source'] === 'subscription', 'Prepaid subscription order');
ok((int) $ord['subscription_delivery_day_id'] === $dayId, 'Traceability day id on order');

$delCount = (int) $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE order_id = ?')->execute([$orderId]) ? 0 : 0;
$stmtD = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE order_id = ?');
$stmtD->execute([$orderId]);
ok((int) $stmtD->fetchColumn() === 1, 'Delivery row created');

$dayRow = $pdo->prepare('SELECT fulfilment_status FROM subscription_delivery_days WHERE id = ?');
$dayRow->execute([$dayId]);
ok((string) $dayRow->fetchColumn() === 'generated', 'Fulfilment status generated not delivered');

$notif = (int) $pdo->prepare('SELECT COUNT(*) FROM subscription_notification_log WHERE notification_type = ? AND reference_key = ?')
    ->execute(['subscription_order_generated', 'day_' . $dayId . '_order']) ? 0 : 0;
$stmtN = $pdo->prepare('SELECT COUNT(*) FROM subscription_notification_log WHERE notification_type = ? AND reference_key = ?');
$stmtN->execute(['subscription_order_generated', 'day_' . $dayId . '_order']);
ok((int) $stmtN->fetchColumn() === 1, 'Deduped generation notification');

echo $fail === 0 ? "\nPASS: Stage 11 Phase E.\n" : "\nFAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
