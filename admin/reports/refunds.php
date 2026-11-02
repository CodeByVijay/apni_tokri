<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/report-filters.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/report-pagination.php';

AdminDemoPerms::denyIfCannot('reports');

$parsed = ReportDateRange::fromRequest($_GET);
if (empty($parsed['ok'])) {
    admin_flash_set($parsed['message'] ?? 'Invalid filters.', 'error');
    header('Location: ' . url_base() . '/admin/reports/refunds.php');
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$page = max(1, (int) ($_GET['page'] ?? 1));

$requests = [];
$completed = [];
$unified = [];
$liability = [];
$list = ['rows' => [], 'total' => 0];
$salesRefund = 0.0;
if (Install::isSchemaReady()) {
    $requests = RefundReportService::requestSummary($range);
    $completed = RefundReportService::completedSummary($range);
    $unified = RefundReportService::unifiedCompletedRefundTotals($range);
    $liability = RefundReportService::pendingLiabilitySnapshot();
    $list = RefundReportService::completedRefunds($range, $page);
    $salesRefund = (float) (SalesReportService::summary($range)['refund_amount'] ?? 0);
}

admin_layout_start(['pageTitle' => 'Refund report', 'activeAdminNav' => 'reports']);
admin_flash_render();
$pageHeading = 'Refund report';
$pageDescription = 'Workflow requests vs completed gateway refunds. Approved/requested amounts are not completed cash.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

admin_report_filters([
    'preset' => $range->preset(),
    'from' => $_GET['from'] ?? $range->customFrom(),
    'to' => $_GET['to'] ?? $range->customTo(),
    'form_action' => url_base() . '/admin/reports/refunds.php',
]);
?>
<div class="flex flex-wrap gap-2 mb-4 items-center">
    <span class="text-sm at-muted"><?= Security::escape($range->label()) ?></span>
    <?php admin_report_export_form('refunds', $range); ?>
</div>

<?php if (!Install::isSchemaReady()): ?>
    <div class="at-alert at-alert--info">Schema not ready.</div>
<?php else: ?>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Refund requests <span class="text-xs font-normal at-muted">(date basis: refund_requests.created_at)</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php
        $reqCards = [
            'Total requests' => (string) ($requests['total_refund_requests'] ?? 0),
            'Open' => (string) ($requests['open_requests'] ?? 0),
            'Approved (not completed cash)' => (string) ($requests['approved_requests'] ?? 0),
            'Processing' => (string) ($requests['processing_requests'] ?? 0),
            'Failed / rejected' => (string) ($requests['failed_rejected_requests'] ?? 0),
        ];
        foreach ($reqCards as $label => $val): ?>
            <div class="at-card"><div class="at-card__body">
                <p class="text-xs at-muted"><?= Security::escape($label) ?></p>
                <p class="text-xl font-semibold"><?= Security::escape($val) ?></p>
            </div></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Completed gateway refunds <span class="text-xs font-normal at-muted">(new rows: payment_refunds.completed_at)</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3">
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Completed count (completed_at period)</p>
            <p class="text-xl font-semibold"><?= (int) ($completed['completed_refund_count'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Completed amount (completed_at period)</p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($completed['completed_refund_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Net sales aligned total</p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($unified['amount_inr'] ?? 0))) ?></p>
            <p class="text-xs at-muted mt-1">Matches sales summary refunds (<?= Security::escape(catalogue_format_price($salesRefund)) ?>)</p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Order refunds</p>
            <p class="text-xl font-semibold"><?= (int) ($completed['order_refund_count'] ?? 0) ?> · <?= Security::escape(catalogue_format_price((float) ($completed['order_refund_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Subscription refunds</p>
            <p class="text-xl font-semibold"><?= (int) ($completed['subscription_refund_count'] ?? 0) ?> · <?= Security::escape(catalogue_format_price((float) ($completed['subscription_refund_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Partial refund payments (multi-row)</p>
            <p class="text-xl font-semibold"><?= (int) ($completed['payments_with_multiple_completed_refunds'] ?? 0) ?></p>
        </div></div>
    </div>
    <?php if (!empty($completed['gateway_breakdown'])): ?>
        <div class="at-card overflow-x-auto mb-3">
            <div class="at-card__body">
                <p class="text-sm font-medium mb-2">Gateway refunds (completed_at period)</p>
                <table class="at-admin-table at-admin-table--dense">
                    <thead><tr><th>Gateway</th><th class="is-num">Count</th><th class="is-num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($completed['gateway_breakdown'] as $gw): ?>
                        <tr>
                            <td><?= Security::escape((string) ($gw['gateway'] ?? '')) ?></td>
                            <td class="is-num"><?= (int) ($gw['refund_count'] ?? 0) ?></td>
                            <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($gw['amount_inr'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Legacy & liability</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Historical terminal (NULL completed_at) — all time</p>
            <p class="text-xl font-semibold"><?= (int) ($completed['legacy_terminal_null_completed_at_count'] ?? 0) ?>
                · <?= Security::escape(catalogue_format_price((float) ($completed['legacy_terminal_null_completed_at_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Legacy in this period (updated_at fallback)</p>
            <p class="text-xl font-semibold"><?= (int) ($unified['legacy_count'] ?? 0) ?>
                · <?= Security::escape(catalogue_format_price((float) ($unified['legacy_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Pending liability (approved pipeline)</p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($liability['pending_liability_approved_inr'] ?? 0))) ?></p>
            <p class="text-xs at-muted"><?= (int) ($liability['open_refund_request_count'] ?? 0) ?> open request(s)</p>
        </div></div>
    </div>
</section>

<section>
    <h2 class="text-lg font-semibold mb-2">Completed refunds detail <span class="text-xs font-normal at-muted">(unified period filter; legacy rows use updated_at)</span></h2>
    <p class="text-sm at-muted mb-2"><?= (int) $list['total'] ?> row(s)</p>
    <div class="at-admin-table-wrap">
        <table class="at-admin-table at-admin-table--dense">
            <thead>
                <tr>
                    <th>Request</th><th>Request status</th><th>Gateway status</th><th class="is-num">Amount</th>
                    <th>Gateway refund ID</th><th>Completed at</th><th>Period basis</th><th>Order / subscription</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($list['rows'] === []): ?>
                <tr><td colspan="8" class="at-admin-table__empty">No completed gateway refunds in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($list['rows'] as $row): ?>
                <tr>
                    <td><?= Security::escape((string) ($row['request_number'] ?? '—')) ?></td>
                    <td><?= Security::escape(SalesReportService::refundStatusLabel($row['request_status'] ?? null)) ?></td>
                    <td><?= admin_status_badge((string) ($row['gateway_status'] ?? '')) ?></td>
                    <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($row['amount_inr'] ?? 0))) ?></td>
                    <td><?= Security::escape((string) ($row['gateway_refund_id'] ?? '—')) ?></td>
                    <td><?= Security::escape(RefundReportService::formatRefundTimestamp($row['completed_at'] ?? null)) ?></td>
                    <td><?= Security::escape((string) ($row['period_date_basis'] ?? '')) ?></td>
                    <td><?= Security::escape((string) ($row['order_number'] ?? $row['subscription_number'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    admin_report_pagination($page, (int) $list['total'], RefundReportService::DETAIL_PER_PAGE, array_filter([
        'preset' => $range->preset(),
        'from' => $range->customFrom() ?: null,
        'to' => $range->customTo() ?: null,
    ]));
    ?>
</section>

<?php endif; ?>
<p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reports/index.php">← All reports</a></p>
<?php admin_layout_end(); ?>
