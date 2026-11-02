<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('reports');
if (!Permission::can('reports.export')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
Csrf::requireValid();

$parsed = ReportDateRange::fromRequest($_POST);
if (empty($parsed['ok'])) {
    http_response_code(400);
    echo 'Invalid date range';
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$type = trim((string) ($_POST['type'] ?? ''));

AuditLog::write('reports.export', 'report', $type, [
    'preset' => $range->preset(),
    'label' => $range->label(),
]);

$slug = ReportCsvExport::safeFilename($type . '-' . date('Y-m-d'));

if ($type === 'sales_summary') {
    $s = SalesReportService::summary($range);
    ReportCsvExport::streamDownload($slug, ['Metric', 'Value'], [
        ['Gross order value', $s['gross_order_value']],
        ['Discounts', $s['discounts']],
        ['Delivery charges', $s['delivery_charges']],
        ['Refund amount', $s['refund_amount']],
        ['Net sales', $s['net_sales']],
        ['Total orders', $s['total_orders']],
        ['Paid orders', $s['paid_orders']],
        ['Pending payment orders', $s['pending_payment_orders']],
        ['Cancelled orders', $s['cancelled_orders']],
        ['Average order value', $s['average_order_value']],
    ]);
    exit;
}

if ($type === 'orders') {
    $orderStatus = trim((string) ($_POST['order_status'] ?? ''));
    $paymentStatus = trim((string) ($_POST['payment_status'] ?? ''));
    $rows = SalesReportService::ordersForExport(
        $range,
        $orderStatus !== '' ? $orderStatus : null,
        $paymentStatus !== '' ? $paymentStatus : null
    );
    $gen = static function () use ($rows): Generator {
        foreach ($rows as $o) {
            yield [
                (string) $o['order_number'],
                SalesReportService::formatOrderDate($o),
                SalesReportService::customerLabel($o),
                (string) $o['status'],
                (string) $o['payment_status'],
                (string) ($o['payment_method'] ?? ''),
                (string) ($o['item_count'] ?? 0),
                (string) ($o['grand_total'] ?? 0),
                (string) ($o['discount_total'] ?? 0),
                (string) ($o['delivery_fee'] ?? 0),
                SalesReportService::refundStatusLabel($o['refund_status'] ?? null),
            ];
        }
    };
    ReportCsvExport::streamDownload(
        $slug,
        ['Order', 'Date', 'Customer', 'Order status', 'Payment status', 'Payment method', 'Items', 'Total', 'Discount', 'Delivery', 'Refund status'],
        $gen()
    );
    exit;
}

if ($type === 'inventory_stock') {
    $stock = InventoryReportService::currentStock();
    $gen = static function () use ($stock): Generator {
        foreach ($stock as $r) {
            yield [
                (string) $r['product_name'],
                (string) $r['variant_name'],
                (string) ($r['sku'] ?? ''),
                (string) $r['stock_qty'],
                (string) $r['low_stock_threshold'],
            ];
        }
    };
    ReportCsvExport::streamDownload($slug, ['Product', 'Variant', 'SKU', 'Stock qty', 'Low threshold'], $gen());
    exit;
}

if ($type === 'inventory_movements') {
    $mov = InventoryReportService::movementsForExport($range);
    $gen = static function () use ($mov): Generator {
        foreach ($mov as $m) {
            yield [
                (string) $m['created_at'],
                (string) $m['product_name'],
                (string) $m['variant_name'],
                (string) ($m['sku'] ?? ''),
                (string) $m['change_qty'],
                (string) $m['qty_after'],
                (string) $m['reason'],
            ];
        }
    };
    ReportCsvExport::streamDownload($slug, ['When', 'Product', 'Variant', 'SKU', 'Change', 'Qty after', 'Reason'], $gen());
    exit;
}

if ($type === 'payments') {
    $attempts = PaymentReportService::attemptSummary($range);
    $collection = PaymentReportService::collectionSummary($range);
    $recon = PaymentReportService::reconciliationSummary($range);
    $detail = PaymentReportService::paymentAttemptsForExport($range);
    $csvHeader = ['Record type', 'Field 1', 'Field 2', 'Field 3', 'Field 4', 'Field 5', 'Field 6', 'Field 7', 'Field 8', 'Field 9'];
    $gen = static function () use ($attempts, $collection, $recon, $detail): Generator {
        $sum = static function (string $section, string $metric, string $value): array {
            return ['SUMMARY', $section, $metric, $value, '', '', '', '', '', ''];
        };
        yield $sum('Attempt cohort', 'Total attempts', (string) ($attempts['total_attempts'] ?? 0));
        yield $sum('Attempt cohort', 'Captured attempts', (string) ($attempts['captured_attempts'] ?? 0));
        yield $sum('Attempt cohort', 'Failed attempts', (string) ($attempts['failed_attempts'] ?? 0));
        yield $sum('Attempt cohort', 'Pending attempts', (string) ($attempts['pending_attempts'] ?? 0));
        yield $sum('Attempt cohort', 'Success rate pct', (string) ($attempts['attempt_cohort_success_rate_pct'] ?? 0));
        yield $sum('Collections', 'Captured amount INR', (string) ($collection['captured_amount_inr'] ?? 0));
        yield $sum('Collections', 'Unique paid orders', (string) ($collection['unique_paid_order_count'] ?? 0));
        yield $sum('Collections', 'Duplicate captured order count', (string) ($collection['duplicate_captured_order_count'] ?? 0));
        yield $sum('Collections', 'Legacy NULL captured_at count', (string) ($collection['legacy_captured_null_timestamp_count'] ?? 0));
        yield $sum('Reconciliation', 'Open exceptions', (string) ($recon['open_exceptions'] ?? 0));
        foreach ($detail as $row) {
            yield [
                'ATTEMPT',
                (string) ($row['order_number'] ?? ''),
                (string) ($row['gateway'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['amount'] ?? ''),
                (string) ($row['gateway_payment_id'] ?? ''),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['captured_at'] ?? ''),
                PaymentReportService::reconciliationLabel($row),
                '',
            ];
        }
    };
    ReportCsvExport::streamDownload($slug, $csvHeader, $gen());
    exit;
}

if ($type === 'refunds') {
    $requests = RefundReportService::requestSummary($range);
    $completed = RefundReportService::completedSummary($range);
    $unified = RefundReportService::unifiedCompletedRefundTotals($range);
    $sales = SalesReportService::summary($range);
    $detail = RefundReportService::completedRefundsForExport($range);
    $csvHeader = ['Record type', 'Field 1', 'Field 2', 'Field 3', 'Field 4', 'Field 5', 'Field 6', 'Field 7', 'Field 8', 'Field 9'];
    $gen = static function () use ($requests, $completed, $unified, $sales, $detail): Generator {
        $sum = static function (string $section, string $metric, string $value): array {
            return ['SUMMARY', $section, $metric, $value, '', '', '', '', '', ''];
        };
        yield $sum('Requests', 'Total requests', (string) ($requests['total_refund_requests'] ?? 0));
        yield $sum('Requests', 'Open', (string) ($requests['open_requests'] ?? 0));
        yield $sum('Completed completed_at period', 'Count', (string) ($completed['completed_refund_count'] ?? 0));
        yield $sum('Completed completed_at period', 'Amount INR', (string) ($completed['completed_refund_amount_inr'] ?? 0));
        yield $sum('Net sales aligned', 'Amount INR', (string) ($unified['amount_inr'] ?? 0));
        yield $sum('Net sales aligned', 'Sales summary refund INR', (string) ($sales['refund_amount'] ?? 0));
        yield $sum('Legacy in period', 'Count', (string) ($unified['legacy_count'] ?? 0));
        yield $sum('Legacy all-time NULL completed_at', 'Count', (string) ($completed['legacy_terminal_null_completed_at_count'] ?? 0));
        foreach ($detail as $row) {
            yield [
                'REFUND',
                (string) ($row['request_number'] ?? ''),
                (string) ($row['request_status'] ?? ''),
                (string) ($row['gateway_status'] ?? ''),
                (string) ($row['amount_inr'] ?? ''),
                (string) ($row['gateway_refund_id'] ?? ''),
                (string) ($row['completed_at'] ?? ''),
                (string) ($row['period_date_basis'] ?? ''),
                (string) ($row['order_number'] ?? $row['subscription_number'] ?? ''),
                '',
            ];
        }
    };
    ReportCsvExport::streamDownload($slug, $csvHeader, $gen());
    exit;
}

if ($type === 'subscriptions') {
    $status = SubscriptionReportService::statusSnapshot();
    $activation = SubscriptionReportService::activationSummary($range);
    $collection = SubscriptionReportService::collectionSummary($range);
    $pipeline = SubscriptionReportService::paymentPipelineSummary($range);
    $fulfilment = SubscriptionReportService::fulfilmentByDeliveryDate(
        $range->inclusiveStartYmd(),
        $range->inclusiveEndYmd()
    );
    $courier = SubscriptionReportService::courierCompletionSummary($range);
    $generated = SubscriptionReportService::generatedOrdersSummary($range);
    $detail = SubscriptionReportService::capturedPaymentsForExport($range);
    $csvHeader = ['Record type', 'Field 1', 'Field 2', 'Field 3', 'Field 4', 'Field 5', 'Field 6', 'Field 7', 'Field 8', 'Field 9'];
    $gen = static function () use ($status, $activation, $collection, $pipeline, $fulfilment, $courier, $generated, $detail): Generator {
        $sum = static function (string $section, string $metric, string $value): array {
            return ['SUMMARY', $section, $metric, $value, '', '', '', '', '', ''];
        };
        yield $sum('Snapshot', 'Active subscriptions', (string) ($status['active'] ?? 0));
        yield $sum('Snapshot', 'Paused', (string) ($status['paused'] ?? 0));
        yield $sum('Snapshot', 'Pending payment', (string) ($status['pending_payment'] ?? 0));
        yield $sum('Activations', 'Count', (string) ($activation['subscription_activations'] ?? 0));
        yield $sum('Collection', 'Collected INR', (string) ($collection['collected_amount_inr'] ?? 0));
        yield $sum('Pipeline', 'Pending pipeline INR', (string) ($pipeline['pending_pipeline_amount_inr'] ?? 0));
        yield $sum('Fulfilment', 'Delivered by schedule date', (string) ($fulfilment['delivered_by_schedule_date'] ?? 0));
        yield $sum('Fulfilment', 'Fulfilment rate pct', (string) ($fulfilment['fulfilment_rate_pct'] ?? 0));
        yield $sum('Courier', 'Deliveries completed', (string) ($courier['subscription_courier_deliveries_completed'] ?? 0));
        yield $sum('Orders', 'Generated fulfilment orders', (string) ($generated['generated_subscription_orders'] ?? 0));
        foreach ($detail as $row) {
            yield [
                'PAYMENT',
                (string) ($row['subscription_number'] ?? ''),
                (string) ($row['gateway'] ?? ''),
                (string) ($row['amount'] ?? ''),
                (string) ($row['gateway_payment_id'] ?? ''),
                (string) ($row['activated_at'] ?? ''),
                (string) ($row['captured_at'] ?? ''),
                '',
                '',
                '',
            ];
        }
    };
    ReportCsvExport::streamDownload($slug, $csvHeader, $gen());
    exit;
}

http_response_code(400);
echo 'Unknown export type';
