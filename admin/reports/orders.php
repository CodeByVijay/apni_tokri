<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/report-filters.php';

AdminDemoPerms::denyIfCannot('reports');

$parsed = ReportDateRange::fromRequest($_GET);
if (empty($parsed['ok'])) {
    admin_flash_set($parsed['message'] ?? 'Invalid filters.', 'error');
    header('Location: ' . url_base() . '/admin/reports/orders.php');
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$page = max(1, (int) ($_GET['page'] ?? 1));
$orderStatus = isset($_GET['order_status']) ? trim((string) $_GET['order_status']) : '';
$paymentStatus = isset($_GET['payment_status']) ? trim((string) $_GET['payment_status']) : '';

$list = ['rows' => [], 'total' => 0];
if (Install::isSchemaReady()) {
    $list = SalesReportService::orders(
        $range,
        $page,
        $orderStatus !== '' ? $orderStatus : null,
        $paymentStatus !== '' ? $paymentStatus : null
    );
}

admin_layout_start(['pageTitle' => 'Order report', 'activeAdminNav' => 'reports']);
admin_flash_render();
$pageHeading = 'Order report';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

admin_report_filters([
    'preset' => $range->preset(),
    'from' => $_GET['from'] ?? $range->customFrom(),
    'to' => $_GET['to'] ?? $range->customTo(),
    'form_action' => url_base() . '/admin/reports/orders.php',
]);
?>
<form method="get" class="at-card mb-4">
    <div class="at-card__body flex flex-wrap gap-3 items-end">
        <input type="hidden" name="preset" value="<?= Security::escape($range->preset()) ?>">
        <?php if ($range->preset() === 'custom'): ?>
            <input type="hidden" name="from" value="<?= Security::escape($range->customFrom()) ?>">
            <input type="hidden" name="to" value="<?= Security::escape($range->customTo()) ?>">
        <?php endif; ?>
        <label class="text-sm">Order status
            <input type="text" name="order_status" class="at-input at-focusable" value="<?= Security::escape($orderStatus) ?>" placeholder="e.g. delivered">
        </label>
        <label class="text-sm">Payment status
            <input type="text" name="payment_status" class="at-input at-focusable" value="<?= Security::escape($paymentStatus) ?>" placeholder="e.g. paid">
        </label>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<div class="flex flex-wrap gap-2 mb-4 items-center">
    <span class="text-sm at-muted"><?= (int) $list['total'] ?> orders</span>
    <?php admin_report_export_form('orders', $range, [
        'order_status' => $orderStatus,
        'payment_status' => $paymentStatus,
    ]); ?>
</div>
<div class="at-admin-table-wrap">
    <table class="at-admin-table at-admin-table--dense">
        <thead>
            <tr>
                <th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Payment</th><th>Method</th>
                <th class="is-num">Items</th><th class="is-num">Total</th><th class="is-num">Discount</th><th class="is-num">Delivery</th><th>Refund</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($list['rows'] === []): ?>
            <tr><td colspan="11" class="at-admin-table__empty">No orders in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($list['rows'] as $o): ?>
            <tr>
                <td class="is-id"><?= Security::escape((string) $o['order_number']) ?></td>
                <td class="is-nowrap"><?= Security::escape(SalesReportService::formatOrderDate($o)) ?></td>
                <td><?= Security::escape(SalesReportService::customerLabel($o)) ?></td>
                <td><?= admin_status_badge((string) $o['status']) ?></td>
                <td><?= admin_status_badge((string) $o['payment_status']) ?></td>
                <td><?= Security::escape((string) ($o['payment_method'] ?? '—')) ?></td>
                <td class="is-num"><?= (int) ($o['item_count'] ?? 0) ?></td>
                <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($o['grand_total'] ?? 0))) ?></td>
                <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($o['discount_total'] ?? 0))) ?></td>
                <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($o['delivery_fee'] ?? 0))) ?></td>
                <td><?= Security::escape(SalesReportService::refundStatusLabel($o['refund_status'] ?? null)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
$totalPages = max(1, (int) ceil($list['total'] / SalesReportService::ORDERS_PER_PAGE));
if ($totalPages > 1):
    $qs = http_build_query(array_filter([
        'preset' => $range->preset(),
        'from' => $range->customFrom() ?: null,
        'to' => $range->customTo() ?: null,
        'order_status' => $orderStatus !== '' ? $orderStatus : null,
        'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
        'page' => min($totalPages, $page + 1),
    ]));
    ?>
    <div class="at-admin-pager">
        <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?> · <?= (int) $list['total'] ?> orders</span>
        <span class="at-admin-pager__nav">
            <?php if ($page > 1): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?<?= Security::escape($qs) ?>">Next</a>
            <?php endif; ?>
        </span>
    </div>
<?php endif; ?>
<p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reports/index.php">← All reports</a></p>
<?php admin_layout_end(); ?>
