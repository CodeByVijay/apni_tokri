<?php

declare(strict_types=1);

/**
 * Admin payment detail view — static + optional DB checks.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$root = APNI_TOKRI_ROOT;
$index = (string) file_get_contents($root . '/admin/payments/index.php');
$view = (string) file_get_contents($root . '/admin/payments/view.php');
$svc = (string) file_get_contents($root . '/includes/payment/payment_admin_detail.php');

t(strpos($index, 'admin/payments/view.php?id=') !== false, 'Payments list View links to detail page');
t(strpos($index, 'admin/orders/view.php?id=') !== false, 'Payments list order links to order detail');
t(strpos($index, "'actions'") !== false, 'Payments list Actions column');
t(strpos($view, 'PaymentAdminDetailService::viewModel') !== false, 'Detail page uses view model');
t(strpos($view, 'admin/refunds/index.php?id=') !== false, 'Refund links to admin refund detail');
t(strpos($svc, 'payment_webhook_events') !== false, 'Timeline uses webhook events table');
t(class_exists('PaymentAdminDetailService'), 'PaymentAdminDetailService autoloaded');

if (Install::canConnect()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $pid = (int) $pdo->query('SELECT id FROM payments ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($pid > 0) {
        $model = PaymentAdminDetailService::viewModel($pid);
        t($model !== null && (int) ($model['payment']['id'] ?? 0) === $pid, 'viewModel loads existing payment');
        t($model !== null && isset($model['gateway'], $model['timeline'], $model['refunds']), 'viewModel sections present');
    } else {
        echo "SKIP: no payment rows in DB\n";
    }
    t(PaymentAdminDetailService::viewModel(999999991) === null, 'Invalid payment id returns null');
} else {
    echo "SKIP: database not connected\n";
}

echo "\nPassed: {$pass}, Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
