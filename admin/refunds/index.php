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
    } elseif ($action === 'approve') {
        $amt = (float) ($_POST['approved_amount'] ?? 0);
        $result = RefundRequestService::approve($rid, $amt, (string) ($_POST['internal_note'] ?? ''));
    } elseif ($action === 'reject') {
        $result = RefundRequestService::reject($rid, (string) ($_POST['reject_reason'] ?? ''));
    } elseif ($action === 'gateway') {
        $result = RefundRequestService::initiateGatewayRefund($rid);
    }
    admin_flash_set($result['ok'] ? ($result['message'] ?? 'Saved.') : ($result['message'] ?? 'Failed.'), $result['ok'] ? 'success' : 'error');
    header('Location: ' . url_base() . '/admin/refunds/index.php?id=' . $rid);
    exit;
}

admin_layout_start(['pageTitle' => 'Refunds', 'activeAdminNav' => 'refunds']);

if ($requestId > 0 && Install::isSchemaReady()) {
    Install::ensureConnection();
    $stmt = Database::pdo()->prepare(
        'SELECT rr.*, o.order_number FROM refund_requests rr
         INNER JOIN orders o ON o.id = rr.order_id WHERE rr.id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        echo '<p>Request not found.</p>';
        admin_layout_end();
        exit;
    }
    $pageHeading = (string) $req['request_number'];
    require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
    $refundable = RefundRequestService::capturedAmountForOrder((int) $req['order_id']);
    ?>
    <div class="at-card"><div class="at-card__body text-sm space-y-2">
        <p>Order: <strong><?= Security::escape((string) $req['order_number']) ?></strong></p>
        <p>Status: <strong><?= Security::escape((string) $req['status']) ?></strong></p>
        <p>Reason: <?= Security::escape((string) $req['reason_code']) ?> — <?= Security::escape((string) ($req['description'] ?? '')) ?></p>
        <p>Refundable balance (approx): ₹<?= number_format($refundable, 2) ?></p>
        <?php if ($req['approved_amount_inr'] !== null): ?>
            <p>Approved amount: ₹<?= number_format((float) $req['approved_amount_inr'], 2) ?></p>
        <?php endif; ?>
    </div></div>
    <form method="post" class="at-card mt-4">
        <div class="at-card__body space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
            <p class="text-sm text-neutral-600">Approval is separate from gateway refund completion.</p>
            <?php if (in_array((string) $req['status'], [RefundStatus::REQUESTED, RefundStatus::UNDER_REVIEW], true)): ?>
                <button type="submit" name="action" value="review" class="at-btn at-btn--secondary at-focusable">Mark under review</button>
            <?php endif; ?>
            <?php if (in_array((string) $req['status'], [RefundStatus::REQUESTED, RefundStatus::UNDER_REVIEW], true)): ?>
                <div>
                    <label class="at-label">Approved refund amount (INR)</label>
                    <input type="number" step="0.01" min="0.01" name="approved_amount" class="at-input at-focusable" required>
                </div>
                <textarea name="internal_note" class="at-textarea at-focusable" placeholder="Internal note"></textarea>
                <button type="submit" name="action" value="approve" class="at-btn at-btn--primary at-focusable">Approve partial/full refund</button>
                <textarea name="reject_reason" class="at-textarea at-focusable" placeholder="Rejection reason"></textarea>
                <button type="submit" name="action" value="reject" class="at-btn at-btn--secondary at-focusable">Reject</button>
            <?php endif; ?>
            <?php if ((string) $req['status'] === RefundStatus::APPROVED): ?>
                <button type="submit" name="action" value="gateway" class="at-btn at-btn--primary at-focusable">Initiate gateway refund</button>
            <?php endif; ?>
        </div>
    </form>
    <div class="at-card mt-4"><div class="at-card__body">
        <h3 class="at-h3">Audit history</h3>
        <ul class="mt-2 text-sm space-y-1">
            <?php foreach (RefundRequestService::history($requestId) as $h): ?>
                <li><?= Security::escape((string) $h['created_at']) ?> — <?= Security::escape((string) $h['action']) ?></li>
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
