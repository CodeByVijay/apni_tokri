<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('refunds');

$bucket = isset($_GET['bucket']) && is_string($_GET['bucket']) ? trim($_GET['bucket']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $reqId = (int) ($_POST['request_id'] ?? 0);
    $prId = (int) ($_POST['payment_refund_id'] ?? 0);
    if ($action === 'retry' && $reqId > 0) {
        $result = RefundRequestService::retryGatewayRefund($reqId);
        admin_flash_set($result['ok'] ? ($result['message'] ?? 'Retried.') : ($result['message'] ?? 'Failed.'), $result['ok'] ? 'success' : 'error');
    } elseif ($action === 'retry_sub' && $prId > 0) {
        admin_flash_set('Subscription refund retry runs via cron reconcile.', 'success');
    }
    header('Location: ' . url_base() . '/admin/refunds/reconcile.php?bucket=' . rawurlencode($bucket));
    exit;
}

admin_layout_start(['pageTitle' => 'Refund reconciliation', 'activeAdminNav' => 'refunds']);
admin_flash_render();
$pageHeading = 'Refund reconciliation';
$pageDescription = 'Gateway completion requires webhook confirmation (except simulate mode).';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$buckets = [
    '' => 'All',
    'pending' => 'Pending',
    'processing' => 'Processing',
    'completed' => 'Completed',
    'failed' => 'Failed',
];
?>
<div class="flex flex-wrap gap-2 mb-4">
    <?php foreach ($buckets as $key => $label): ?>
        <a href="?bucket=<?= rawurlencode($key) ?>" class="at-btn at-btn--sm <?= $bucket === $key ? 'at-btn--primary' : 'at-btn--secondary' ?> at-focusable"><?= Security::escape($label) ?></a>
    <?php endforeach; ?>
    <a href="<?= Security::escape(url_base()) ?>/admin/refunds/index.php" class="at-btn at-btn--sm at-btn--secondary at-focusable ml-auto">Refund requests</a>
</div>
<?php
$rows = Install::isSchemaReady() ? RefundRequestService::listReconciliation($bucket !== '' ? $bucket : null) : [];
$columns = [
    ['label' => 'Idempotency', 'key' => 'idempotency_key'],
    ['label' => 'Order / Sub', 'key' => 'reference'],
    ['label' => 'Amount', 'key' => 'amount', 'align' => 'num'],
    ['label' => 'Gateway ref', 'key' => 'gw_ref'],
    ['label' => 'Status', 'key' => 'status'],
];
$tableRows = [];
foreach ($rows as $r) {
    $tableRows[] = [
        'idempotency_key' => (string) ($r['idempotency_key'] ?? ''),
        'reference' => (string) ($r['order_number'] ?? $r['subscription_number'] ?? '—'),
        'amount' => '₹' . number_format((float) ($r['amount_inr'] ?? 0), 2),
        'gw_ref' => (string) ($r['gateway_refund_id'] ?? '—'),
        'status' => (string) ($r['gateway_status'] ?? ''),
    ];
}
$rows = $tableRows;
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
$rows = Install::isSchemaReady() ? RefundRequestService::listReconciliation($bucket !== '' ? $bucket : null) : [];
?>
<div class="grid gap-3 mt-4">
    <?php foreach ($rows as $r): ?>
        <?php if ((string) ($r['request_status'] ?? '') === RefundStatus::REFUND_FAILED && !empty($r['refund_request_id'])): ?>
            <form method="post" class="flex items-center gap-3 text-sm">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="retry">
                <input type="hidden" name="request_id" value="<?= (int) $r['refund_request_id'] ?>">
                <span>Retry failed request <strong><?= Security::escape((string) ($r['request_number'] ?? '')) ?></strong></span>
                <button type="submit" class="at-btn at-btn--sm at-btn--secondary at-focusable">Safe retry</button>
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<p class="text-sm at-muted mt-4">Payment captured: order rows use payments.amount; subscription rows use subscription_payments. Completion requires gateway webhook unless simulate mode.</p>
<?php admin_layout_end(); ?>
