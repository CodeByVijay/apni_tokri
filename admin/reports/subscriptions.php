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
    header('Location: ' . url_base() . '/admin/reports/subscriptions.php');
    exit;
}
/** @var ReportDateRange $range */
$range = $parsed['range'];
$page = max(1, (int) ($_GET['page'] ?? 1));

$status = [];
$activation = [];
$collection = [];
$pipeline = [];
$fulfilment = [];
$courier = [];
$generated = [];
$list = ['rows' => [], 'total' => 0];
if (Install::isSchemaReady()) {
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
    $list = SubscriptionReportService::capturedPayments($range, $page);
}

admin_layout_start(['pageTitle' => 'Subscription report', 'activeAdminNav' => 'reports']);
admin_flash_render();
$pageHeading = 'Subscription report';
$pageDescription = 'Activations, subscription payment collection, delivery schedule, and fulfilment orders (not storefront revenue).';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

admin_report_filters([
    'preset' => $range->preset(),
    'from' => $_GET['from'] ?? $range->customFrom(),
    'to' => $_GET['to'] ?? $range->customTo(),
    'form_action' => url_base() . '/admin/reports/subscriptions.php',
]);
?>
<div class="flex flex-wrap gap-2 mb-4 items-center">
    <span class="text-sm at-muted"><?= Security::escape($range->label()) ?></span>
    <?php admin_report_export_form('subscriptions', $range); ?>
</div>

<?php if (!Install::isSchemaReady()): ?>
    <div class="at-alert at-alert--info">Schema not ready.</div>
<?php else: ?>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Status snapshot <span class="text-xs font-normal at-muted">(point in time)</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <?php
        $snap = [
            'Active' => (int) ($status['active'] ?? 0),
            'Paused' => (int) ($status['paused'] ?? 0),
            'Pending payment' => (int) ($status['pending_payment'] ?? 0),
            'Payment failed' => (int) ($status['payment_failed'] ?? 0),
        ];
        foreach ($snap as $label => $val): ?>
            <div class="at-card"><div class="at-card__body">
                <p class="text-xs at-muted"><?= Security::escape($label) ?></p>
                <p class="text-xl font-semibold"><?= $val ?></p>
            </div></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Activations & collection</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3">
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Activations <span class="at-muted">(activated_at)</span></p>
            <p class="text-xl font-semibold"><?= (int) ($activation['subscription_activations'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Expected activation amount</p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($activation['expected_activation_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Collected <span class="at-muted">(captured_at)</span></p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($collection['collected_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Pending pipeline <span class="at-muted">(created_at attempts)</span></p>
            <p class="text-xl font-semibold"><?= Security::escape(catalogue_format_price((float) ($pipeline['pending_pipeline_amount_inr'] ?? 0))) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Duplicate captured payments (subs)</p>
            <p class="text-xl font-semibold"><?= (int) ($collection['duplicate_captured_subscription_count'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Legacy captured (NULL captured_at)</p>
            <p class="text-xl font-semibold"><?= (int) ($collection['legacy_captured_null_timestamp_count'] ?? 0) ?></p>
        </div></div>
    </div>
</section>

<section class="mb-6">
    <h2 class="text-lg font-semibold mb-2">Fulfilment <span class="text-xs font-normal at-muted">(scheduled delivery date: <?= Security::escape($range->inclusiveStartYmd()) ?> – <?= Security::escape($range->inclusiveEndYmd()) ?>)</span></h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Scheduled days</p>
            <p class="text-xl font-semibold"><?= (int) ($fulfilment['scheduled_delivery_days'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Delivered (by schedule date)</p>
            <p class="text-xl font-semibold"><?= (int) ($fulfilment['delivered_by_schedule_date'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Skipped / cancelled</p>
            <p class="text-xl font-semibold"><?= (int) ($fulfilment['skipped_delivery_days'] ?? 0) ?> / <?= (int) ($fulfilment['cancelled_delivery_days'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Missed (derived)</p>
            <p class="text-xl font-semibold"><?= (int) ($fulfilment['missed_delivery_days_derived'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Fulfilment rate</p>
            <p class="text-xl font-semibold"><?= (string) ($fulfilment['fulfilment_rate_pct'] ?? 0) ?>%</p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Courier completed <span class="at-muted">(delivered_at)</span></p>
            <p class="text-xl font-semibold"><?= (int) ($courier['subscription_courier_deliveries_completed'] ?? 0) ?></p>
        </div></div>
        <div class="at-card"><div class="at-card__body">
            <p class="text-xs at-muted">Generated fulfilment orders</p>
            <p class="text-xl font-semibold"><?= (int) ($generated['generated_subscription_orders'] ?? 0) ?></p>
        </div></div>
    </div>
    <p class="text-xs at-muted mt-2"><?= Security::escape((string) ($fulfilment['missed_derivation'] ?? '')) ?>. Fulfilment orders are not storefront revenue.</p>
</section>

<section>
    <h2 class="text-lg font-semibold mb-2">Captured subscription payments <span class="text-xs font-normal at-muted">(capture date: subscription_payments.captured_at)</span></h2>
    <p class="text-sm at-muted mb-2"><?= (int) $list['total'] ?> payment(s)</p>
    <div class="at-admin-table-wrap">
        <table class="at-admin-table">
            <thead>
                <tr>
                    <th>Subscription</th><th>Gateway</th><th class="is-num">Amount</th><th>Gateway ref</th>
                    <th>Activated at</th><th>Captured at</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($list['rows'] === []): ?>
                <tr><td colspan="6" class="at-admin-table__empty">No captured subscription payments in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($list['rows'] as $row): ?>
                <tr>
                    <td><?= Security::escape((string) ($row['subscription_number'] ?? '')) ?></td>
                    <td><?= Security::escape((string) ($row['gateway'] ?? '')) ?></td>
                    <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($row['amount'] ?? 0))) ?></td>
                    <td><?= Security::escape((string) ($row['gateway_payment_id'] ?? '—')) ?></td>
                    <td><?= Security::escape(SubscriptionReportService::formatSubscriptionTimestamp($row['activated_at'] ?? null)) ?></td>
                    <td><?= Security::escape(SubscriptionReportService::formatSubscriptionTimestamp($row['captured_at'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    admin_report_pagination($page, (int) $list['total'], SubscriptionReportService::PAYMENTS_PER_PAGE, array_filter([
        'preset' => $range->preset(),
        'from' => $range->customFrom() ?: null,
        'to' => $range->customTo() ?: null,
    ]));
    ?>
</section>

<?php endif; ?>
<p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/reports/index.php">← All reports</a></p>
<?php admin_layout_end(); ?>
