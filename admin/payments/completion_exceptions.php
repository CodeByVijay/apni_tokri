<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('payments');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['exception_id'] ?? 0);
    $note = trim((string) ($_POST['resolution_note'] ?? ''));
    if ($action === 'dismissed') {
        AdminDemoPerms::denyIfCannot('refunds');
    }
    if ($id > 0 && mb_strlen($note) >= 10 && in_array($action, ['resolved_refund', 'resolved_manual', 'dismissed'], true)) {
        $status = $action === 'dismissed' ? PaymentCompletionExceptionService::STATUS_DISMISSED
            : ($action === 'resolved_refund' ? PaymentCompletionExceptionService::STATUS_RESOLVED_REFUND
            : PaymentCompletionExceptionService::STATUS_RESOLVED_MANUAL);
        if (PaymentCompletionExceptionService::resolve($id, $status, $note, AdminAuth::id())) {
            admin_flash_set('Exception resolved and audit note saved on order history.');
        } else {
            admin_flash_set('Could not update exception (already resolved or note too short).', 'error');
        }
    } elseif ($id > 0 && $note !== '') {
        admin_flash_set('Resolution note must be at least 10 characters.', 'error');
    }
    header('Location: ' . url_base() . '/admin/payments/completion_exceptions.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Payment completion exceptions', 'activeAdminNav' => 'payments']);
admin_flash_render();
$pageHeading = 'Payment completion exceptions';
$pageDescription = 'Gateway captured funds but order fulfilment could not complete (delivery, inventory, or address drift). Dismissal requires Refunds permission and a written reason. Refund path: use Admin → Refunds with the order after marking resolved.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$rows = Install::isSchemaReady() ? PaymentCompletionExceptionService::listRecent(null, 100) : [];
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/payments/index.php">← Payment history</a>
 · <a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/refunds/index.php">Refund requests</a>
 · <a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/refunds/reconcile.php">Refund reconciliation</a></p>
<?php if (!PaymentCompletionExceptionService::tableReady()): ?>
    <p class="text-sm at-muted">Apply migration 1.8.0_d2_hardening.sql to enable this view.</p>
<?php elseif ($rows === []): ?>
    <p class="text-sm at-muted">No completion exceptions recorded.</p>
<?php else: ?>
<div class="at-admin-table-wrap">
    <table class="at-admin-table">
        <thead><tr>
            <th>Order</th><th>Gateway ref</th><th class="is-num">Amount</th><th>Category</th><th>Slot / date</th><th>Reason</th><th>Status</th><th>Resolve</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="is-id"><a href="<?= Security::escape(url_base()) ?>/admin/orders/view.php?id=<?= (int) $r['order_id'] ?>"><?= Security::escape((string) $r['order_number']) ?></a></td>
                <td><?= Security::escape((string) $r['gateway_payment_id']) ?></td>
                <td class="is-num">₹<?= number_format((float) $r['captured_amount_inr'], 2) ?></td>
                <td><?= Security::escape((string) ($r['failure_category'] ?? '—')) ?></td>
                <td><?= (int) ($r['delivery_slot_id'] ?? 0) ?> / <?= Security::escape((string) ($r['delivery_date'] ?? '—')) ?></td>
                <td><?= Security::escape((string) $r['failure_reason']) ?></td>
                <td><?= admin_status_badge((string) $r['status']) ?></td>
                <td>
                    <?php if ((string) $r['status'] === PaymentCompletionExceptionService::STATUS_OPEN): ?>
                    <form method="post" class="space-y-1">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="exception_id" value="<?= (int) $r['id'] ?>">
                        <input name="resolution_note" class="at-input at-focusable text-xs w-full" placeholder="Resolution note (min 10 chars)" required minlength="10">
                        <div class="flex flex-wrap gap-1">
                            <button type="submit" name="action" value="resolved_manual" class="at-btn at-btn--sm at-focusable">Manual fulfilment</button>
                            <button type="submit" name="action" value="resolved_refund" class="at-btn at-btn--sm at-focusable" title="Then open Refunds for this order">Refund path</button>
                            <button type="submit" name="action" value="dismissed" class="at-btn at-btn--sm at-focusable">Dismiss (refunds perm)</button>
                        </div>
                    </form>
                    <?php else: ?>
                        <?= Security::escape((string) ($r['resolution_note'] ?? '')) ?>
                        <?php if ((string) $r['status'] === PaymentCompletionExceptionService::STATUS_RESOLVED_REFUND): ?>
                            <a class="underline text-xs" href="<?= Security::escape(url_base()) ?>/admin/refunds/index.php">Refunds</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php admin_layout_end(); ?>
