<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('orders');

$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$order = $orderId > 0 && Install::isSchemaReady() ? AdminOrderService::findById($orderId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order !== null) {
    Csrf::requireValid();
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
admin_flash_render();

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

        <?php if (($order['payments'] ?? []) !== []): ?>
        <div class="at-card"><div class="at-card__body">
            <h3 class="font-bold mb-2">Payment attempts</h3>
            <div class="at-admin-table-wrap">
                <table class="at-admin-table at-admin-table--dense">
                    <thead><tr>
                        <th>Gateway</th><th>Status</th><th class="is-num">Amount</th><th>Gateway ref</th><th>Updated</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($order['payments'] as $pay): ?>
                        <tr>
                            <td><?= Security::escape((string) ($pay['gateway'] ?? '')) ?></td>
                            <td><?= admin_status_badge((string) ($pay['status'] ?? '')) ?></td>
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

    <div class="at-card"><div class="at-card__body space-y-3">
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
<?php admin_layout_end(); ?>
