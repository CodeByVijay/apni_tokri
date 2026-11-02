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
    header('Location: ' . url_base() . '/admin/reports/payments.php');
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$page = max(1, (int) ($_GET['page'] ?? 1));

$attempts = ['rows' => [], 'total' => 0];
$attemptSummary = [];
$collection = [];
$recon = [];
if (Install::isSchemaReady()) {
    $attemptSummary = PaymentReportService::attemptSummary($range);
    $collection = PaymentReportService::collectionSummary($range);
    $recon = PaymentReportService::reconciliationSummary($range);
    $attempts = PaymentReportService::paymentAttempts($range, $page);
}

admin_layout_start(['pageTitle' => 'Payment report', 'activeAdminNav' => 'reports']);
admin_flash_render();
$pageHeading = 'Payment report';
$pageDescription = 'Storefront payment attempts and confirmed collections (Asia/Kolkata). Subscription fulfilment orders excluded.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

admin_report_filters([
    'preset' => $range->preset(),
    'from' => $_GET['from'] ?? $range->customFrom(),
    'to' => $_GET['to'] ?? $range->customTo(),
    'form_action' => url_base() . '/admin/reports/payments.php',
]);
?>
<div class="flex flex-wrap gap-2 mb-4 items-center">
    <span class="text-sm at-muted"><?= Security::escape($range->label()) ?></span>
    <?php admin_report_export_form('payments', $range); ?>
</div>

<?php if (!Install::isSchemaReady()): ?>
    <div class="at-alert at-alert--info">Schema not ready.</div>
<?php else: ?>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Attempt cohort <span class="text-xs font-normal at-muted">(date basis: payments.created_at)</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php
        $attemptCards = [
            'Total attempts' => (string) ($attemptSummary['total_attempts'] ?? 0),
            'Captured attempts' => (string) ($attemptSummary['captured_attempts'] ?? 0),
            'Failed attempts' => (string) ($attemptSummary['failed_attempts'] ?? 0),
            'Pending attempts' => (string) ($attemptSummary['pending_attempts'] ?? 0),
            'Success rate (cohort)' => (string) ($attemptSummary['attempt_cohort_success_rate_pct'] ?? 0) . '%',
        ];
        foreach ($attemptCards as $label => $val): ?>
            <div class="at-card"><div class="at-card__body">
                <p class="text-xs at-muted"><?= Security::escape($label) ?></p>
                <p class="text-xl font-semibold"><?= Security::escape($val) ?></p>
            </div></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Confirmed collections <span class="text-xs font-normal at-muted">(date basis: payments.captured_at)</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3">
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Collection amount</p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($collection['captured_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Unique paid orders</p>
            <p class="text-xl font-semibold"><?= (int) ($collection['unique_paid_order_count'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Duplicate captured rows (orders)</p>
            <p class="text-xl font-semibold"><?= (int) ($collection['duplicate_captured_order_count'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Legacy captured (NULL captured_at)</p>
            <p class="text-xl font-semibold"><?= (int) ($collection['legacy_captured_null_timestamp_count'] ?? 0) ?>
                <span class="text-sm font-normal"> · <?= Security::escape(catalogue_format_price((float) ($collection['legacy_captured_null_timestamp_amount_inr'] ?? 0))) ?></span>
            </p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Open reconciliation exceptions</p>
            <p class="text-xl font-semibold"><?= (int) ($recon['open_exceptions'] ?? 0) ?></p>
        </div></div>
    </div>
    <?php if (!empty($collection['gateway_breakdown'])): ?>
        <div class="at-card overflow-x-auto mb-3">
            <div class="at-card__body">
                <p class="text-sm font-medium mb-2">Gateway collections (capture period)</p>
                <table class="at-admin-table at-admin-table--dense">
                    <thead><tr><th>Gateway</th><th class="is-num">Payments</th><th class="is-num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($collection['gateway_breakdown'] as $gw): ?>
                        <tr>
                            <td><?= Security::escape((string) ($gw['gateway'] ?? '')) ?></td>
                            <td class="is-num"><?= (int) ($gw['payment_count'] ?? 0) ?></td>
                            <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($gw['amount_inr'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <p class="text-xs at-muted">Reconciliation-required payments are excluded from collection totals. Resolved exceptions: <?= (int) ($recon['resolved_exceptions'] ?? 0) ?>.</p>
</section>

<section>
    <h2 class="text-lg font-semibold mb-2">Payment attempts <span class="text-xs font-normal at-muted">(filtered by attempt date: created_at)</span></h2>
    <p class="text-sm at-muted mb-2"><?= (int) $attempts['total'] ?> attempt(s) in period</p>
    <div class="at-admin-table-wrap">
        <table class="at-admin-table at-admin-table--dense">
            <thead>
                <tr>
                    <th>Order</th><th>Gateway</th><th>Status</th><th class="is-num">Amount</th><th>Gateway ref</th>
                    <th>Attempt at</th><th>Captured at</th><th>Reconciliation</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($attempts['rows'] === []): ?>
                <tr><td colspan="8" class="at-admin-table__empty">No payment attempts in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($attempts['rows'] as $row): ?>
                <tr>
                    <td class="is-id"><?= Security::escape((string) ($row['order_number'] ?? '')) ?></td>
                    <td><?= Security::escape((string) ($row['gateway'] ?? '')) ?></td>
                    <td><?= admin_status_badge((string) ($row['status'] ?? '')) ?></td>
                    <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($row['amount'] ?? 0))) ?></td>
                    <td><?= Security::escape((string) ($row['gateway_payment_id'] ?? '—')) ?></td>
                    <td><?= Security::escape(PaymentReportService::formatPaymentTimestamp($row['created_at'] ?? null)) ?></td>
                    <td><?= Security::escape(PaymentReportService::formatPaymentTimestamp($row['captured_at'] ?? null)) ?></td>
                    <td><?= Security::escape(PaymentReportService::reconciliationLabel($row)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    admin_report_pagination($page, (int) $attempts['total'], PaymentReportService::PAYMENTS_PER_PAGE, array_filter([
        'preset' => $range->preset(),
        'from' => $range->customFrom() ?: null,
        'to' => $range->customTo() ?: null,
    ]));
    ?>
</section>

<?php endif; ?>
<p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reports/index.php">← All reports</a></p>
<?php admin_layout_end(); ?>
