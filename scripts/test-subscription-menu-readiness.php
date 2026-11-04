<?php

declare(strict_types=1);

/**
 * Subscription Menu Planner production-readiness: skipped basket feedback, duplicate POST guard, make-live conflict.
 * Run: php7.3 scripts/run-in-disposable-db.php scripts/test-subscription-menu-readiness.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

function applyMigration127(PDO $pdo): void
{
    if (SubscriptionBasketService::generationSkippedColumnReady()) {
        return;
    }
    $pdo->exec(
        'ALTER TABLE `subscription_delivery_baskets`
         ADD COLUMN `generation_skipped_json` TEXT NULL DEFAULT NULL COMMENT \'JSON array of skipped menu lines at generation time\' AFTER `total_inr`'
    );
}

applyMigration127($pdo);
t(SubscriptionBasketService::generationSkippedColumnReady(), 'Migration 1.27 generation_skipped_json column');

$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' AND v.stock_qty > 0 ORDER BY v.id LIMIT 1'
)->fetchColumn() ?: 0);
t($variantId > 0, 'Fixture variant');

$oosId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.status = \'published\' AND v.id <> ' . (int) $variantId . ' ORDER BY v.id LIMIT 1'
)->fetchColumn() ?: 0);
if ($oosId > 0) {
    $pdo->exec('UPDATE product_variants SET stock_qty = 0 WHERE id = ' . (int) $oosId);
}

$tok = bin2hex(random_bytes(3));
$deliveryDate = (new DateTimeImmutable('+5 days', new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('Y-m-d');
$weekday = (new DateTimeImmutable($deliveryDate, new DateTimeZone(SubscriptionScheduleGenerator::TZ)))->format('N');

$menuSave = SubscriptionMenuRepository::save(null, [
    'name' => 'Readiness weekly ' . $tok,
    'planner_type' => 'weekly',
    'weekday' => $weekday,
    'is_active' => '1',
], [
    ['variant_id' => $variantId, 'default_qty' => 1, 'unit_label' => 'kg', 'unit_price_inr' => 40, 'include_in_rotation' => 1, 'max_qty' => 2],
    ['variant_id' => $oosId, 'default_qty' => 1, 'unit_label' => 'kg', 'unit_price_inr' => 30, 'include_in_rotation' => 1, 'max_qty' => 2],
]);
t($menuSave['ok'], 'Weekly menu with in-stock and OOS lines');

$plan = SubscriptionPlanRepository::findByTemplateKey('veg_7');
t($plan !== null, 'veg_7 plan');
$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn());
$subNum = 'SUB-RD-' . strtoupper($tok);
$snapshot = SubscriptionPlanRepository::toSnapshotArray($plan, (float) $plan['price']);
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id,
     plan_snapshot_json, price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL 14 DAY)'
)->execute([$subNum, $customerId, (int) $plan['id'], json_encode($snapshot), (float) $plan['price'], 'active', 7]);
$subId = (int) $pdo->lastInsertId();
$cutoff = SubscriptionCutoff::cutoffDateTime($deliveryDate, SubscriptionCutoff::cutoffHoursFromSnapshot($snapshot))->format('Y-m-d H:i:s');
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status, selection_cutoff_at, basket_status)
     VALUES (?, ?, 1, ?, ?, ?)'
)->execute([$subId, $deliveryDate, 'scheduled', $cutoff, 'none']);
$dayId = (int) $pdo->lastInsertId();

$subRow = CustomerSubscriptionRepository::findByNumber($subNum);
$dayRow = SubscriptionBasketRepository::dayByIdForSubscription($dayId, $subId);
$gen = SubscriptionBasketService::ensureSuggestedBasket($subRow, $dayRow, $snapshot);
t(!empty($gen['ok']) && count($gen['items'] ?? []) >= 1, 'Suggested basket generated with available lines');
$skipped = $gen['skipped'] ?? [];
t($skipped !== [], 'Skipped menu lines recorded');
$hasOos = false;
foreach ($skipped as $s) {
    if (stripos((string) ($s['reason'] ?? ''), 'stock') !== false) {
        $hasOos = true;
    }
}
t($hasOos, 'Out-of-stock menu line listed in skipped feedback');

$basket = SubscriptionBasketRepository::basketByDayId($dayId);
$stored = is_string($basket['generation_skipped_json'] ?? null) && $basket['generation_skipped_json'] !== '';
t($stored, 'Skipped lines persisted on basket row');

$built = SubscriptionMenuPlanService::findActiveForSubscriptionPlan((int) $plan['id'], null);
// findActive may be null on disposable DB — test service method with two drafts
$payload = static function (string $name, int $subId) use ($variantId): array {
    return [
        'name' => $name,
        'duration_days' => 7,
        'subscription_plan_id' => $subId,
        'repeat_mode' => 'none',
        'is_active' => false,
        'days' => [1 => ['customized' => false, 'items' => [['variant_id' => $variantId, 'qty' => 1, 'max_qty' => null]]]],
    ];
};
$a = SubscriptionMenuPlanService::save(null, $payload('Menu A ' . $tok, (int) $plan['id']));
$b = SubscriptionMenuPlanService::save(null, $payload('Menu B ' . $tok, (int) $plan['id']));
SubscriptionMenuPlanService::setActive((int) $a['id'], true);
$live = SubscriptionMenuPlanService::findActiveForSubscriptionPlan((int) $plan['id'], (int) $b['id']);
t($live !== null && (int) $live['id'] === (int) $a['id'], 'findActiveForSubscriptionPlan returns other live menu');

$menuPlanner = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/subscriptions/menu-planner.php');
t(strpos($menuPlanner, 'findActiveForSubscriptionPlan') !== false && strpos($menuPlanner, 'confirm(') !== false, 'Make live list UI uses conflict confirm');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/admin/subscriptions/menu-plan.php'), 'MenuPlanSubmitGuard') !== false, 'Menu plan save uses submit guard');
t(strpos((string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/menu-planner.js'), 'submit_token') !== false, 'Wizard posts submit_token');

$pdo->exec('DELETE FROM subscription_delivery_days WHERE customer_subscription_id = ' . (int) $subId);
$pdo->exec('DELETE FROM customer_subscriptions WHERE id = ' . (int) $subId);
$pdo->exec('DELETE FROM subscription_menu_templates WHERE name LIKE ' . $pdo->quote('%' . $tok . '%'));
$pdo->exec('DELETE FROM subscription_menu_plans WHERE name LIKE ' . $pdo->quote('%' . $tok . '%'));

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
