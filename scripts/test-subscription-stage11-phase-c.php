<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready — run migration 1.6.2.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;

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
     WHERE v.is_active = 1 AND p.status = \'published\' AND v.stock_qty > 0 ORDER BY v.id LIMIT 1'
)->fetchColumn() ?: 0);
ok($variantId > 0, 'Published in-stock variant for menu/basket tests');

$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
if ($customerId <= 0) {
    $mobile = '97' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$mobile, 'Phase C Test']);
    $customerId = (int) $pdo->lastInsertId();
}
ok($customerId > 0, 'Test customer');

$deliveryDate = (new DateTimeImmutable('+3 days', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('Y-m-d');
$menuWeekday = (new DateTimeImmutable($deliveryDate, new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('N');

$menuSave = SubscriptionMenuRepository::save(null, [
    'name' => 'Test weekly menu ' . Security::randomToken(3),
    'planner_type' => 'weekly',
    'weekday' => $menuWeekday,
    'is_active' => '1',
], [[
    'variant_id' => $variantId,
    'default_qty' => 1,
    'unit_label' => 'kg',
    'unit_price_inr' => 50,
    'include_in_rotation' => 1,
    'max_qty' => 2,
]]);
ok($menuSave['ok'], 'Menu planner CRUD save');

$badMenu = SubscriptionMenuRepository::save(null, [
    'name' => '',
    'planner_type' => 'date',
    'template_date' => '',
], []);
ok(!$badMenu['ok'], 'Menu date validation rejects empty date');

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
ok($plan !== null, 'Plan veg_7');
$planId = (int) ($plan['id'] ?? 0);

$price = (float) $plan['price'];
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, $price);
$subNum = 'SUB-C-' . strtoupper(substr(Security::randomToken(4), 0, 6));
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id,
     plan_snapshot_json, price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE() + INTERVAL 1 DAY, CURDATE() + INTERVAL 14 DAY)'
)->execute([
    $subNum,
    $customerId,
    $planId,
    json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    $price,
    'active',
    (int) $plan['delivery_count'],
]);
$subId = (int) $pdo->lastInsertId();
$cutoff = SubscriptionCutoff::cutoffDateTime($deliveryDate, SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot))->format('Y-m-d H:i:s');
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 1, ?, ?, ?)'
)->execute([$subId, $deliveryDate, 'scheduled', $cutoff, 'none']);
$dayId = (int) $pdo->lastInsertId();

$subRow = CustomerSubscriptionRepository::findByNumber($subNum);
$dayRow = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subId);
ok($subRow !== null && $dayRow !== null, 'Fixture subscription day');

$gen = SubscriptionBasketService::ensureSuggestedBasket($subRow, $dayRow, $snapshot);
ok($gen['ok'] && count($gen['items'] ?? []) >= 1, 'Suggested basket generation');

$wrong = CustomerSubscriptionRepository::findByNumberForCustomer($subNum, $customerId + 99999);
ok($wrong === null, 'Unauthorized subscription access blocked');

$save = SubscriptionBasketService::saveCustomerDays($customerId, $subNum, [
    $dayId => ['lines' => [['variant_id' => $variantId, 'qty' => 1]]],
], false);
ok($save['ok'], 'Customer multi-day save draft');

$over = SubscriptionBasketService::saveCustomerDays($customerId, $subNum, [
    $dayId => ['lines' => [['variant_id' => $variantId, 'qty' => 20]]],
], true);
ok(!$over['ok'], 'Allowance / validation blocks excessive basket (no silent charge)');

$pdo->prepare(
    'UPDATE subscription_delivery_days SET selection_cutoff_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?'
)->execute([$dayId]);
$lockedDay = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subId);
ok($lockedDay !== null && SubscriptionCutoff::isLocked($lockedDay), 'Cutoff enforcement');
$afterCutoff = SubscriptionBasketService::saveCustomerDays($customerId, $subNum, [
    $dayId => ['lines' => [['variant_id' => $variantId, 'qty' => 1]]],
], false);
ok(!$afterCutoff['ok'], 'Changes blocked after cutoff');

$auditStmt = $pdo->prepare('SELECT COUNT(*) FROM subscription_selection_audit WHERE customer_subscription_id = ?');
$auditStmt->execute([$subId]);
$auditCount = (int) $auditStmt->fetchColumn();
ok($auditCount >= 1, 'Audit trail entries');

$oldPrice = (float) CustomerSubscriptionRepository::decodeSnapshot($subRow)['price_paid'];
$pdo->prepare('UPDATE subscription_plans SET price = price + 999 WHERE id = ?')->execute([$planId]);
ok((float) CustomerSubscriptionRepository::decodeSnapshot($subRow)['price_paid'] === $oldPrice, 'Plan snapshot immutable');

if ($fail > 0) {
    echo "\n{$fail} failed.\n";
    exit(1);
}
echo "\nPASS: Stage 11 Phase C.\n";
exit(0);
