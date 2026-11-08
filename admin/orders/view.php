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
    if ($postAction === 'recover_payment_fulfilment_custom') {
        $adminId = AdminAuth::id();
        if ($adminId === null) {
            admin_flash_set('Admin session required.', 'error');
        } else {
            $exceptionId = isset($_POST['exception_id']) ? (int) $_POST['exception_id'] : 0;
            $deliveryDate = isset($_POST['custom_delivery_date']) && is_string($_POST['custom_delivery_date'])
                ? trim($_POST['custom_delivery_date']) : '';
            $customStart = isset($_POST['custom_start_time']) && is_string($_POST['custom_start_time'])
                ? trim($_POST['custom_start_time']) : '';
            $customEnd = isset($_POST['custom_end_time']) && is_string($_POST['custom_end_time'])
                ? trim($_POST['custom_end_time']) : '';
            $confirmNote = isset($_POST['custom_confirm_note']) && is_string($_POST['custom_confirm_note'])
                ? trim($_POST['custom_confirm_note']) : '';
            $customerConfirmed = !empty($_POST['custom_customer_confirmed']);
            $result = PaymentFulfilmentReconciliationService::adminCustomDeliveryAndCompleteFulfilment(
                $orderId,
                $exceptionId,
                $deliveryDate,
                $customStart,
                $customEnd,
                (int) $adminId,
                $confirmNote,
                $customerConfirmed
            );
            admin_flash_set(
                (string) ($result['message'] ?? ($result['ok'] ? 'Recovery completed.' : 'Recovery failed.')),
                !empty($result['ok']) ? 'success' : 'error'
            );
        }
        header('Location: ' . url_base() . '/admin/orders/view.php?id=' . $orderId, true, 302);
        exit;
    }
    if ($postAction === 'reschedule_delivery') {
        $adminId = AdminAuth::id();
        if ($adminId === null) {
            admin_flash_set('Admin session required.', 'error');
        } else {
            $slotId = isset($_POST['reschedule_slot_id']) ? (int) $_POST['reschedule_slot_id'] : 0;
            $deliveryDate = isset($_POST['reschedule_delivery_date']) && is_string($_POST['reschedule_delivery_date'])
                ? trim($_POST['reschedule_delivery_date']) : '';
            $confirmNote = isset($_POST['reschedule_confirm_note']) && is_string($_POST['reschedule_confirm_note'])
                ? trim($_POST['reschedule_confirm_note']) : '';
            $result = AdminOrderDeliveryRescheduleService::reschedule(
                $orderId,
                $slotId,
                $deliveryDate,
                (int) $adminId,
                $confirmNote
            );
            admin_flash_set(
                (string) ($result['message'] ?? ($result['ok'] ? 'Delivery rescheduled.' : 'Reschedule failed.')),
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
    if ($postAction === 'update_status' || ($postAction === '' && array_key_exists('status', $_POST))) {
        $result = AdminOrderService::updateStatus(
            $orderId,
            (string) ($_POST['status'] ?? ''),
            (string) ($_POST['note'] ?? '')
        );
        admin_flash_set($result['message'] ?? ($result['ok'] ? 'Saved.' : 'Failed.'), !empty($result['ok']) ? 'success' : 'error');
        header('Location: ' . url_base() . '/admin/orders/view.php?id=' . $orderId, true, 302);
        exit;
    }
    admin_flash_set('Unknown action.', 'error');
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
$pageDescription = 'Placed ' . customer_format_datetime((string) ($order['placed_at'] ?? ''));
$breadcrumbs = [
    ['label' => 'Orders', 'href' => url_base() . '/admin/orders/index.php'],
    ['label' => (string) $order['order_number']],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4 text-sm"><a class="underline at-focusable" href="<?= Security::escape(url_base()) ?>/admin/orders/index.php">← Back to orders</a></p>

<div class="at-admin-order-detail">
<div class="at-admin-order-detail__layout">
    <div class="at-admin-order-detail__main">
        <div class="at-card"><div class="at-card__body at-admin-order-detail__summary text-sm">
            <p class="at-admin-order-detail__status-line">
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
                <p><strong>Cancellation</strong> · <?= Security::escape(ucfirst($by)) ?> · <?= Security::escape(customer_format_datetime((string) ($cancelEvt['created_at'] ?? ''))) ?></p>
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
                <div class="at-admin-order-detail__items">
                <?php $orderItems = $order['items']; require APNI_TOKRI_ROOT . '/includes/partials/order-items.php'; ?>
                </div>
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
        $customRecoveryMinDate = DeliveryConfig::now()->format('Y-m-d');
        try {
            $customRecoveryMaxDate = DeliveryConfig::now()->modify('+60 days')->format('Y-m-d');
        } catch (Throwable $e) {
            $customRecoveryMaxDate = $customRecoveryMinDate;
        }
        $canOverrideDeliveryCapacity = Permission::can('deliveries.manage');
        ?>
        <?php if ($paymentRecovery !== null): ?>
        <section class="at-card at-admin-order-recon" aria-labelledby="at-admin-order-recon-title">
            <div class="at-card__body at-admin-order-recon__body">
                <h3 id="at-admin-order-recon-title" class="at-admin-order-recon__title">Payment captured — fulfilment reconciliation</h3>
                <p class="at-admin-order-recon__lead">Do not create another payment session. Assign a delivery slot and complete fulfilment below.</p>
                <dl class="at-admin-order-recon__meta">
                    <div>
                        <dt>Captured amount</dt>
                        <dd><?= Security::escape(catalogue_format_price((float) $paymentRecovery['captured_amount_inr'])) ?></dd>
                    </div>
                    <div class="at-admin-order-recon__meta--wide">
                        <dt>Gateway payment</dt>
                        <dd class="at-admin-order-recon__mono"><?= Security::escape((string) $paymentRecovery['gateway_payment_id']) ?></dd>
                    </div>
                </dl>
                <p class="at-admin-order-recon__failure"><strong>Failure:</strong> <?= Security::escape((string) $paymentRecovery['failure_reason']) ?></p>
                <?php if ($recoverySlots === []): ?>
                    <p class="at-alert at-alert--warning at-admin-order-recon__warn">No predefined replacement slots with capacity right now. Use a custom delivery window below or free capacity, then retry.</p>
                <?php else: ?>
                    <form method="post" class="at-admin-order-recon__actions" id="admin-order-payment-recovery">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="exception_id" value="<?= (int) $paymentRecovery['exception_id'] ?>">
                        <div class="at-admin-order-recon__field">
                            <label class="at-label" for="recovery_slot_id">Replacement delivery slot</label>
                            <select id="recovery_slot_id" name="recovery_slot_id" class="at-select at-focusable" required>
                                <option value="">Choose a slot…</option>
                                <?php foreach ($recoverySlots as $opt): ?>
                                    <option value="<?= (int) $opt['id'] ?>" data-date="<?= Security::escape((string) $opt['delivery_date']) ?>">
                                        <?= Security::escape((string) $opt['label']) ?> · <?= Security::escape((string) $opt['window']) ?>
                                        · <?= Security::escape((string) $opt['delivery_date']) ?>
                                        (<?= (int) $opt['remaining'] ?> left)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="recovery_delivery_date" id="recovery_delivery_date" value="">
                        <div class="at-admin-order-recon__field">
                            <label class="at-label" for="recovery_confirm_note">Confirmation note (required)</label>
                            <textarea id="recovery_confirm_note" name="recovery_confirm_note" class="at-textarea at-focusable" rows="3" required
                                      placeholder="e.g. Customer agreed to morning slot 8 Mar after expired hold."></textarea>
                        </div>
                        <p class="at-admin-order-recon__hint">Reserves the slot, re-runs server-side payment completion, and resolves the exception (idempotent if already confirmed).</p>
                        <button type="submit" name="action" value="recover_payment_fulfilment" class="at-btn at-btn--primary at-focusable at-admin-order-recon__submit"
                                onclick="return confirm('Complete fulfilment for this captured payment with the selected slot?');">
                            Complete fulfilment with selected slot
                        </button>
                    </form>
                <?php endif; ?>

                <div class="at-admin-order-recon__custom mt-6 pt-6 border-t border-[var(--at-line)]">
                    <h4 class="font-bold">Custom delivery time (Admin override)</h4>
                    <p class="text-sm at-muted mt-1">Assign a customer-agreed window. Reuses the captured payment — does not charge again.</p>
                    <?php if (!$canOverrideDeliveryCapacity): ?>
                        <p class="text-xs at-muted mt-1">If all slots are full, an admin with <strong>Deliveries</strong> permission must perform this action.</p>
                    <?php endif; ?>
                    <form method="post" class="at-admin-order-recon__actions mt-3" id="admin-order-payment-recovery-custom">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="exception_id" value="<?= (int) $paymentRecovery['exception_id'] ?>">
                        <div class="at-admin-order-recon__field">
                            <label class="at-label" for="custom_delivery_date">Delivery date</label>
                            <input type="date" id="custom_delivery_date" name="custom_delivery_date" class="at-input at-focusable" required
                                   min="<?= Security::escape($customRecoveryMinDate) ?>" max="<?= Security::escape($customRecoveryMaxDate) ?>">
                        </div>
                        <div class="at-admin-order-recon__field grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="at-label" for="custom_start_time">Custom start time</label>
                                <input type="time" id="custom_start_time" name="custom_start_time" class="at-input at-focusable" required>
                            </div>
                            <div>
                                <label class="at-label" for="custom_end_time">Custom end time</label>
                                <input type="time" id="custom_end_time" name="custom_end_time" class="at-input at-focusable" required>
                            </div>
                        </div>
                        <div class="at-admin-order-recon__field">
                            <label class="at-label" for="custom_confirm_note">Customer confirmation note (required)</label>
                            <textarea id="custom_confirm_note" name="custom_confirm_note" class="at-textarea at-focusable" rows="3" required
                                      placeholder="e.g. Customer called at 9:15 AM and agreed to delivery between 10–11 AM today."></textarea>
                        </div>
                        <label class="flex gap-2 items-start text-sm mt-2">
                            <input type="checkbox" name="custom_customer_confirmed" value="1" class="mt-1" required>
                            <span>I have confirmed this delivery time with the customer.</span>
                        </label>
                        <button type="submit" name="action" value="recover_payment_fulfilment_custom" class="at-btn at-btn--secondary at-focusable at-admin-order-recon__submit mt-3"
                                onclick="return confirm('Complete fulfilment with this custom delivery window?');">
                            Complete fulfilment with custom window
                        </button>
                    </form>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php
        $canRescheduleDelivery = AdminOrderDeliveryRescheduleService::canRescheduleOrder($order, $paymentRecovery);
        $rescheduleMinDate = DeliveryConfig::now()->format('Y-m-d');
        try {
            $rescheduleMaxDate = DeliveryConfig::now()->modify('+60 days')->format('Y-m-d');
        } catch (Throwable $e) {
            $rescheduleMaxDate = $rescheduleMinDate;
        }
        ?>
        <?php if ($canRescheduleDelivery): ?>
        <section class="at-card at-admin-order-recon" aria-labelledby="at-admin-order-reschedule-title">
            <div class="at-card__body at-admin-order-recon__body">
                <h3 id="at-admin-order-reschedule-title" class="at-admin-order-recon__title">Reschedule delivery</h3>
                <p class="at-admin-order-recon__lead text-sm at-muted mb-3">
                    Current:
                    <strong><?= Security::escape((string) ($order['delivery_slot_label'] ?? $order['delivery_slot'] ?? '—')) ?></strong>
                    <?php if ((string) ($order['delivery_slot_window'] ?? '') !== ''): ?>
                        · <?= Security::escape((string) $order['delivery_slot_window']) ?>
                    <?php endif; ?>
                    <?php if ((string) ($order['delivery_slot_times'] ?? '') !== ''): ?>
                        · <?= Security::escape((string) $order['delivery_slot_times']) ?>
                    <?php endif; ?>
                    <?php if ((string) ($order['delivery_date'] ?? '') !== ''): ?>
                        · <strong><?= Security::escape((string) $order['delivery_date']) ?></strong>
                    <?php endif; ?>
                </p>
                <form method="post" class="at-admin-order-recon__actions" id="admin-order-reschedule-delivery">
                    <?= Csrf::field() ?>
                    <div class="at-admin-order-recon__field">
                        <label class="at-label" for="reschedule_delivery_date">New delivery date</label>
                        <input type="date" id="reschedule_delivery_date" name="reschedule_delivery_date" class="at-input at-focusable"
                               min="<?= Security::escape($rescheduleMinDate) ?>" max="<?= Security::escape($rescheduleMaxDate) ?>" required>
                    </div>
                    <div class="at-admin-order-recon__field">
                        <label class="at-label" for="reschedule_slot_id">Available slot</label>
                        <select id="reschedule_slot_id" name="reschedule_slot_id" class="at-select at-focusable" required disabled>
                            <option value="">Choose a date first…</option>
                        </select>
                        <p id="reschedule_slots_status" class="text-xs at-muted mt-1" aria-live="polite"></p>
                    </div>
                    <div class="at-admin-order-recon__field">
                        <label class="at-label" for="reschedule_confirm_note">Customer confirmation / reason (required)</label>
                        <textarea id="reschedule_confirm_note" name="reschedule_confirm_note" class="at-textarea at-focusable" rows="3" required
                                  minlength="10" placeholder="e.g. Customer called — agreed to move to morning slot on 10 Nov."></textarea>
                    </div>
                    <p class="at-admin-order-recon__hint">Does not change payment status or start a new payment. Capacity is re-checked on submit.</p>
                    <button type="submit" name="action" value="reschedule_delivery" class="at-btn at-btn--primary at-focusable at-admin-order-recon__submit"
                            onclick="return confirm('Reschedule this order to the selected slot and date?');">
                        Confirm reschedule
                    </button>
                </form>
            </div>
        </section>
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
            <div class="at-admin-order-detail__section-head">
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
                            <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($pay['updated_at'] ?? $pay['created_at'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div></div>
        <?php endif; ?>

        <?php if (($order['history'] ?? []) !== []): ?>
        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-3">Status history</h3>
            <?php
            $history = $order['history'];
            require APNI_TOKRI_ROOT . '/includes/partials/admin/order-status-timeline.php';
            ?>
        </div></div>
        <?php endif; ?>
    </div>

    <aside class="at-admin-order-detail__aside">
    <div class="at-card at-admin-order-detail__customer-card"><div class="at-card__body at-admin-order-detail__customer text-sm">
        <h3 class="font-bold">Customer &amp; delivery</h3>
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
            <?php
            $delName = (string) ($order['delivery_slot_label'] ?? '');
            if ($delName === '') {
                $delName = (string) ($order['delivery_slot'] ?? '—');
            }
            ?>
            <?= Security::escape($delName) ?>
            <?php if ((string) ($order['delivery_slot_window'] ?? '') !== ''): ?>
                · <?= Security::escape((string) $order['delivery_slot_window']) ?>
            <?php endif; ?>
            <?php if ((string) ($order['delivery_slot_times'] ?? '') !== ''): ?>
                · <?= Security::escape((string) $order['delivery_slot_times']) ?>
            <?php endif; ?>
            <?php if ((string) ($order['delivery_date'] ?? '') !== ''): ?>
                · <?= Security::escape((string) $order['delivery_date']) ?>
            <?php endif; ?>
        </p>
    </div></div>
    <div class="at-card at-admin-order-detail__status-card"><div class="at-card__body at-admin-order-detail__status-form">
        <form method="post" id="admin-order-update-status">
            <?= Csrf::field() ?>
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
        <button type="submit" name="action" value="update_status" class="at-btn at-btn--primary at-focusable at-btn--block">Save</button>
        <p class="text-xs at-muted">Payment status is not changed here. Use Payments / Refunds modules for gateway actions.</p>
        </form>
    </div></div>
    </aside>
</div>
<form method="post" id="admin-order-refresh-cashfree" class="hidden" aria-hidden="true" tabindex="-1">
    <?= Csrf::field() ?>
</form>
<?php if ($paymentRecovery !== null && $recoverySlots !== []): ?>
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
<?php if (!empty($canRescheduleDelivery)): ?>
<script>
(function () {
    var dateEl = document.getElementById('reschedule_delivery_date');
    var slotEl = document.getElementById('reschedule_slot_id');
    var statusEl = document.getElementById('reschedule_slots_status');
    if (!dateEl || !slotEl) return;
    var orderId = <?= (int) $orderId ?>;
    var apiBase = <?= json_encode(url_base() . '/admin/ajax/order-reschedule-slots.php', JSON_UNESCAPED_UNICODE) ?>;
    var loading = false;
    var loadSlots = function () {
        var date = dateEl.value;
        slotEl.innerHTML = '';
        slotEl.disabled = true;
        if (!date) {
            var ph = document.createElement('option');
            ph.value = '';
            ph.textContent = 'Choose a date first…';
            slotEl.appendChild(ph);
            if (statusEl) statusEl.textContent = '';
            return;
        }
        if (loading) return;
        loading = true;
        if (statusEl) statusEl.textContent = 'Loading slots…';
        fetch(apiBase + '?order_id=' + encodeURIComponent(String(orderId)) + '&delivery_date=' + encodeURIComponent(date), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                loading = false;
                var slots = payload && payload.data && payload.data.slots ? payload.data.slots : [];
                if (!payload.success || slots.length === 0) {
                    var empty = document.createElement('option');
                    empty.value = '';
                    empty.textContent = 'No slots with capacity on this date';
                    slotEl.appendChild(empty);
                    var errMsg = payload.error && payload.error.message ? payload.error.message : (payload.message || 'No slots available.');
                    if (statusEl) statusEl.textContent = errMsg;
                    return;
                }
                var first = document.createElement('option');
                first.value = '';
                first.textContent = 'Choose a slot…';
                slotEl.appendChild(first);
                slots.forEach(function (s) {
                    var opt = document.createElement('option');
                    opt.value = String(s.id);
                    opt.textContent = s.label + ' · ' + s.window + ' (' + s.remaining + ' left)';
                    slotEl.appendChild(opt);
                });
                slotEl.disabled = false;
                if (statusEl) statusEl.textContent = slots.length + ' slot(s) available.';
            })
            .catch(function () {
                loading = false;
                var err = document.createElement('option');
                err.value = '';
                err.textContent = 'Could not load slots';
                slotEl.appendChild(err);
                if (statusEl) statusEl.textContent = 'Request failed. Try again.';
            });
    };
    dateEl.addEventListener('change', loadSlots);
})();
</script>
<?php endif; ?>
</div>
<?php admin_layout_end(); ?>
