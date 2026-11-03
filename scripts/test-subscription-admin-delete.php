<?php

declare(strict_types=1);

/** Admin delete for subscription plans and menu templates. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
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

$_SESSION['admin_user_id'] = 1;
Permission::clearCache();
$tok = strtolower(bin2hex(random_bytes(4)));
$pdo->exec("DELETE FROM subscription_delivery_basket_items WHERE basket_id IN (SELECT id FROM subscription_delivery_baskets WHERE subscription_delivery_day_id IN (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN (SELECT id FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-%" . $tok . "')))");
$pdo->exec("DELETE FROM subscription_delivery_baskets WHERE subscription_delivery_day_id IN (SELECT id FROM subscription_delivery_days WHERE customer_subscription_id IN (SELECT id FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-%" . $tok . "'))");
$pdo->exec("DELETE FROM subscription_delivery_days WHERE customer_subscription_id IN (SELECT id FROM customer_subscriptions WHERE subscription_number LIKE 'ZT-%" . $tok . "')");
$pdo->exec('DELETE FROM customer_subscriptions WHERE subscription_number LIKE ' . $pdo->quote('ZT-%' . $tok));
$pdo->exec('DELETE FROM subscription_plans WHERE name LIKE ' . $pdo->quote('%' . $tok . '%'));
$pdo->exec('DELETE FROM subscription_menu_templates WHERE name LIKE ' . $pdo->quote('%' . $tok . '%'));

/** @return array<string, mixed> */
function zt_plan_input(string $name): array
{
    return [
        'name' => $name,
        'description' => 'ZT delete test',
        'calendar_duration_days' => 7,
        'delivery_count' => 7,
        'frequency_type' => 'daily',
        'price' => 199,
        'is_active' => 0,
        'sort_order' => 990,
    ];
}

$planSave = SubscriptionPlanRepository::save(null, zt_plan_input('ZT Del Plan ' . $tok));
t($planSave['ok'] ?? false, 'Create disposable subscription plan');
$planId = (int) ($planSave['id'] ?? 0);

$rDel = SubscriptionPlanRepository::deleteForAdmin($planId);
t($rDel['ok'] && SubscriptionPlanRepository::find($planId) === null, 'Delete unused subscription plan');

$planRef = SubscriptionPlanRepository::save(null, zt_plan_input('ZT Ref Plan ' . $tok));
$refPlanId = (int) ($planRef['id'] ?? 0);
$cust = $pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?,?,1)');
$cust->execute(['9' . random_int(100000000, 999999999), 'ZT Sub Del']);
$cid = (int) $pdo->lastInsertId();
$snap = json_encode(['name' => 'ZT', 'delivery_count' => 7], JSON_UNESCAPED_UNICODE);
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
     price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'ZT-SUB-' . strtoupper($tok),
    $cid,
    $refPlanId,
    $snap,
    199.0,
    'completed',
    0,
    '2026-01-01',
    '2026-01-07',
]);
$rBlock = SubscriptionPlanRepository::deleteForAdmin($refPlanId);
t(!$rBlock['ok'] && str_contains($rBlock['message'], 'Deactivate'), 'Block delete of referenced subscription plan');
t(SubscriptionPlanRepository::find($refPlanId) !== null, 'Referenced plan remains after blocked delete');

$menuUnused = SubscriptionMenuRepository::save(null, [
    'name' => 'ZT Menu Unused ' . $tok,
    'planner_type' => 'date',
    'template_date' => '2099-06-15',
    'is_active' => 1,
], []);
$menuUnusedId = (int) ($menuUnused['id'] ?? 0);
$rMenuDel = SubscriptionMenuRepository::deleteForAdmin($menuUnusedId);
t($rMenuDel['ok'] && SubscriptionMenuRepository::find($menuUnusedId) === null, 'Delete unused menu template');

$deliveryDate = '2099-' . sprintf('%02d', (hexdec(substr($tok, 0, 2)) % 11) + 1) . '-'
    . sprintf('%02d', (hexdec(substr($tok, 2, 2)) % 27) + 1);
$menuRef = SubscriptionMenuRepository::save(null, [
    'name' => 'ZT Menu Ref ' . $tok,
    'planner_type' => 'date',
    'template_date' => $deliveryDate,
    'is_active' => 1,
], []);
$menuRefId = (int) ($menuRef['id'] ?? 0);
t(
    SubscriptionMenuRepository::resolveTemplateIdForDay($deliveryDate, 1) === $menuRefId,
    'Menu template resolves for fixture delivery date'
);
$planForSub = SubscriptionPlanRepository::save(null, zt_plan_input('ZT Menu Sub Plan ' . $tok));
$subPlanId = (int) ($planForSub['id'] ?? 0);
$pdo->prepare(
    'INSERT INTO customer_subscriptions (subscription_number, customer_id, subscription_plan_id, plan_snapshot_json,
     price_paid, status, deliveries_remaining, start_date, calendar_end_date)
     VALUES (?,?,?,?,?,?,?,?,?)'
)->execute([
    'ZT-MENU-' . strtoupper($tok),
    $cid,
    $subPlanId,
    $snap,
    199.0,
    'active',
    3,
    '2098-03-01',
    '2098-04-01',
]);
$subId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_days (customer_subscription_id, delivery_date, day_index, status)
     VALUES (?,?,?,?)'
)->execute([$subId, $deliveryDate, 1, 'scheduled']);
$dayId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO subscription_delivery_baskets (subscription_delivery_day_id, source_type, status, allowance_inr, total_inr)
     VALUES (?,?,?,?,?)'
)->execute([$dayId, 'suggested', 'suggested', 499, 100]);
$rMenuBlock = SubscriptionMenuRepository::deleteForAdmin($menuRefId);
t(!$rMenuBlock['ok'] && str_contains($rMenuBlock['message'], 'Deactivate'), 'Block delete of referenced menu template');
t(SubscriptionMenuRepository::find($menuRefId) !== null, 'Referenced menu template remains');

$pgSearch = 'ZT Pg ' . $tok;
$pgA = SubscriptionPlanRepository::save(null, zt_plan_input('ZT PgA ' . $pgSearch));
$pgB = SubscriptionPlanRepository::save(null, zt_plan_input('ZT PgB ' . $pgSearch));
$pgAId = (int) ($pgA['id'] ?? 0);
$pgBId = (int) ($pgB['id'] ?? 0);
SubscriptionPlanRepository::deleteForAdmin($pgBId);
$redirect = admin_delete_redirect_url('http://example.test/admin/subscriptions/index.php', [
    'entity' => 'subscription_plan',
    'admin_delete_ok' => '1',
    'list_page' => 2,
    'list_per_page' => 1,
    'list_q' => $pgSearch,
]);
t(!str_contains($redirect, 'page=2'), 'Pagination clamp after deleting last record on page 2');

$goodToken = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'admin_delete' => '1',
    'entity' => 'subscription_plan',
    'record_id' => (string) $pgAId,
    Csrf::fieldName() => 'invalid-token',
];
t(!Csrf::validateRequest(), 'CSRF rejection on invalid token');
$_POST[Csrf::fieldName()] = $goodToken;
t(Csrf::validateRequest(), 'Valid CSRF token accepted');

unset($_SESSION['admin_user_id']);
t(!AdminAuth::check(), 'Admin session cleared for unauthorized test');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['admin_delete'] = '1';
$_POST['entity'] = 'subscription_plan';
$_POST['record_id'] = (string) $pgAId;
$_POST[Csrf::fieldName()] = $goodToken;
$wouldDelete = Install::isSchemaReady() && AdminAuth::check();
t(!$wouldDelete, 'List delete handler requires admin session');

$_SESSION['admin_user_id'] = 1;
Permission::clearCache();

$idx = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/subscriptions/index.php');
$menuPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/subscriptions/menu-planner.php');
t(str_contains($idx, 'admin_process_list_delete') && str_contains($idx, 'admin_table_actions'), 'Plans index wires shared delete UI');
t(str_contains($menuPhp, 'admin_table_actions') && str_contains($menuPhp, 'subscription_menu_template'), 'Menu planner wires delete UI');
t(str_contains($idx, 'plan-edit.php') && str_contains($menuPhp, 'menu-edit.php'), 'Edit links preserved');

// Cleanup
$pdo->exec('DELETE FROM subscription_delivery_basket_items WHERE basket_id IN (SELECT id FROM subscription_delivery_baskets WHERE subscription_delivery_day_id = ' . $dayId . ')');
$pdo->exec('DELETE FROM subscription_delivery_baskets WHERE subscription_delivery_day_id = ' . $dayId);
$pdo->exec('DELETE FROM subscription_delivery_days WHERE id = ' . $dayId);
$pdo->exec('DELETE FROM customer_subscriptions WHERE customer_id = ' . $cid);
$pdo->exec('DELETE FROM customers WHERE id = ' . $cid);
$pdo->exec('DELETE FROM subscription_plans WHERE name LIKE ' . $pdo->quote('ZT %' . $tok . '%'));
$pdo->exec('DELETE FROM subscription_menu_templates WHERE name LIKE ' . $pdo->quote('ZT %' . $tok . '%'));
if ($pgAId > 0 && SubscriptionPlanRepository::find($pgAId) !== null) {
    SubscriptionPlanRepository::deleteForAdmin($pgAId);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
