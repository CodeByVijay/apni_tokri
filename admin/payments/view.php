<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

AdminDemoPerms::denyIfCannot('payments');

$paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$backQs = http_build_query(array_filter([
    'q' => isset($_GET['q']) && trim((string) $_GET['q']) !== '' ? trim((string) $_GET['q']) : null,
    'page' => isset($_GET['page']) && (int) $_GET['page'] > 1 ? (string) (int) $_GET['page'] : null,
    'per_page' => isset($_GET['per_page']) ? (string) (int) $_GET['per_page'] : null,
]));
$backHref = url_base() . '/admin/payments/index.php' . ($backQs !== '' ? '?' . $backQs : '');

admin_layout_start(['pageTitle' => 'Payment detail', 'activeAdminNav' => 'payments']);

if (!Install::isSchemaReady() || !AdminAuth::check()) {
    echo '<div class="at-alert at-alert--info">Database admin session required.</div>';
    admin_layout_end();
    exit;
}

if ($paymentId <= 0) {
    echo '<div class="at-alert at-alert--warn">Invalid payment ID.</div>';
    echo '<p class="mt-3"><a class="underline at-focusable" href="' . Security::escape($backHref) . '">← Back to payments</a></p>';
    admin_layout_end();
    exit;
}

$model = PaymentAdminDetailService::viewModel($paymentId);
if ($model === null) {
    http_response_code(404);
    echo '<div class="at-alert at-alert--warn">Payment #' . (int) $paymentId . ' was not found.</div>';
    echo '<p class="mt-3"><a class="underline at-focusable" href="' . Security::escape($backHref) . '">← Back to payments</a></p>';
    admin_layout_end();
    exit;
}

$payment = $model['payment'];
$order = $model['order'];
$gateway = $model['gateway'];
$timeline = $model['timeline'];
$refunds = $model['refunds'];
$limitations = $model['limitations'];
$base = url_base();
$orderId = (int) ($payment['order_id'] ?? 0);
$orderHref = $orderId > 0 ? $base . '/admin/orders/view.php?id=' . $orderId : '';
$status = (string) ($payment['status'] ?? '');
$reference = PaymentAdminDetailService::publicReference($payment);

$pageHeading = 'Payment #' . (int) $payment['id'];
$pageDescription = 'Reference ' . $reference . ' · ' . ucfirst((string) ($payment['gateway'] ?? ''));
$pageActionsHtml = '<a href="' . Security::escape($backHref) . '" class="at-btn at-btn--secondary at-btn--sm at-focusable">Back to payments</a>';
$breadcrumbs = [
    ['label' => 'Payments', 'href' => $backHref],
    ['label' => 'Payment #' . (int) $payment['id']],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$customerName = '—';
$customerEmail = '—';
$customerPhone = '—';
$orderTotal = '—';
$orderPayStatus = '—';
$orderStatus = '—';
$orderPlaced = '—';
$orderNumber = (string) ($payment['order_number'] ?? '');
if ($order !== null) {
    $name = trim((string) ($order['customer_name'] ?? ''));
    $mobile = trim((string) ($order['customer_mobile'] ?? ''));
    if ($name === '' && $mobile === '') {
        $customerName = 'Guest';
    } else {
        $customerName = $name !== '' ? $name : $mobile;
    }
    $customerEmail = trim((string) ($order['customer_email'] ?? '')) !== '' ? (string) $order['customer_email'] : '—';
    $customerPhone = $mobile !== '' ? $mobile : '—';
    $orderTotal = catalogue_format_price((float) ($order['grand_total'] ?? 0));
    $orderPayStatus = (string) ($order['payment_status'] ?? '');
    $orderStatus = (string) ($order['status'] ?? '');
    $orderPlaced = customer_format_datetime((string) ($order['placed_at'] ?? $order['created_at'] ?? ''));
}
?>
<div class="space-y-4">
    <section class="at-card">
        <div class="at-card__body">
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <h2 class="text-lg font-bold">Payment overview</h2>
                <?= admin_status_badge($status, ucfirst($status)) ?>
            </div>
            <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="at-dl__dt">Payment ID</dt><dd class="font-mono"><?= (int) ($payment['id'] ?? 0) ?></dd></div>
                <div><dt class="at-dl__dt">Gateway reference</dt><dd class="font-mono text-sm break-all"><?= Security::escape($reference) ?></dd></div>
                <div><dt class="at-dl__dt">Order number</dt><dd>
                    <?php if ($orderHref !== '' && $orderNumber !== ''): ?>
                        <a class="underline font-semibold at-focusable" href="<?= Security::escape($orderHref) ?>"><?= Security::escape($orderNumber) ?></a>
                    <?php else: ?>
                        <?= Security::escape($orderNumber !== '' ? $orderNumber : '—') ?>
                    <?php endif; ?>
                </dd></div>
                <div><dt class="at-dl__dt">Gateway</dt><dd><?= Security::escape(ucfirst((string) ($payment['gateway'] ?? ''))) ?></dd></div>
                <div><dt class="at-dl__dt">Amount</dt><dd><?= Security::escape(catalogue_format_price((float) ($payment['amount'] ?? 0))) ?></dd></div>
                <div><dt class="at-dl__dt">Currency</dt><dd><?= Security::escape((string) ($payment['currency'] ?? 'INR')) ?></dd></div>
                <div><dt class="at-dl__dt">Payment method</dt><dd><?= Security::escape((string) $gateway['payment_method']) ?></dd></div>
                <div><dt class="at-dl__dt">Captured at</dt><dd><?= Security::escape((string) $gateway['captured_at']) ?></dd></div>
                <div><dt class="at-dl__dt">Created</dt><dd><?= Security::escape(customer_format_datetime((string) ($payment['created_at'] ?? ''))) ?></dd></div>
                <div><dt class="at-dl__dt">Last updated</dt><dd><?= Security::escape(customer_format_datetime((string) ($payment['updated_at'] ?? ''))) ?></dd></div>
                <?php if (array_key_exists('amount_refunded_inr', $payment)): ?>
                    <div><dt class="at-dl__dt">Amount refunded (ledger)</dt><dd><?= Security::escape(catalogue_format_price((float) ($payment['amount_refunded_inr'] ?? 0))) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-3">Related order</h2>
            <?php if ($order === null): ?>
                <p class="text-sm at-muted">The linked order is missing or was removed.</p>
            <?php else: ?>
                <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="at-dl__dt">Order number</dt><dd>
                        <a class="underline font-semibold at-focusable" href="<?= Security::escape($orderHref) ?>"><?= Security::escape($orderNumber) ?></a>
                    </dd></div>
                    <div><dt class="at-dl__dt">Customer</dt><dd><?= Security::escape($customerName) ?></dd></div>
                    <div><dt class="at-dl__dt">Email</dt><dd><?= Security::escape($customerEmail) ?></dd></div>
                    <div><dt class="at-dl__dt">Phone</dt><dd><?= Security::escape($customerPhone) ?></dd></div>
                    <div><dt class="at-dl__dt">Order total</dt><dd><?= Security::escape($orderTotal) ?></dd></div>
                    <div><dt class="at-dl__dt">Order payment status</dt><dd><?= admin_status_badge($orderPayStatus) ?></dd></div>
                    <div><dt class="at-dl__dt">Order status</dt><dd><?= admin_status_badge($orderStatus) ?></dd></div>
                    <div><dt class="at-dl__dt">Order placed</dt><dd><?= Security::escape($orderPlaced) ?></dd></div>
                </dl>
            <?php endif; ?>
        </div>
    </section>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-3">Gateway transaction</h2>
            <dl class="at-dl at-dl--compact grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="at-dl__dt">Gateway order ID</dt><dd class="font-mono text-sm break-all"><?= Security::escape((string) $gateway['gateway_order_id']) ?></dd></div>
                <div><dt class="at-dl__dt">Gateway payment ID</dt><dd class="font-mono text-sm break-all"><?= Security::escape((string) $gateway['gateway_payment_id']) ?></dd></div>
                <div><dt class="at-dl__dt">Gateway event ID</dt><dd class="font-mono text-sm break-all"><?= Security::escape((string) $gateway['gateway_event_id']) ?></dd></div>
                <div><dt class="at-dl__dt">Idempotency key</dt><dd class="font-mono text-sm break-all"><?= Security::escape((string) $gateway['idempotency_key']) ?></dd></div>
                <div><dt class="at-dl__dt">Gateway status (stored)</dt><dd><?= Security::escape((string) $gateway['gateway_status']) ?></dd></div>
                <div><dt class="at-dl__dt">Response code</dt><dd><?= Security::escape((string) $gateway['response_code']) ?></dd></div>
                <div><dt class="at-dl__dt">Response message</dt><dd><?= Security::escape((string) $gateway['response_message']) ?></dd></div>
                <div><dt class="at-dl__dt">Payment environment</dt><dd><?= Security::escape((string) $gateway['payment_environment']) ?></dd></div>
                <div><dt class="at-dl__dt">Status fetched at</dt><dd><?= Security::escape((string) $gateway['status_fetched_at']) ?></dd></div>
                <div><dt class="at-dl__dt">Last sync (row updated)</dt><dd><?= Security::escape((string) $gateway['last_sync_at']) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-3">Payment status history</h2>
            <?php if ($timeline === []): ?>
                <p class="text-sm at-muted">No recorded payment events for this attempt.</p>
            <?php else: ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--dense">
                        <thead>
                            <tr>
                                <th scope="col">Event</th>
                                <th scope="col">Description</th>
                                <th scope="col">Source</th>
                                <th scope="col">When</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($timeline as $ev): ?>
                            <tr>
                                <td><?= Security::escape((string) ($ev['event'] ?? '')) ?></td>
                                <td><?= Security::escape((string) ($ev['description'] ?? '')) ?></td>
                                <td><?= Security::escape((string) ($ev['source'] ?? '')) ?></td>
                                <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($ev['at'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="at-card">
        <div class="at-card__body">
            <h2 class="text-lg font-bold mb-3">Related refunds</h2>
            <?php if ($refunds === []): ?>
                <p class="text-sm at-muted">No refunds recorded for this payment.</p>
            <?php else: ?>
                <div class="at-admin-table-wrap">
                    <table class="at-admin-table at-admin-table--dense">
                        <thead>
                            <tr>
                                <th scope="col">Refund ID</th>
                                <th scope="col">Request</th>
                                <th scope="col" class="is-num">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Gateway refund ref</th>
                                <th scope="col">Created</th>
                                <th scope="col">Updated</th>
                                <th class="is-actions" scope="col"><span class="sr-only">View</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($refunds as $rf):
                            $rid = (int) ($rf['id'] ?? 0);
                            $reqId = (int) ($rf['refund_request_id'] ?? 0);
                            $reqHref = $reqId > 0 ? $base . '/admin/refunds/index.php?id=' . $reqId : '';
                            ?>
                            <tr>
                                <td class="font-mono"><?= $rid ?></td>
                                <td><?= Security::escape((string) ($rf['request_number'] ?? ($reqId > 0 ? '#' . $reqId : '—'))) ?></td>
                                <td class="is-num"><?= Security::escape(catalogue_format_price((float) ($rf['amount_inr'] ?? 0))) ?></td>
                                <td><?= admin_status_badge((string) ($rf['gateway_status'] ?? '')) ?></td>
                                <td class="font-mono text-sm"><?= Security::escape((string) (($rf['gateway_refund_id'] ?? '') !== '' ? $rf['gateway_refund_id'] : '—')) ?></td>
                                <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($rf['created_at'] ?? ''))) ?></td>
                                <td class="is-nowrap"><?= Security::escape(customer_format_datetime((string) ($rf['updated_at'] ?? ''))) ?></td>
                                <td class="is-actions">
                                    <?php if ($reqHref !== ''): ?>
                                        <a class="at-btn at-btn--sm at-btn--ghost at-focusable" href="<?= Security::escape($reqHref) ?>">View</a>
                                    <?php else: ?>
                                        <span class="at-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($limitations !== []): ?>
        <div class="at-alert at-alert--info">
            <p class="text-sm font-semibold mb-1">Data notes</p>
            <ul class="text-sm list-disc ps-5 space-y-1">
                <?php foreach ($limitations as $note): ?>
                    <li><?= Security::escape($note) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<?php
admin_layout_end();
