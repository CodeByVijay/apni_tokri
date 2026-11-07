<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('orders');

$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$order = $orderId > 0 && Install::isSchemaReady() ? AdminOrderService::findById($orderId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order !== null) {
    Csrf::requireValid();
    $postAction = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if ($postAction === 'recover_payment_fulfilment') {
        $adminId = AdminAuth::id();
        if ($adminId === null) {
            admin_flash_set('Admin session required.', 'error');
        } else {
            $exceptionId = isset($_POST['exception_id']) ? (int) $_POST['exception_id'] : 0;
            $slotId = isset($_POST['recovery_slot_id']) ? (int) $_POST['recovery_slot_id'] : 0;
            $deliveryDate = isset($_POST['recovery_delivery_date']) && is_string($_POST['recovery_delivery_date'])
                ? trim($_POST['recovery_delivery_date']) : '';
            $confirmNote = isset($_POST['recovery_confirm_note']) && is_string($_POST['recovery_confirm_note'])
                ? trim($_POST['recovery_confirm_note']) : '';
            $result = PaymentFulfilmentReconciliationService::adminReassignSlotAndCompleteFulfilment(
                $orderId,
                $exceptionId,
                $slotId,
                $deliveryDate,
                (int) $adminId,
                $confirmNote
            );
            admin_flash_set(
                (string) ($result['message'] ?? ($result['ok'] ? 'Recovery completed.' : 'Recovery failed.')),
                !empty($result['ok']) ? 'success' : 'error'
            );
        }
        header('Location: ' . url_base() . '/admin/orders/view.php?id=' . $orderId, true, 302);
        exit;
    }
    if ($postAction === 'refresh_cashfree_payment') {
        $adminId = AdminAuth::id();
        if ($adminId === null) {
            admin_flash_set('Admin session required.', 'error');
        } else {
            $refresh = PaymentService::adminRefreshCashfreePayment((string) $order['order_number'], (int) $adminId);
            $msg = (string) ($refresh['message'] ?? '');
            if ($msg === '' && ($refresh['state'] ?? '') === 'confirmed') {
                $msg = !empty($refresh['idempotent'])
                    ? 'Payment already marked paid (no duplicate fulfilment).'
                    : 'Payment confirmed from Cashfree.';
            } elseif ($msg === '' && ($refresh['state'] ?? '') === 'pending') {
                $msg = 'Cashfree still shows pending — customer can retry payment.';
            } elseif ($msg === '' && ($refresh['state'] ?? '') === 'failed') {
                $msg = 'Cashfree reports a failed payment; order updated.';
            } elseif ($msg === '' && ($refresh['state'] ?? '') === 'noop') {
                $msg = 'No Cashfree attempts to reconcile.';
            } elseif ($msg === '' && ($refresh['state'] ?? '') === 'reconciliation') {
                $msg = 'Payment captured at gateway but fulfilment needs manual review.';
            } elseif ($msg === '') {
                $msg = 'Could not refresh payment status.';
            }
            admin_flash_set($msg, !empty($refresh['ok']) ? 'success' : 'error');
        }
        header('Location: ' . url_base() . '/admin/orders/view.php?id=' . $orderId, true, 302);
        exit;
    }
    $result = AdminOrderService::updateStatus(
        $orderId,
        (string) ($_POST['status'] ?? ''),
        (string) ($_POST['note'] ?? '')
    );
    admin_flash_set($result['message'] ?? ($result['ok'] ? 'Saved.' : 'Failed.'), !empty($result['ok']) ? 'success' : 'error');
    header('Location: ' . url_base() . '/admin/orders/view.php?id=' . $orderId, true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Order detail', 'activeAdminNav' => 'orders']);

if ($order === null) {
    echo '<div class="at-alert at-alert--error">Order not found.</div>';
    echo '<p class="mt-3"><a class="underline" href="' . Security::escape(url_base()) . '/admin/orders/index.php">← Back to orders</a></p>';
    admin_layout_end();
    exit;
}

$pageHeading = (string) $order['order_number'];
$pageDescription = 'Placed ' . (string) ($order['placed_at'] ?? '');
$breadcrumbs = [
    ['label' => 'Orders', 'href' => url_base() . '/admin/orders/index.php'],
    ['label' => (string) $order['order_number']],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/orders/index.php">← Back to orders</a></p>

<form method="post" class="grid lg:grid-cols-3 gap-4">
    <?= Csrf::field() ?>
    <div class="lg:col-span-2 space-y-4">
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <p><strong>Customer:</strong>
                <?= Security::escape(trim((string) ($order['customer_name'] ?? '')) !== '' ? (string) $order['customer_name'] : 'Guest') ?>
                <?php if ((string) ($order['customer_mobile'] ?? '') !== ''): ?>
                    · <?= Security::escape((string) $order['customer_mobile']) ?>
                <?php endif; ?>
            </p>
            <?php if ((string) ($order['customer_email'] ?? '') !== ''): ?>
                <p><strong>Email:</strong> <?= Security::escape((string) $order['customer_email']) ?></p>
            <?php endif; ?>
            <p><strong>Address<?= (string) ($order['address_label'] ?? '') !== '' ? ' (' . Security::escape((string) $order['address_label']) . ')' : '' ?>:</strong>
                <?= Security::escape((string) ($order['address_line'] ?? '—')) ?></p>
            <p><strong>Delivery:</strong>
                <?= Security::escape((string) ($order['delivery_slot'] ?? '—')) ?>
                <?php if ((string) ($order['delivery_date'] ?? '') !== ''): ?>
                    · <?= Security::escape((string) $order['delivery_date']) ?>
                <?php endif; ?>
            </p>
            <p class="flex flex-wrap gap-2 items-center">
                <strong>Order status:</strong> <?= admin_status_badge((string) $order['status']) ?>
                <strong>Payment:</strong> <?= admin_status_badge((string) $order['payment_status']) ?>
                <span class="at-muted">· <?= Security::escape((string) ($order['order_source'] ?? '')) ?></span>
            </p>
            <?php
            $cancelEvt = OrderCancellationService::cancellationEventForOrder($orderId);
            if ($cancelEvt !== null):
                $by = (string) ($cancelEvt['requested_by'] ?? '');
                $reasonCode = (string) ($cancelEvt['reason_code'] ?? '');
                $reasonLabel = OrderCancellationService::CUSTOMER_REASONS[$reasonCode] ?? $reasonCode;
                ?>
            <div class="mt-3 p-3 rounded-lg bg-[var(--at-surface-2)] text-sm space-y-1">
                <p><strong>Cancellation</strong> · <?= Security::escape(ucfirst($by)) ?> · <?= Security::escape((string) ($cancelEvt['created_at'] ?? '')) ?></p>
                <p><strong>Reason:</strong> <?= Security::escape($reasonLabel) ?>
                    <?php if (trim((string) ($cancelEvt['reason_detail'] ?? '')) !== ''): ?>
                        — <?= Security::escape(trim((string) $cancelEvt['reason_detail'])) ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            <?php
            $adminInv = null;
            if (Install::isSchemaReady()) {
                $adminInv = InvoiceService::syncForOrder($orderId)['invoice'];
            }
            ?>
            <p><strong>Invoice:</strong>
                <?php if ($adminInv !== null): ?>
                    <a class="underline" href="<?= Security::escape(url_base()) ?>/admin/invoices/view.php?id=<?= (int) $adminInv['id'] ?>"><?= Security::escape((string) $adminInv['invoice_number']) ?></a>
                <?php else: ?>
                    <span class="at-muted">Not generated yet</span>
                <?php endif; ?>
            </p>
            <?php if (!empty($order['delivery_id'])): ?>
                <p><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/deliveries/view.php?id=<?= (int) $order['delivery_id'] ?>">Open delivery fulfilment</a></p>
            <?php endif; ?>
        </div></div>

        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold">Items</h3>
            <?php if (($order['items'] ?? []) === []): ?>
                <p class="text-sm at-muted mt-2">No line items recorded.</p>
            <?php else: ?>
                <?php $orderItems = $order['items']; require APNI_TOKRI_ROOT . '/includes/partials/order-items.php'; ?>
            <?php endif; ?>
            <dl class="at-order-totals">
                <div><dt>Subtotal</dt><dd><?= Security::escape(catalogue_format_price((float) ($order['subtotal'] ?? 0))) ?></dd></div>
                <div><dt>Discount<?= !empty($order['coupon_code']) ? ' (' . Security::escape((string) $order['coupon_code']) . ')' : '' ?></dt><dd><?= (float) ($order['discount_total'] ?? 0) > 0 ? '−' : '' ?><?= Security::escape(catalogue_format_price((float) ($order['discount_total'] ?? 0))) ?></dd></div>
                <div><dt>Delivery fee</dt><dd><?= (float) ($order['delivery_fee'] ?? 0) === 0.0 ? 'Free' : Security::escape(catalogue_format_price((float) $order['delivery_fee'])) ?></dd></div>
                <div class="is-grand"><dt>Grand total</dt><dd><?= Security::escape(catalogue_format_price((float) ($order['grand_total'] ?? 0))) ?></dd></div>
            </dl>
        </div></div>

        <?php
        $paymentRecovery = PaymentFulfilmentReconciliationService::adminRecoveryContext($orderId);
        $recoverySlots = $paymentRecovery !== null
            ? PaymentFulfilmentReconciliationService::listReplacementSlotOptions($orderId)
            : [];
        ?>
        <?php if ($paymentRecovery !== null): ?>
        <div class="at-card border-amber-200 bg-amber-50/40"><div class="at-card__body space-y-3">
            <h3 class="font-bold text-amber-950">Payment captured — fulfilment reconciliation</h3>
            <p class="text-sm">Cashfree captured <strong><?= Security::escape(catalogue_format_price((float) $paymentRecovery['captured_amount_inr'])) ?></strong>.
                Do not create another payment session. Assign a delivery slot and complete fulfilment below.</p>
            <p class="text-sm at-muted"><strong>Failure:</strong> <?= Security::escape((string) $paymentRecovery['failure_reason']) ?></p>
            <p class="text-xs font-mono at-muted">Gateway payment: <?= Security::escape((string) $paymentRecovery['gateway_payment_id']) ?></p>
            <?php if ($recoverySlots === []): ?>
                <p class="text-sm at-alert at-alert--warn">No replacement slots with capacity right now. Free capacity or add a slot, then retry.</p>
            <?php else: ?>
                <form method="post" class="space-y-3 border-t border-amber-200/80 pt-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="recover_payment_fulfilment">
                    <input type="hidden" name="exception_id" value="<?= (int) $paymentRecovery['exception_id'] ?>">
                    <label class="at-label" for="recovery_slot_id">Replacement delivery slot</label>
                    <select id="recovery_slot_id" name="recovery_slot_id" class="at-select at-focusable w-full" required>
                        <option value="">Choose a slot…</option>
                        <?php foreach ($recoverySlots as $opt): ?>
                            <option value="<?= (int) $opt['id'] ?>" data-date="<?= Security::escape((string) $opt['delivery_date']) ?>">
                                <?= Security::escape((string) $opt['label']) ?> · <?= Security::escape((string) $opt['window']) ?>
                                · <?= Security::escape((string) $opt['delivery_date']) ?>
                                (<?= (int) $opt['remaining'] ?> left)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="recovery_delivery_date" id="recovery_delivery_date" value="">
                    <label class="at-label" for="recovery_confirm_note">Confirmation note (required)</label>
                    <textarea id="recovery_confirm_note" name="recovery_confirm_note" class="at-textarea at-focusable" rows="2" required
                              placeholder="e.g. Customer agreed to morning slot 8 Mar after expired hold."></textarea>
                    <p class="text-xs at-muted">This reserves the slot, re-runs server-side payment completion, and marks the exception resolved. Idempotent if already confirmed.</p>
                    <button type="submit" class="at-btn at-btn--primary at-focusable" onclick="return confirm('Complete fulfilment for this captured payment with the selected slot?');">
                        Complete fulfilment with selected slot
                    </button>
                </form>
                <script>
                (function () {
                    var sel = document.getElementById('recovery_slot_id');
                    var dateInput = document.getElementById('recovery_delivery_date');
                    if (!sel || !dateInput) return;
                    var sync = function () {
                        var opt = sel.options[sel.selectedIndex];
                        dateInput.value = opt && opt.dataset.date ? opt.dataset.date : '';
                    };
                    sel.addEventListener('change', sync);
                    sync();
                })();
                </script>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>

        <?php if (($order['payments'] ?? []) !== []): ?>
        <?php
        $hasCashfreeAttempt = false;
        foreach ($order['payments'] as $payRow) {
            if ((string) ($payRow['gateway'] ?? '') === 'cashfree') {
                $hasCashfreeAttempt = true;
                break;
            }
        }
        $canRefreshCashfree = $hasCashfreeAttempt
            && (string) ($order['payment_status'] ?? '') !== 'paid'
            && $paymentRecovery === null
            && CashfreeGateway::isConfigured();
        ?>
        <div class="at-card"><div class="at-card__body">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                <h3 class="font-bold">Payment attempts</h3>
                <?php if ($canRefreshCashfree): ?>
                    <button type="submit" form="admin-order-refresh-cashfree" name="action" value="refresh_cashfree_payment" class="at-btn at-btn--secondary at-btn--sm at-focusable">Refresh payment status</button>
                <?php endif; ?>
            </div>
            <p class="text-xs at-muted mb-2">Re-queries Cashfree server-side (live/sandbox per gateway settings). Does not expose credentials.</p>
            <div class="at-admin-table-wrap">
                <table class="at-admin-table at-admin-table--dense">
                    <thead><tr>
                        <th>Gateway</th><th>Status</th><th class="is-num">Amount</th><th>Gateway ref</th><th>Updated</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($order['payments'] as $pay): ?>
                        <tr>
                            <td><?= Security::escape((string) ($pay['gateway'] ?? '')) ?></td>
                            <td><?= admin_status_badge((string) ($pay['status'] ?? ''), PaymentService::paymentAttemptStatusLabel((string) ($pay['status'] ?? ''))) ?></td>
                            <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($pay['amount'] ?? 0))) ?></td>
                            <td class="is-id"><?= Security::escape((string) ($pay['gateway_payment_id'] ?? $pay['gateway_order_id'] ?? '—')) ?></td>
                            <td class="is-nowrap"><?= Security::escape((string) ($pay['updated_at'] ?? $pay['created_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div></div>
        <?php endif; ?>

        <?php if (($order['history'] ?? []) !== []): ?>
        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Status history</h3>
            <ul class="text-sm space-y-2">
                <?php foreach ($order['history'] as $h): ?>
                    <li>
                        <?= admin_status_badge((string) ($h['status'] ?? '')) ?>
                        <span class="at-muted">· <?= Security::escape((string) ($h['created_at'] ?? '')) ?></span>
                        <?php if (!empty($h['admin_name'])): ?>
                            <span class="at-muted">· <?= Security::escape((string) $h['admin_name']) ?></span>
                        <?php endif; ?>
                        <?php if (trim((string) ($h['note'] ?? '')) !== ''): ?>
                            <span class="block at-muted mt-0.5"><?= Security::escape((string) $h['note']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="at-card lg:col-span-1 self-start"><div class="at-card__body space-y-3">
        <h3 class="font-bold">Update status</h3>
        <label class="at-label" for="status">Order status</label>
        <select id="status" name="status" class="at-select at-focusable" required>
            <?php
            $current = (string) ($order['status'] ?? '');
            $statusOptions = AdminOrderService::adminUpdatableStatuses();
            if ($current !== '' && !in_array($current, $statusOptions, true)) {
                array_unshift($statusOptions, $current);
            }
            foreach ($statusOptions as $st):
                ?>
                <option value="<?= Security::escape($st) ?>" <?= $current === $st ? 'selected' : '' ?>>
                    <?= Security::escape(AdminOrderService::statusLabel($st)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label class="at-label" for="note">Internal note</label>
        <textarea id="note" name="note" class="at-textarea at-focusable" rows="3" placeholder="Optional note for audit trail"></textarea>
        <button type="submit" class="at-btn at-btn--primary at-focusable at-btn--block">Save</button>
        <p class="text-xs at-muted">Payment status is not changed here. Use Payments / Refunds modules for gateway actions.</p>
    </div></div>
</form>
<form method="post" id="admin-order-refresh-cashfree" class="hidden" aria-hidden="true" tabindex="-1">
    <?= Csrf::field() ?>
</form>
<?php admin_layout_end(); ?>
