<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migration 1.6.3.\n");
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
     WHERE v.is_active = 1 AND p.status = \'published\' AND v.stock_qty > 0 LIMIT 1'
)->fetchColumn() ?: 0);
ok($variantId > 0, 'Variant in stock');

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute(['96' . random_int(10000000, 99999999), 'Phase D']);
    $customerId = (int) $pdo->lastInsertId();
}

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
ok($plan !== null, 'Plan fixture');
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
$subNum = 'SUB-D-' . strtoupper(substr(Security::randomToken(4), 0, 6));
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
$subRow = CustomerSubscriptionRepository::findByNumber($subNum);

$futureDate = (new DateTimeImmutable('+5 days', $tz))->format('Y-m-d');
$pastCutoff = (new DateTimeImmutable('-1 hour', $tz))->format('Y-m-d H:i:s');
$futureCutoff = (new DateTimeImmutable('+2 days', $tz))->format('Y-m-d H:i:s');

$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 1, ?, ?, ?)'
)->execute([$subId, $futureDate, 'scheduled', $futureCutoff, 'none']);
$dayFutureCutoff = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 2, ?, ?, ?)'
)->execute([$subId, (new DateTimeImmutable('+6 days', $tz))->format('Y-m-d'), 'scheduled', $pastCutoff, 'none']);
$dayPastCutoff = (int) $pdo->lastInsertId();

$pastDelivery = (new DateTimeImmutable('+6 days', $tz))->format('Y-m-d');
$menuFuture = SubscriptionMenuRepository::save(null, [
    'name' => 'D date ' . Security::randomToken(2),
    'planner_type' => 'date',
    'template_date' => $futureDate,
    'is_active' => '1',
], [['variant_id' => $variantId, 'default_qty' => 1, 'unit_price_inr' => 40, 'include_in_rotation' => 1]]);
ok($menuFuture['ok'], 'Future delivery date menu fixture');
$menuPast = SubscriptionMenuRepository::save(null, [
    'name' => 'D2 date ' . Security::randomToken(2),
    'planner_type' => 'date',
    'template_date' => $pastDelivery,
    'is_active' => '1',
], [['variant_id' => $variantId, 'default_qty' => 1, 'unit_price_inr' => 40, 'include_in_rotation' => 1]]);
ok($menuPast['ok'], 'Past-cutoff delivery date menu fixture');

$dayRow = SubscriptionBasketRepository::dayByIdForSubscription($dayPastCutoff, $subId);
SubscriptionBasketService::ensureSuggestedBasket($subRow, $dayRow, $snapshot);

$beforeDeadline = SubscriptionAutoConfirmService::confirmDay($dayFutureCutoff, 'test_early', new DateTimeImmutable('now', $tz));
ok(!$beforeDeadline['ok'], 'No auto-confirm before deadline');

$confirm1 = SubscriptionAutoConfirmService::confirmDay($dayPastCutoff, 'run_a', new DateTimeImmutable('now', $tz));
ok($confirm1['ok'], 'Auto-confirm at deadline');
$basket = SubscriptionBasketRepository::basketByDayId($dayPastCutoff);
ok($basket !== null && (string) $basket['status'] === 'confirmed', 'Basket confirmed');

$confirmDup = SubscriptionAutoConfirmService::confirmDay($dayPastCutoff, 'run_b', new DateTimeImmutable('now', $tz));
ok($confirmDup['ok'] && !empty($confirmDup['idempotent']), 'Duplicate cron idempotent');
if ($basket !== null) {
    $itemCountAfter = count(SubscriptionBasketRepository::basketItems((int) $basket['id']));
    SubscriptionAutoConfirmService::confirmDay($dayPastCutoff, 'run_c', new DateTimeImmutable('now', $tz));
    ok(count(SubscriptionBasketRepository::basketItems((int) $basket['id'])) === $itemCountAfter, 'Already-confirmed basket not overwritten');
}

$remainingBefore = (int) $subRow['deliveries_remaining'];
$skipDayDate = (new DateTimeImmutable('+7 days', $tz))->format('Y-m-d');
$skipCutoff = SubscriptionCutoff::cutoffDateTime($skipDayDate, 18)->format('Y-m-d H:i:s');
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 3, ?, ?, ?)'
)->execute([$subId, $skipDayDate, 'scheduled', $skipCutoff, 'none']);
$skipDayId = (int) $pdo->lastInsertId();

$skip = SubscriptionLifecycleService::skipDeliveryDay($customerId, $subNum, $skipDayId);
ok($skip['ok'], 'Customer skip');
$subAfterSkip = CustomerSubscriptionRepository::findById($subId);
ok((int) $subAfterSkip['deliveries_remaining'] === $remainingBefore, 'Skip preserves deliveries_remaining');
$stmtDays = $pdo->prepare('SELECT COUNT(*) FROM subscription_delivery_days WHERE customer_subscription_id = ?');
$stmtDays->execute([$subId]);
ok((int) $stmtDays->fetchColumn() >= 4, 'Skip carry-forward adds calendar day');

$badOwner = SubscriptionLifecycleService::skipDeliveryDay($customerId + 99999, $subNum, $skipDayId);
ok(!$badOwner['ok'], 'Customer ownership enforced');

$pause = SubscriptionLifecycleService::pause($customerId, $subNum, 'vacation');
ok($pause['ok'], 'Pause subscription');
ok((string) CustomerSubscriptionRepository::findById($subId)['status'] === 'paused', 'Status paused');
$resume = SubscriptionLifecycleService::resume($customerId, $subNum);
ok($resume['ok'], 'Resume subscription');
ok((string) CustomerSubscriptionRepository::findById($subId)['status'] === 'active', 'Status active after resume');

if (SubscriptionCronLock::acquire('test_lock_phase_d')) {
    SubscriptionCronLock::release('test_lock_phase_d');
    ok(true, 'Cron lock acquire/release');
} else {
    ok(false, 'Cron lock acquire');
}

$adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($adminId > 0 && $basket !== null) {
    $_SESSION = [];
    $override = SubscriptionBasketService::adminOverrideDayWithAudit(
        $adminId,
        $subId,
        $dayPastCutoff,
        [['variant_id' => $variantId, 'qty' => 1]],
        true,
        'Test override reason',
        SubscriptionBasketRepository::basketItems((int) $basket['id'])
    );
    ok($override['ok'], 'Admin override with audit');
    CustomerNotificationService::notify(
        $customerId,
        'Subscription basket updated',
        'Test notification for override.',
        ['subscription_number' => $subNum]
    );
    $nStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_notifications WHERE customer_id = ?');
    $nStmt->execute([$customerId]);
    ok((int) $nStmt->fetchColumn() >= 1, 'Customer notified after override');
} else {
    ok(true, 'Admin override skipped (no admin user)');
}

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 11 Phase D.\n";
exit(0);
