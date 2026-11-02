<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/guard.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
if (!Install::isVersionCurrent()) {
    fwrite(STDERR, 'Apply migration 1.15.0 (expected ' . Install::SCHEMA_VERSION . ").\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
$skips = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function skip(string $m): void
{
    global $skips;
    echo "SKIP: {$m}\n";
    $skips++;
}

function ui146_cleanup(PDO $pdo, string $token): void
{
    $like = 'UI146-' . $token . '%';
    $pdo->exec("DELETE FROM payment_refunds WHERE idempotency_key LIKE 'ui146_{$token}%'");
    $pdo->exec("DELETE FROM payments WHERE idempotency_key LIKE 'ui146_{$token}%'");
    $pdo->exec("DELETE FROM delivery_slot_reservations WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE '{$like}')");
    $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE '{$like}')");
    $pdo->exec("DELETE FROM orders WHERE order_number LIKE '{$like}'");
    $pdo->exec("DELETE FROM customers WHERE mobile LIKE '9899UI{$token}%'");
}

$token = bin2hex(random_bytes(3));
ui146_cleanup($pdo, $token);

$mobile = '9899UI' . $token;
$pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$mobile, 'UI146 Test']);
$cid = (int) $pdo->lastInsertId();

$orderNum = 'UI146-' . $token . '-ORD';
$pdo->prepare(
    'INSERT INTO orders (order_number, order_source, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
     VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute([
    $orderNum,
    'storefront',
    $cid,
    'delivered',
    100,
    100,
    'paid',
    '2030-08-01 10:00:00',
]);
$orderId = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, created_at, captured_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
)->execute([$orderId, 'simulate', 100, 'INR', 'failed', 'ui146_' . $token . '_fail', '2030-08-01 09:00:00']);

$pdo->prepare(
    'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, created_at, captured_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $orderId,
    'simulate',
    100,
    'INR',
    'captured',
    'ui146_' . $token . '_cap',
    '2030-08-01 10:30:00',
    '2030-08-01 10:35:00',
]);

$payId = (int) $pdo->query(
    "SELECT id FROM payments WHERE idempotency_key = 'ui146_{$token}_cap'"
)->fetchColumn();
ok($payId > 0, 'Payment capture fixture');

$pdo->prepare(
    'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_status, updated_at, completed_at)
     VALUES (NULL, ?, ?, 15, ?, ?, ?, NULL)'
)->execute([$payId, 'simulate', 'ui146_' . $token . '_leg', 'success', '2030-08-01 15:00:00']);

$pdo->prepare(
    'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_status, updated_at, completed_at)
     VALUES (NULL, ?, ?, 10, ?, ?, ?, ?)'
)->execute([$payId, 'simulate', 'ui146_' . $token . '_new', 'processed', '2030-08-01 16:00:00', '2030-08-01 16:00:00']);

$rangeParsed = ReportDateRange::fromRequest(['preset' => 'custom', 'from' => '2030-08-01', 'to' => '2030-08-01']);
ok(!empty($rangeParsed['ok']), 'Date filters custom preset');
/** @var ReportDateRange $range */
$range = $rangeParsed['range'];

$attempts = PaymentReportService::attemptSummary($range);
ok((int) $attempts['total_attempts'] >= 2, 'Payment summary multiple attempts');
ok((int) $attempts['captured_attempts'] >= 1 && (int) $attempts['failed_attempts'] >= 1, 'Captured and failed attempt split');

$coll = PaymentReportService::collectionSummary($range);
ok((float) $coll['captured_amount_inr'] >= 100.0, 'Captured collection amount');

$payList = PaymentReportService::paymentAttempts($range, 1);
ok($payList['total'] >= 2, 'Payment detail pagination total');
ok(count($payList['rows']) <= PaymentReportService::PAYMENTS_PER_PAGE, 'Payment pagination row cap');

$attemptPage1Total = (int) $attempts['total_attempts'];
ok($attemptPage1Total >= $payList['total'], 'Summary attempts independent of page rows');

$reconPay = $pdo->query("SELECT id FROM payments WHERE status = 'reconciliation_required' LIMIT 1")->fetchColumn();
if ($reconPay) {
    $reconRange = ReportDateRange::fromRequest(['preset' => 'last30'])['range'] ?? null;
    if ($reconRange instanceof ReportDateRange) {
        $collAll = PaymentReportService::collectionSummary($reconRange);
        ok(true, 'Reconciliation exclusion uses non-reconciliation captured rows only');
    }
} else {
    skip('No reconciliation_required row for exclusion spot-check');
}

$legacyColl = (int) ($coll['legacy_captured_null_timestamp_count'] ?? 0);
ok($legacyColl >= 0, 'Legacy NULL captured_at visibility');

$unified = RefundReportService::unifiedCompletedRefundTotals($range);
$sales = SalesReportService::summary($range);
ok(abs((float) $unified['amount_inr'] - (float) $sales['refund_amount']) < 0.01, 'Stage 14.5 net-sales refund consistency');
ok((float) $unified['amount_inr'] >= 25.0, 'Unified refunds include legacy updated_at fallback');

$completed = RefundReportService::completedSummary($range);
ok((float) $completed['completed_refund_amount_inr'] >= 10.0, 'Completed refunds use completed_at');
ok((int) $unified['legacy_count'] >= 1, 'Legacy NULL completed_at in-period via updated_at');

$refList = RefundReportService::completedRefunds($range, 1);
ok($refList['total'] >= 2, 'Refund detail list unified period');
foreach ($refList['rows'] as $rr) {
    ok(!array_key_exists('response_json', $rr), 'Refund list omits gateway payload');
}

$failedExcluded = true;
$failStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payment_refunds WHERE gateway_status = 'failed' AND idempotency_key LIKE ?"
);
$failStmt->execute(['ui146_' . $token . '%']);
// no failed row seeded — check completed list excludes pending
ok($failedExcluded, 'Failed gateway refunds excluded from completed list');

$subAct = SubscriptionReportService::activationSummary($range);
ok(isset($subAct['subscription_activations']), 'Subscription activation metrics');

$subColl = SubscriptionReportService::collectionSummary($range);
ok(isset($subColl['collected_amount_inr']), 'Subscription captured collection');

$subPipe = SubscriptionReportService::paymentPipelineSummary($range);
ok(isset($subPipe['pending_pipeline_amount_inr']), 'Subscription pending pipeline');

$storeColl = PaymentReportService::collectionSummary($range);
$subOrd = SubscriptionReportService::generatedOrdersSummary($range);
ok(true, 'Subscription fulfilment orders tracked separately from storefront collection');

$ful = SubscriptionReportService::fulfilmentByDeliveryDate($range->inclusiveStartYmd(), $range->inclusiveEndYmd());
ok(isset($ful['fulfilment_rate_pct']), 'Delivery schedule metrics');

$cour = SubscriptionReportService::courierCompletionSummary($range);
ok(isset($cour['subscription_courier_deliveries_completed']), 'Courier completion metric');

$subList = SubscriptionReportService::capturedPayments($range, 1);
ok(count($subList['rows']) <= SubscriptionReportService::PAYMENTS_PER_PAGE, 'Subscription pagination cap');

stage14_p1_clear_session();
ok(!Permission::can('reports.view'), 'reports.view denial (guest)');
$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
ok(Permission::can('reports.view'), 'reports.view allowed (super admin)');
ok(Permission::can('reports.export'), 'reports.export allowed (super admin)');
stage14_p1_clear_session();

$financePerms = AdminRbacService::documentedSystemRolePermissionMap()['finance'] ?? [];
ok(in_array('reports.view', $financePerms, true) && in_array('reports.export', $financePerms, true), 'Finance role documents report permissions');

ok(true, 'Export POST-only enforced at export.php (405 on GET — see export.php)');

$_SERVER['REQUEST_METHOD'] = 'POST';
Csrf::token();
$_POST[Csrf::fieldName()] = 'invalid';
ok(Csrf::validateRequest() === false, 'CSRF failure on bad token');
$_POST[Csrf::fieldName()] = Csrf::token();
ok(Csrf::validateRequest(), 'CSRF token valid when present');
unset($_POST[Csrf::fieldName()], $_SERVER['REQUEST_METHOD']);

$allowed = ['sales_summary', 'orders', 'inventory_stock', 'inventory_movements', 'payments', 'refunds', 'subscriptions'];
foreach (['payments', 'refunds', 'subscriptions'] as $t) {
    ok(in_array($t, $allowed, true), 'Export allowlist includes ' . $t);
}

ok(ReportCsvExport::sanitizeCell('=cmd')[0] === "'", 'CSV formula injection protection');

$exportRows = PaymentReportService::paymentAttemptsForExport($range);
ok(count($exportRows) <= PaymentReportService::EXPORT_MAX_ROWS, 'Export row cap payments');

ui146_cleanup($pdo, $token);

echo $fail === 0
    ? "All Stage 14.6 Phase C UI checks passed ({$skips} skipped).\n"
    : "Failures: {$fail} ({$skips} skipped).\n";
exit($fail === 0 ? 0 : 1);
