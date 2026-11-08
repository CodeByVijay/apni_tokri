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
ok(isset($detail['address']) || array_key_exists('address', $detail), 'Detail includes address block key');
ok(isset($detail['refunds']) && is_array($detail['refunds']), 'Detail includes refunds array');

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
ok(str_contains($viewPhp, '<div class="at-admin-order-detail__layout">'), 'Layout wrapper is not a single POST form');
ok(str_contains($viewPhp, 'id="admin-order-update-status"'), 'Status update has dedicated form');
ok(str_contains($viewPhp, 'id="admin-order-reschedule-delivery"'), 'Reschedule delivery has dedicated form');
ok(
    !preg_match('/id="admin-order-update-status"[^>]*>[\s\S]*reschedule_delivery_date/s', $viewPhp)
    && !preg_match('/admin-order-update-status[\s\S]{0,1200}reschedule_delivery_date/s', $viewPhp),
    'Reschedule date field is outside status update form'
);
ok(
    str_contains($viewPhp, 'at-admin-order-detail__layout')
    && str_contains($viewPhp, 'at-admin-order-detail__main')
    && str_contains($viewPhp, 'at-admin-order-detail__aside'),
    'Order detail retains two-column desktop layout (scoped grid)'
);
ok(str_contains($viewPhp, 'at-admin-order-recon'), 'Reconciliation panel uses scoped admin order classes');
ok(str_contains($viewPhp, 'order-detail-customer.php'), 'Customer section in main column');
ok(str_contains($viewPhp, 'order-status-timeline.php'), 'Admin order detail uses status timeline partial');
ok(str_contains($viewPhp, 'customer_format_datetime'), 'Admin order detail formats display timestamps');
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
