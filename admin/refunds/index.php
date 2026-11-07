<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('refunds');

$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$filterStatus = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'status' => 'status', 'request_number' => 'request_number',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
]);

$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $rid = (int) ($_POST['request_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $result = ['ok' => false, 'message' => 'Unknown action'];
    if ($action === 'review') {
        $result = RefundRequestService::setUnderReview($rid);
    } elseif ($action === 'approve_process') {
        $mode = isset($_POST['refund_mode']) && is_string($_POST['refund_mode']) ? $_POST['refund_mode'] : 'partial';
        $amt = $mode === 'full'
            ? RefundRequestService::availableApprovalAmountForRequest($rid)
            : (float) ($_POST['approved_amount'] ?? 0);
        $result = RefundRequestService::approveAndProcessGatewayRefund(
            $rid,
            $amt,
            isset($_POST['internal_note']) && is_string($_POST['internal_note']) ? trim($_POST['internal_note']) : null
        );
    } elseif ($action === 'approve') {
        $amt = (float) ($_POST['approved_amount'] ?? 0);
        $result = RefundRequestService::approve($rid, $amt, (string) ($_POST['internal_note'] ?? ''));
    } elseif ($action === 'reject') {
        $result = RefundRequestService::reject($rid, (string) ($_POST['reject_reason'] ?? ''));
    } elseif ($action === 'retry_gateway') {
        $result = RefundRequestService::retryGatewayRefund($rid);
    } elseif ($action === 'fetch_gateway_status') {
        $result = RefundRequestService::fetchGatewayRefundStatus($rid);
    } elseif ($action === 'gateway') {
        $result = RefundRequestService::initiateGatewayRefund($rid);
    }
    admin_flash_set($result['ok'] ? ($result['message'] ?? 'Saved.') : ($result['message'] ?? 'Failed.'), $result['ok'] ? 'success' : 'error');
    header('Location: ' . url_base() . '/admin/refunds/index.php?id=' . $rid);
    exit;
}

admin_layout_start(['pageTitle' => 'Refunds', 'activeAdminNav' => 'refunds']);

if ($requestId > 0 && Install::isSchemaReady()) {
    $req = RefundRequestService::findByIdForAdmin($requestId);
    if ($req === null) {
        echo '<p>Request not found.</p>';
        admin_layout_end();
        exit;
    }
    $orderId = (int) ($req['order_id'] ?? 0);
    $order = $orderId > 0 ? AdminOrderService::findById($orderId) : null;
    $refundLineItems = RefundRequestService::lineItemsForAdmin($requestId);
    $refundLinesTotal = RefundRequestService::sumLineItemsForRequest($requestId);
    $reasonLabels = RefundRequestService::reasonLabels();
    $reasonCode = (string) ($req['reason_code'] ?? '');
    $reasonLabel = $reasonLabels[$reasonCode] ?? $reasonCode;
    $customerId = (int) ($req['customer_id'] ?? 0);
    $base = url_base();
    $orderHref = $orderId > 0 ? $base . '/admin/orders/view.php?id=' . $orderId : '';
    $customerName = trim((string) ($order['customer_name'] ?? ''));
    $customerMobile = trim((string) ($order['customer_mobile'] ?? ''));
    $isGuestPresentation = $customerName === '' && $customerMobile === '';
    $customerDisplay = $isGuestPresentation
        ? 'Guest'
        : ($customerName !== '' ? $customerName : $customerMobile);
    $customerHref = $customerId > 0 && !$isGuestPresentation
        ? $base . '/admin/customers/view.php?id=' . $customerId
        : '';
    $orderPlacedAt = $order !== null
        ? (string) ($order['placed_at'] ?? '')
        : '';
    $pageHeading = (string) $req['request_number'];
    require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
    $refundable = RefundRequestService::capturedAmountForOrder($orderId);
    $availableApproval = RefundRequestService::availableApprovalAmountForRequest($requestId);
    $latestGwRefund = RefundRequestService::latestPaymentRefundForRequest($requestId);
    $reqStatus = (string) $req['status'];
    $canApproveProcess = in_array($reqStatus, [RefundStatus::REQUESTED, RefundStatus::UNDER_REVIEW], true);
    $canRetryGateway = $reqStatus === RefundStatus::REFUND_FAILED
        && ($latestGwRefund === null || (string) ($latestGwRefund['gateway_status'] ?? '') === 'failed');
    $canFetchGatewayStatus = RefundRequestService::canAdminFetchGatewayStatus($requestId, $reqStatus);
    $canManualGateway = $reqStatus === RefundStatus::APPROVED;
    $showSuccessPanel = in_array($reqStatus, [RefundStatus::REFUNDED, RefundStatus::PROCESSING, RefundStatus::REFUND_INITIATED], true)
        && $latestGwRefund !== null;
    ?>
    <div class="at-card"><div class="at-card__body text-sm space-y-2">
        <p class="flex flex-wrap gap-2 items-center">
            <span>Status: <?= admin_status_badge((string) $req['status'], RefundRequestService::publicStatusLabel((string) $req['status'])) ?></span>
            <?php if ($req['approved_amount_inr'] !== null): ?>
                <span class="at-muted">· Approved <?= Security::escape(catalogue_format_price((float) $req['approved_amount_inr'])) ?></span>
            <?php endif; ?>
        </p>
        <p><strong>Reason:</strong> <?= Security::escape($reasonLabel) ?>
            <?php if (trim((string) ($req['description'] ?? '')) !== ''): ?>
                <span class="at-muted">— <?= Security::escape(trim((string) $req['description'])) ?></span>
            <?php endif; ?>
        </p>
        <p>Refundable balance (approx): <strong><?= Security::escape(catalogue_format_price($refundable)) ?></strong></p>
        <p class="text-xs at-muted">Requested <?= Security::escape(customer_format_datetime((string) ($req['created_at'] ?? ''))) ?></p>
    </div></div>

    <div class="grid lg:grid-cols-2 gap-4 mt-4">
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold">Order details</h3>
            <?php if ($orderHref !== ''): ?>
                <p><strong>Order:</strong> <a class="underline font-mono" href="<?= Security::escape($orderHref) ?>"><?= Security::escape((string) $req['order_number']) ?></a></p>
            <?php else: ?>
                <p><strong>Order:</strong> <?= Security::escape((string) $req['order_number']) ?></p>
            <?php endif; ?>
            <?php if ($order !== null): ?>
                <p><strong>Order status:</strong> <?= admin_status_badge((string) ($order['status'] ?? '')) ?></p>
                <p><strong>Payment:</strong> <?= admin_status_badge((string) ($order['payment_status'] ?? '')) ?></p>
                <p><strong>Grand total:</strong> <?= Security::escape(catalogue_format_price((float) ($order['grand_total'] ?? 0))) ?></p>
            <?php endif; ?>
        </div></div>
        <div class="at-card"><div class="at-card__body text-sm space-y-2">
            <h3 class="font-bold">Customer details</h3>
            <p><strong>Customer:</strong>
                <?php if ($customerHref !== ''): ?>
                    <a class="underline" href="<?= Security::escape($customerHref) ?>"><?= Security::escape($customerDisplay) ?></a>
                <?php else: ?>
                    <?= Security::escape($customerDisplay) ?>
                <?php endif; ?>
            </p>
            <?php if ($customerMobile !== ''): ?>
                <p><strong>Mobile:</strong> <?= Security::escape($customerMobile) ?></p>
            <?php endif; ?>
            <p><strong>Email:</strong>
                <?php if ($order !== null && trim((string) ($order['customer_email'] ?? '')) !== ''): ?>
                    <?= Security::escape((string) $order['customer_email']) ?>
                <?php else: ?>
                    <span class="at-muted">—</span>
                <?php endif; ?>
            </p>
            <p><strong>Delivery address:</strong> <?= Security::escape((string) ($order['address_line'] ?? '—')) ?></p>
            <p><strong>Order date:</strong>
                <?php if ($orderPlacedAt !== ''): ?>
                    <?= Security::escape(customer_format_datetime($orderPlacedAt)) ?>
                <?php else: ?>
                    <span class="at-muted">—</span>
                <?php endif; ?>
            </p>
        </div></div>
    </div>

    <div class="at-card mt-4"><div class="at-card__body">
        <?php if ($refundLineItems !== []): ?>
            <h3 class="font-bold mb-3">Refund items</h3>
            <?php
            $orderItems = $refundLineItems;
            require APNI_TOKRI_ROOT . '/includes/partials/order-items.php';
            ?>
            <dl class="at-order-totals mt-3">
                <div class="is-grand"><dt>Refund items total</dt><dd><?= Security::escape(catalogue_format_price($refundLinesTotal)) ?></dd></div>
            </dl>
        <?php elseif ($order !== null && ($order['items'] ?? []) !== []): ?>
            <h3 class="font-bold mb-3">Order items</h3>
            <p class="text-sm at-muted mb-2">No line items are stored on this refund request. Shown for order context only — not inferred from the refund amount.</p>
            <?php
            $orderItems = $order['items'];
            require APNI_TOKRI_ROOT . '/includes/partials/order-items.php';
            ?>
        <?php else: ?>
            <h3 class="font-bold mb-2">Refund items</h3>
            <p class="text-sm at-muted">No refund line items recorded for this request.</p>
        <?php endif; ?>
    </div></div>

    <?php if ($showSuccessPanel): ?>
    <div class="at-card mt-4 at-alert at-alert--success"><div class="at-card__body text-sm space-y-1">
        <p class="font-bold">Refund processed successfully</p>
        <?php if ($req['approved_amount_inr'] !== null): ?>
            <p>Approved amount: <strong><?= Security::escape(catalogue_format_price((float) $req['approved_amount_inr'])) ?></strong></p>
        <?php endif; ?>
        <?php if ((string) ($latestGwRefund['gateway_refund_id'] ?? '') !== ''): ?>
            <p>Gateway reference: <code class="text-xs"><?= Security::escape((string) $latestGwRefund['gateway_refund_id']) ?></code></p>
        <?php endif; ?>
        <p class="text-xs at-muted">Gateway status: <?= Security::escape(RefundRequestService::publicGatewayStatusLabel((string) ($latestGwRefund['gateway_status'] ?? ''))) ?>
            · <?= Security::escape(customer_format_datetime((string) ($latestGwRefund['created_at'] ?? ''))) ?></p>
    </div></div>
    <?php endif; ?>

    <?php if ($reqStatus === RefundStatus::REFUND_FAILED && $req['approved_amount_inr'] !== null): ?>
    <div class="at-card mt-4"><div class="at-card__body text-sm space-y-2">
        <p class="at-alert at-alert--warning">Refund approval saved, but gateway processing failed. You can retry the refund below.</p>
        <p>Approved amount: <strong><?= Security::escape(catalogue_format_price((float) $req['approved_amount_inr'])) ?></strong></p>
    </div></div>
    <?php endif; ?>

    <form method="post" class="at-card mt-4" id="admin-refund-actions">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
            <?php if ($canApproveProcess): ?>
                <h3 class="font-bold">Approve refund</h3>
                <p class="text-sm at-muted">Available refundable balance for this request: <strong><?= Security::escape(catalogue_format_price($availableApproval)) ?></strong></p>
                <?php if (in_array($reqStatus, [RefundStatus::REQUESTED, RefundStatus::UNDER_REVIEW], true)): ?>
                    <button type="submit" name="action" value="review" class="at-btn at-btn--secondary at-btn--sm at-focusable">Mark under review</button>
                <?php endif; ?>
                <fieldset class="space-y-2 border-0 p-0">
                    <legend class="at-label">Refund type</legend>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="refund_mode" value="full" class="at-focusable" data-refund-mode>
                        Full refund (<?= Security::escape(catalogue_format_price($availableApproval)) ?>)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="refund_mode" value="partial" class="at-focusable" data-refund-mode checked>
                        Partial refund
                    </label>
                </fieldset>
                <div>
                    <label class="at-label" for="approved_amount">Approved refund amount (INR)</label>
                    <input type="number" step="0.01" min="0.01" max="<?= Security::escape((string) max(0.01, $availableApproval)) ?>" name="approved_amount" id="approved_amount" class="at-input at-focusable" value="<?= $availableApproval > 0 ? Security::escape((string) $availableApproval) : '' ?>">
                </div>
                <div>
                    <label class="at-label" for="internal_note">Internal note</label>
                    <textarea id="internal_note" name="internal_note" class="at-textarea at-focusable" rows="2" placeholder="Optional"></textarea>
                </div>
                <button type="submit" name="action" value="approve_process" class="at-btn at-btn--primary at-focusable">Approve &amp; Process Refund</button>
                <div class="pt-2 border-t border-[var(--at-line)]">
                    <label class="at-label" for="reject_reason">Rejection reason</label>
                    <textarea id="reject_reason" name="reject_reason" class="at-textarea at-focusable" rows="2" placeholder="Required to reject"></textarea>
                    <button type="submit" name="action" value="reject" class="at-btn at-btn--secondary at-focusable mt-2">Reject</button>
                </div>
            <?php elseif ($canRetryGateway): ?>
                <p class="text-sm">Retry uses the saved approved amount (<?= Security::escape(catalogue_format_price((float) ($req['approved_amount_inr'] ?? 0))) ?>).</p>
                <button type="submit" name="action" value="retry_gateway" class="at-btn at-btn--primary at-focusable">Retry gateway refund</button>
            <?php elseif ($canManualGateway): ?>
                <p class="text-sm at-muted">Approval saved; gateway was not started. Process when ready.</p>
                <button type="submit" name="action" value="gateway" class="at-btn at-btn--primary at-focusable">Process gateway refund</button>
            <?php elseif ($showSuccessPanel): ?>
                <p class="text-sm at-muted">This refund has already been submitted to the gateway.</p>
            <?php endif; ?>
            <?php if ($canFetchGatewayStatus): ?>
                <div class="pt-2 border-t border-[var(--at-line)] space-y-2">
                    <p class="text-sm at-muted">Fetch the latest status from the payment gateway. This does not create a new refund attempt.</p>
                    <button type="submit" name="action" value="fetch_gateway_status" class="at-btn at-btn--secondary at-focusable">Fetch Gateway Status</button>
                </div>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($canApproveProcess): ?>
    <script>
    (function () {
        var form = document.getElementById('admin-refund-actions');
        if (!form) return;
        var amount = form.querySelector('#approved_amount');
        var fullAmt = <?= json_encode($availableApproval, JSON_THROW_ON_ERROR) ?>;
        var sync = function () {
            var mode = form.querySelector('input[name="refund_mode"]:checked');
            if (!amount || !mode) return;
            if (mode.value === 'full') {
                amount.value = String(fullAmt);
                amount.readOnly = true;
                amount.removeAttribute('required');
            } else {
                amount.readOnly = false;
                amount.setAttribute('required', 'required');
            }
        };
        form.querySelectorAll('[data-refund-mode]').forEach(function (el) {
            el.addEventListener('change', sync);
        });
        sync();
    })();
    </script>
    <?php endif; ?>
    <div class="at-card mt-4"><div class="at-card__body">
        <h3 class="at-h3">Audit history</h3>
        <ul class="mt-2 text-sm space-y-1">
            <?php foreach (RefundRequestService::history($requestId) as $h): ?>
                <li><?= Security::escape(admin_format_audit_datetime((string) ($h['created_at'] ?? ''))) ?> — <?= Security::escape((string) $h['action']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div></div>
    <p class="mt-4"><a class="underline" href="<?= Security::escape(url_base()) ?>/admin/refunds/index.php">← All requests</a></p>
    <?php
    admin_layout_end();
    exit;
}

$pageHeading = 'Refund requests';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
?>
<p class="mb-4"><a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/admin/refunds/reconcile.php">Refund reconciliation dashboard →</a></p>
<?php if (Install::isSchemaReady()): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Request #, order #, mobile">
        </div>
        <div>
            <label class="at-label" for="status">Status</label>
            <select id="status" name="status" class="at-select at-focusable">
                <option value="">All</option>
                <?php foreach ([RefundStatus::REQUESTED, RefundStatus::UNDER_REVIEW, RefundStatus::APPROVED, RefundStatus::REJECTED, RefundStatus::REFUNDED, RefundStatus::REFUND_FAILED] as $st): ?>
                    <option value="<?= Security::escape($st) ?>"<?= $filterStatus === $st ? ' selected' : '' ?>><?= Security::escape(ucwords(str_replace('_', ' ', $st))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="per_page" value="<?= (int) $listPerPage ?>">
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Filter</button>
    </div>
</form>
<?php endif;
$rows = [];
$list = ['total' => 0, 'page' => 1, 'total_pages' => 1];
if (Install::isSchemaReady()) {
    $list = RefundRequestService::searchForAdmin(
        $listQ,
        $filterStatus !== '' ? $filterStatus : null,
        $listPage,
        $listPerPage,
        $sort,
        $dir
    );
    foreach ($list['rows'] as $r) {
        $rid = (int) $r['id'];
        $rows[] = [
            'id' => (string) $r['request_number'],
            'order' => (string) ($r['order_number'] ?? ''),
            'amount' => $r['approved_amount_inr'] !== null
                ? '₹' . number_format((float) $r['approved_amount_inr'], 2)
                : '—',
            'status' => (string) $r['status'],
            'actions' => admin_action_edit_link(url_base() . '/admin/refunds/index.php?id=' . $rid, 'Open'),
        ];
    }
}
$baseList = url_base() . '/admin/refunds/index.php';
$columns = [
    ['label' => 'Request', 'label_html' => AdminServerList::sortHeaderLink('request_number', 'Request', $sort, $dir, $baseList, $queryBase), 'key' => 'id'],
    ['label' => 'Order', 'key' => 'order'],
    ['label' => 'Amount', 'key' => 'amount', 'align' => 'num'],
    ['label' => 'Status', 'label_html' => AdminServerList::sortHeaderLink('status', 'Status', $sort, $dir, $baseList, $queryBase), 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if (Install::isSchemaReady() && (int) ($list['total_pages'] ?? 1) > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $baseList);
}
admin_layout_end();
