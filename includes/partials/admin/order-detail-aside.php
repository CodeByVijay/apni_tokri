<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $order
 * @var int $orderId
 * @var array<string, mixed>|null $adminInv
 * @var bool $canRescheduleDelivery
 * @var string $rescheduleMinDate
 * @var string $rescheduleMaxDate
 */
?>
<aside class="at-admin-order-detail__aside">
    <div class="at-card at-admin-order-detail__status-card">
        <div class="at-card__body at-admin-order-detail__status-form">
            <form method="post" id="admin-order-update-status">
                <?= Csrf::field() ?>
                <h3 class="font-bold">Update order status</h3>
                <label class="at-label" for="status">Order status</label>
                <select id="status" name="status" class="at-select at-focusable" required>
                    <?php
                    $current = (string) ($order['status'] ?? '');
                    $statusOptions = AdminOrderService::allowedTargetsFor($current);
                    if ($current !== '') {
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
                <button type="submit" name="action" value="update_status" class="at-btn at-btn--primary at-focusable at-btn--block">Save status</button>
                <p class="text-xs at-muted mt-2">Payment status is updated only by payment verification, webhooks, or refund processing.</p>
            </form>
        </div>
    </div>

    <?php if ($adminInv !== null): ?>
        <div class="at-card">
            <div class="at-card__body text-sm">
                <h3 class="font-bold">Invoice</h3>
                <p class="mt-2"><a class="at-btn at-btn--secondary at-btn--block at-focusable text-center" href="<?= Security::escape(url_base()) ?>/admin/invoices/view.php?id=<?= (int) $adminInv['id'] ?>">View invoice</a></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canRescheduleDelivery): ?>
        <div class="at-card">
            <div class="at-card__body at-admin-order-recon__body text-sm">
                <h3 class="font-bold">Reschedule delivery</h3>
                <p class="at-muted text-xs mt-1 mb-3">Does not change payment status.</p>
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
                        <label class="at-label" for="reschedule_confirm_note">Customer confirmation / reason</label>
                        <textarea id="reschedule_confirm_note" name="reschedule_confirm_note" class="at-textarea at-focusable" rows="3" required minlength="10"></textarea>
                    </div>
                    <button type="submit" name="action" value="reschedule_delivery" class="at-btn at-btn--primary at-focusable at-btn--block"
                            onclick="return confirm('Reschedule this order to the selected slot and date?');">
                        Confirm reschedule
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</aside>
