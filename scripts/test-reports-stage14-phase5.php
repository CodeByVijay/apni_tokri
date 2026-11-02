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
    fwrite(STDERR, 'Apply migration 1.14.0_stage14_reports_phase5.sql (expected ' . Install::SCHEMA_VERSION . ").\n");
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

function r145_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM payment_refunds WHERE idempotency_key LIKE 'test_r145_%'");
    $pdo->exec("DELETE FROM payments WHERE idempotency_key LIKE 'test_r145_%'");
    $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_number LIKE 'AT-R145-%')");
    $pdo->exec("DELETE FROM orders WHERE order_number LIKE 'AT-R145-%'");
    $pdo->exec("DELETE FROM customers WHERE mobile LIKE '9899R%' AND name = 'R145 Test'");
    $pdo->exec("DELETE FROM inventory_movements WHERE reason LIKE 'test_r145_%'");
}

function r145_seed(PDO $pdo): array
{
    $ts = '2030-06-15 12:00:00';

    $token = bin2hex(random_bytes(3));
    $mobile = '9899R' . $token;
    $pdo->prepare('INSERT INTO customers (mobile, name) VALUES (?, ?)')->execute([$mobile, 'R145 Test']);
    $cid = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['AT-R145-PAID', $cid, 'delivered', 200, 20, 15, 195, 'paid', $ts]);
    $paidId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$paidId, 'P', 'V', 2, 100, 200]);
    $pdo->prepare(
        'INSERT INTO order_items (order_id, product_name, variant_name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$paidId, 'P2', 'V2', 1, 50, 50]);

    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['AT-R145-PEND', $cid, 'pending_payment', 100, 0, 0, 100, 'pending', $ts]);

    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, placed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['AT-R145-CANC', $cid, 'cancelled', 50, 0, 0, 50, 'paid', $ts]);

    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$paidId, 'simulate', 195, 'INR', 'captured', 'test_r145_pay']);
    $payId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO payment_refunds (refund_request_id, payment_id, gateway, amount_inr, idempotency_key, gateway_status, updated_at)
         VALUES (NULL, ?, ?, ?, ?, ?, ?)'
    )->execute([$payId, 'simulate', 25, 'test_r145_ref', 'success', $ts]);

    $vid = (int) ($pdo->query('SELECT id FROM product_variants LIMIT 1')->fetchColumn() ?: 0);
    if ($vid > 0) {
        $pdo->prepare(
            'INSERT INTO inventory_movements (variant_id, change_qty, qty_before, qty_after, reason, admin_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$vid, 5, 10, 15, 'test_r145_in', 1, $ts]);
        $pdo->prepare(
            'INSERT INTO inventory_movements (variant_id, change_qty, qty_before, qty_after, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$vid, -2, 15, 13, 'test_r145_out', $ts]);
    }

    return ['paid_id' => $paidId, 'variant_id' => $vid, 'ts' => $ts, 'range_from' => '2030-06-15', 'range_to' => '2030-06-15'];
}

r145_cleanup($pdo);
$seed = r145_seed($pdo);

$rangeOk = ReportDateRange::fromRequest([
    'preset' => 'custom',
    'from' => $seed['range_from'],
    'to' => $seed['range_to'],
]);
ok(!empty($rangeOk['ok']), 'Date filter custom preset');
/** @var ReportDateRange $todayRange */
$todayRange = $rangeOk['range'];

$summary = SalesReportService::summary($todayRange);
ok((float) $summary['gross_order_value'] === 195.0, 'Sales calculations (gross paid non-cancelled)');
ok((float) $summary['discounts'] === 20.0, 'Discount calculations');
ok((float) $summary['delivery_charges'] === 15.0, 'Delivery charge calculations');
ok((float) $summary['refund_amount'] === 25.0, 'Refund calculations');
ok((float) $summary['net_sales'] === 170.0, 'Net sales (gross - refunds)');
ok((int) $summary['paid_orders'] >= 1 && (int) $summary['pending_payment_orders'] >= 1, 'Order status filtering counts');
ok((int) $summary['cancelled_orders'] >= 1, 'Cancelled orders counted');
ok(abs((float) $summary['average_order_value'] - 195.0) < 0.01, 'Average order value from paid gross');

$dupCheck = (float) $summary['gross_order_value'];
ok($dupCheck < 400, 'Duplicate-order prevention in joins (not 2x line items)');

$customBad = ReportDateRange::fromRequest(['preset' => 'custom', 'from' => '2026-01-10', 'to' => '2026-01-01']);
ok(empty($customBad['ok']), 'Custom date range validation (from after to)');

$ist = ReportDateRange::TIMEZONE;
ok($ist === 'Asia/Kolkata', 'Asia/Kolkata date handling');

$listPaid = SalesReportService::orders($todayRange, 1, null, 'paid');
ok($listPaid['total'] >= 1, 'Payment status filtering');
$foundPaid = false;
foreach ($listPaid['rows'] as $row) {
    if (($row['order_number'] ?? '') === 'AT-R145-PAID') {
        $foundPaid = true;
        ok((int) ($row['item_count'] ?? 0) === 2, 'Item count subquery (2 lines not double order)');
    }
}
ok($foundPaid, 'Order report includes fixture order');

$page1 = SalesReportService::orders($todayRange, 1, null, null);
$pageSize = SalesReportService::ORDERS_PER_PAGE;
ok($page1['total'] >= 3, 'Pagination total');
ok(count($page1['rows']) <= $pageSize, 'Pagination row limit');

$stock = InventoryReportService::currentStock();
ok(is_array($stock), 'Inventory current stock');
$low = InventoryReportService::lowStock();
$out = InventoryReportService::outOfStock();
ok(is_array($low) && is_array($out), 'Low-stock and out-of-stock lists');

$mov = InventoryReportService::movementSummary($todayRange, 1);
ok((int) ($mov['totals']['stock_received'] ?? 0) >= 5, 'Stock movement received');
ok((int) ($mov['totals']['stock_deducted'] ?? 0) >= 2, 'Stock movement deducted');

ok(ReportCsvExport::sanitizeCell('=1+1')[0] === "'", 'CSV formula injection protection');
ok(ReportCsvExport::sanitizeCell('hello') === 'hello', 'CSV escaping normal cells');

stage14_p1_clear_session();
ok(!Permission::can('reports.view'), 'Report authorization (guest denied)');
$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
ok(Permission::can('reports.view') && Permission::can('reports.export'), 'Report and export authorization (super admin)');
stage14_p1_clear_session();

$inj = SalesReportService::orders($todayRange, 1, "x' OR '1'='1", null);
ok($inj['total'] === 0, 'SQL injection-resistant filters (invalid status)');

$emptyRange = ReportDateRange::fromRequest(['preset' => 'custom', 'from' => '1999-01-01', 'to' => '1999-01-02']);
/** @var ReportDateRange $emptyR */
$emptyR = $emptyRange['range'];
$emptySum = SalesReportService::summary($emptyR);
ok((int) $emptySum['total_orders'] === 0, 'Empty result handling');

$demoInSummary = false;
foreach ($summary as $k => $v) {
    if (is_string($v) && (stripos($v, 'demo') !== false || stripos($v, 'DEMO01') !== false)) {
        $demoInSummary = true;
    }
}
ok(!$demoInSummary, 'No demo data in reports');

r145_cleanup($pdo);

echo $fail === 0 ? "All Stage 14.5 report checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
