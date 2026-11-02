<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/report-filters.php';

AdminDemoPerms::denyIfCannot('reports');

$parsed = ReportDateRange::fromRequest($_GET);
if (empty($parsed['ok'])) {
    admin_flash_set($parsed['message'] ?? 'Invalid filters.', 'error');
    header('Location: ' . url_base() . '/admin/reports/sales.php');
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$summary = Install::isSchemaReady() ? SalesReportService::summary($range) : [];

admin_layout_start(['pageTitle' => 'Sales summary', 'activeAdminNav' => 'reports']);
admin_flash_render();
$pageHeading = 'Sales summary';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

admin_report_filters([
    'preset' => $range->preset(),
    'from' => $_GET['from'] ?? $range->customFrom(),
    'to' => $_GET['to'] ?? $range->customTo(),
    'form_action' => url_base() . '/admin/reports/sales.php',
]);
?>
<div class="flex flex-wrap gap-2 mb-4 items-center">
    <span class="text-sm at-muted"><?= Security::escape($range->label()) ?></span>
    <?php admin_report_export_form('sales_summary', $range); ?>
</div>
<?php if ($summary === []): ?>
    <div class="at-alert at-alert--info">Schema not ready or no data.</div>
<?php else: ?>
<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php
    $metrics = [
        'Gross order value' => catalogue_format_price((float) $summary['gross_order_value']),
        'Discounts' => catalogue_format_price((float) $summary['discounts']),
        'Delivery charges' => catalogue_format_price((float) $summary['delivery_charges']),
        'Refunds' => catalogue_format_price((float) $summary['refund_amount']),
        'Net sales' => catalogue_format_price((float) $summary['net_sales']),
        'Total orders' => (string) $summary['total_orders'],
        'Paid orders' => (string) $summary['paid_orders'],
        'Pending payment' => (string) $summary['pending_payment_orders'],
        'Cancelled' => (string) $summary['cancelled_orders'],
        'Average order value' => catalogue_format_price((float) $summary['average_order_value']),
    ];
    foreach ($metrics as $label => $val): ?>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted"><?= Security::escape($label) ?></p>
            <p class="text-xl font-semibold"><?= Security::escape($val) ?></p>
        </div></div>
    <?php endforeach; ?>
</div>
<p class="text-xs at-muted mt-4">Revenue metrics include paid, non-cancelled orders only. Refunds use completed gateway refunds in this date range (completed_at when set; legacy terminal rows use updated_at fallback — see refund report).</p>
<?php endif; ?>
<p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reports/index.php">← All reports</a></p>
<?php admin_layout_end(); ?>
