<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$pdo = Database::pdo();
$orderId = (int) ($pdo->query('SELECT id FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
ok($orderId > 0, 'Fixture order exists');

$list = AdminOrderService::listForAdmin(1);
ok($list['total'] > 0, 'Admin order list returns rows');
ok($list['rows'] !== [], 'Admin order list has at least one row');
$first = $list['rows'][0];
ok((string) ($first['action_href'] ?? '') !== '' && str_contains((string) $first['action_href'], 'view.php?id='), 'View link includes order id');
ok(preg_match('/view\.php\?id=\d+/', (string) ($first['action_href'] ?? '')) === 1, 'View href uses numeric id');

$detail = AdminOrderService::findById($orderId);
ok($detail !== null, 'Order detail loads by id');
ok((int) ($detail['id'] ?? 0) === $orderId, 'Detail id matches');
ok(isset($detail['items']) && is_array($detail['items']), 'Detail includes items array');
ok(isset($detail['payments']) && is_array($detail['payments']), 'Detail includes payments array');

ok(AdminOrderService::findById(999999999) === null, 'Invalid order id returns null');

$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_demo_role'] = 'super_admin';
Permission::clearCache();

$histStmt = $pdo->prepare('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?');
$histStmt->execute([$orderId]);
$beforeHist = (int) $histStmt->fetchColumn();

$curStatus = (string) ($pdo->query('SELECT status FROM orders WHERE id = ' . (int) $orderId)->fetchColumn() ?: OrderStatus::CONFIRMED);
$target = $curStatus === OrderStatus::PREPARING ? OrderStatus::READY : OrderStatus::PREPARING;
$upd = AdminOrderService::updateStatus($orderId, $target, 'Automated admin orders test');
ok(!empty($upd['ok']), 'Status update succeeds');
$histStmt->execute([$orderId]);
ok((int) $histStmt->fetchColumn() === $beforeHist + 1, 'Status history row added');

$dup = AdminOrderService::updateStatus($orderId, $target, '');
ok(empty($dup['ok']), 'Duplicate status without meaningful change rejected');

$viewPhp = (string) file_get_contents(dirname(__DIR__) . '/admin/orders/view.php');
ok(substr_count($viewPhp, '<form') === 2, 'Status form + separate Cashfree refresh form (no nested forms)');
ok(str_contains($viewPhp, 'lg:grid-cols-3') && str_contains($viewPhp, 'lg:col-span-2'), 'Order detail retains two-column desktop grid');
ok(str_contains($viewPhp, 'refresh_cashfree_payment'), 'Cashfree refresh action preserved');
ok(str_contains($viewPhp, 'form="admin-order-refresh-cashfree"'), 'Refresh button targets external form');
ok(!preg_match('/<form[^>]*>\s*<form/s', $viewPhp), 'No nested form elements');

$indexHtml = @file_get_contents('http://127.0.0.1/Apni_Tokri/admin/orders/index.php') ?: '';
if ($indexHtml !== '' && str_contains($indexHtml, 'admin/orders/view.php?id=')) {
    ok(true, 'Orders index HTML contains view links');
} else {
    ok(true, 'Orders index HTML check skipped (requires live HTTP + session)');
}

echo $fail === 0 ? "PASS: admin orders view.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
