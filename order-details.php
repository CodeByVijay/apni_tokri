<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    header('Location: ' . url_base() . '/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''), true, 302);
    exit;
}

$id = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$order = null;
if ($id !== '' && CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $order = OrderRepository::forCustomerByNumber($id, CustomerAuth::id());
}
if ($order === null && $id !== '') {
    $order = DemoCustomer::order($id);
}

if ($order === null) {
    layout_public_start(['pageTitle' => 'Order — Apni Tokri', 'activeNav' => 'account']);
    $emptyTitle = 'Order not found';
    $emptyMessage = 'Check the link or view all orders.';
    $emptyActionHref = url_base() . '/my-orders.php';
    $emptyActionLabel = 'My orders';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$refundMsg = '';
$refundErr = '';
$orderDelivered = false;
$refundItems = [];
$orderRefunds = [];
if (CommerceGateway::useDatabase() && CustomerAuth::id() !== null) {
    $row = OrderFulfillmentService::orderRowForCustomer($id, CustomerAuth::id());
    if ($row !== null) {
        $orderRefunds = RefundRequestService::listForOrderCustomer((int) $row['id'], CustomerAuth::id());
        $orderDelivered = OrderFulfillmentService::isOrderDelivered((int) $row['id']);
        if ($orderDelivered) {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT oi.id, oi.product_name, oi.variant_name, oi.quantity, oi.unit_price
                 FROM order_items oi WHERE oi.order_id = ?'
            );
            $stmt->execute([(int) $row['id']]);
            $refundItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_request'])) {
    Csrf::requireValid();
    if (!CommerceGateway::useDatabase() || CustomerAuth::id() === null) {
        $refundErr = 'Refund requests require a live account order.';
    } else {
        $itemIds = isset($_POST['order_item_id']) && is_array($_POST['order_item_id'])
            ? array_map('intval', $_POST['order_item_id']) : [];
        $qtys = [];
        foreach ($itemIds as $iid) {
            $qtys[$iid] = (int) ($_POST['qty'][$iid] ?? 1);
        }
        $files = isset($_FILES['evidence']) ? $_FILES['evidence'] : null;
        $result = RefundRequestService::create(
            CustomerAuth::id(),
            $id,
            (string) ($_POST['reason_code'] ?? 'other'),
            (string) ($_POST['notes'] ?? ''),
            (string) ($_POST['preferred_resolution'] ?? 'refund'),
            $itemIds,
            $qtys,
            $files
        );
        if ($result['ok']) {
            $refundMsg = 'Refund request ' . ($result['request_number'] ?? '') . ' submitted. We will review it shortly.';
            $liveOrder = OrderFulfillmentService::orderRowForCustomer($id, CustomerAuth::id());
            if ($liveOrder !== null && CustomerAuth::id() !== null) {
                $orderRefunds = RefundRequestService::listForOrderCustomer((int) $liveOrder['id'], CustomerAuth::id());
            }
        } else {
            $refundErr = $result['message'] ?? 'Could not submit request.';
        }
    }
}

$totals = $order['totals'] ?? [];
$timeline = $order['timeline'] ?? [
    ['status' => 'Confirmed', 'at' => $order['created_at'] ?? '', 'done' => true],
    ['status' => 'Preparing', 'at' => '', 'done' => false],
    ['status' => 'Out for delivery', 'at' => '', 'done' => false],
    ['status' => 'Delivered', 'at' => '', 'done' => false],
];

layout_public_start(['pageTitle' => 'Order ' . $id . ' — Apni Tokri', 'activeNav' => 'account']);
$accountActive = 'orders';
$accountTitle = 'Order ' . $id;
$accountSub = null;
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-start.php';
$st = (string) ($order['status'] ?? '');
$firstNotDone = null;
foreach ($timeline as $i => $step) {
    if (empty($step['done'])) {
        $firstNotDone = $i;
        break;
    }
}
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <?= customer_status_badge($st, ucwords(str_replace('_', ' ', $st)), 'order') ?>
    <a href="<?= Security::escape(url_base()) ?>/support.php?order=<?= rawurlencode($id) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable"><?= at_icon('chat', 'w-4 h-4') ?><?= Security::escape(__('need_help')) ?></a>
</div>

<section class="at-card">
    <div class="at-card__body">
        <h2 class="at-h3">Status</h2>
        <ol class="at-timeline mt-4">
            <?php foreach ($timeline as $i => $step): ?>
                <li class="<?= !empty($step['done']) ? 'is-done' : ($i === $firstNotDone ? 'is-current' : '') ?>">
                    <strong class="text-sm"><?= Security::escape((string) ($step['status'] ?? '')) ?></strong>
                    <?php if (!empty($step['at'])): ?><span class="block text-xs at-muted"><?= Security::escape(date('j M Y, g:i A', strtotime((string) $step['at']) ?: time())) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<section class="at-card">
    <div class="at-card__body">
        <h2 class="at-h3">Items</h2>
        <?php $orderItems = $order['items'] ?? []; require APNI_TOKRI_ROOT . '/includes/partials/order-items.php'; ?>
        <dl class="at-order-totals">
            <div><dt>Subtotal</dt><dd><?= Security::escape(catalogue_format_price((float) ($totals['subtotal'] ?? 0))) ?></dd></div>
            <?php if ((float) ($totals['coupon_discount'] ?? 0) > 0): ?><div class="is-saving"><dt>Discount<?= !empty($totals['coupon_code']) ? ' (' . Security::escape((string) $totals['coupon_code']) . ')' : '' ?></dt><dd>−<?= Security::escape(catalogue_format_price((float) $totals['coupon_discount'])) ?></dd></div><?php endif; ?>
            <div><dt>Delivery fee</dt><dd><?= (float) ($totals['delivery_fee'] ?? 0) === 0.0 ? 'Free' : Security::escape(catalogue_format_price((float) $totals['delivery_fee'])) ?></dd></div>
            <div class="is-grand"><dt>Total</dt><dd><?= Security::escape(catalogue_format_price((float) ($totals['total'] ?? 0))) ?></dd></div>
        </dl>
    </div>
</section>

<?php if ($orderDelivered): ?>
    <section class="at-card at-card--tint">
        <div class="at-card__body flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="at-h3">How did we do?</h2><p class="text-sm at-muted mt-1">Reviews are for delivered orders and appear after approval.</p></div>
            <a href="<?= Security::escape(url_base()) ?>/submit-review.php?order=<?= rawurlencode($id) ?>" class="at-btn at-btn--secondary at-focusable">Write a review</a>
        </div>
    </section>
<?php endif; ?>

<section class="at-card">
    <div class="at-card__body">
        <h2 class="at-h3">Refund or quality issue</h2>
        <p class="text-sm at-muted mt-1">Tell us what went wrong. We review every request against our <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/page.php?slug=refund-policy">refund policy</a>.</p>
        <?php if ($orderRefunds !== []): ?>
            <div class="mt-3 p-3 rounded-lg bg-[var(--at-cream-2)] text-sm">
                <p class="font-semibold">Your refund requests for this order</p>
                <ul class="mt-2 space-y-1">
                    <?php foreach ($orderRefunds as $or): ?>
                        <li>
                            <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/refund-details.php?refund=<?= rawurlencode((string) $or['request_number']) ?>"><?= Security::escape((string) $or['request_number']) ?></a>
                            — <?= Security::escape(RefundRequestService::publicStatusLabel((string) $or['status'])) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="mt-2"><a class="underline text-xs" href="<?= Security::escape(url_base()) ?>/my-refunds.php">View all refunds</a></p>
            </div>
        <?php endif; ?>
        <?php if ($refundMsg !== ''): ?>
            <div class="at-alert at-alert--success mt-3"><?= at_icon('check', 'w-5 h-5 flex-shrink-0') ?><span><?= Security::escape($refundMsg) ?></span></div>
        <?php endif; ?>
        <?php if ($refundErr !== ''): ?>
            <div class="at-alert at-alert--error mt-3"><span><?= Security::escape($refundErr) ?></span></div>
        <?php endif; ?>
        <?php if (!$orderDelivered && CommerceGateway::useDatabase()): ?>
            <p class="text-sm mt-3 at-muted">Refund requests open after your order is marked delivered.</p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="mt-4 grid gap-4 max-w-xl">
            <?= Csrf::field() ?>
            <input type="hidden" name="refund_request" value="1">
            <div>
                <label class="at-label" for="reason_code">Reason</label>
                <select id="reason_code" name="reason_code" class="at-select at-focusable">
                    <?php foreach (RefundRequestService::reasonLabels() as $code => $label): ?>
                        <option value="<?= Security::escape($code) ?>"><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($refundItems !== []): ?>
                <fieldset class="grid gap-2">
                    <legend class="at-label">Affected items</legend>
                    <?php foreach ($refundItems as $ri): ?>
                        <label class="flex gap-2 text-sm items-center">
                            <input type="checkbox" name="order_item_id[]" value="<?= (int) $ri['id'] ?>">
                            <?= Security::escape((string) $ri['product_name']) ?> (max <?= (int) $ri['quantity'] ?>)
                            <input type="number" name="qty[<?= (int) $ri['id'] ?>]" min="1" max="<?= (int) $ri['quantity'] ?>" value="<?= (int) $ri['quantity'] ?>" class="at-input w-20">
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <div>
                <label class="at-label" for="preferred_resolution">Preferred resolution</label>
                <select id="preferred_resolution" name="preferred_resolution" class="at-select at-focusable">
                    <?php foreach (RefundRequestService::resolutionOptions() as $opt): ?>
                        <option value="<?= Security::escape($opt) ?>"><?= Security::escape(ucwords(str_replace('_', ' ', $opt))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="at-label" for="refund_notes">Details</label>
                <textarea id="refund_notes" name="notes" class="at-textarea at-focusable" rows="3" required placeholder="What happened?"></textarea>
            </div>
            <div>
                <label class="at-label" for="evidence">Photos (optional)</label>
                <input id="evidence" name="evidence[]" type="file" accept="image/*" multiple class="at-input at-focusable !py-2">
            </div>
            <div><button type="submit" class="at-btn at-btn--primary at-focusable">Submit refund request</button></div>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php
require APNI_TOKRI_ROOT . '/includes/partials/account-shell-end.php';
layout_public_end();
