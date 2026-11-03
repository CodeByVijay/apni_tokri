<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('support');

$ticketId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$listQ = AdminServerList::searchFromRequest(isset($_GET['q']) ? (string) $_GET['q'] : '');
$listPage = AdminServerList::pageFromRequest(isset($_GET['page']) ? (int) $_GET['page'] : null);
$listPerPage = AdminServerList::perPageFromRequest(isset($_GET['per_page']) ? (int) $_GET['per_page'] : null);
$filterStatus = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
$sort = AdminServerList::resolveSort((string) ($_GET['sort'] ?? 'created_at'), [
    'created_at' => 'created_at', 'status' => 'status', 'ticket_number' => 'ticket_number',
], 'created_at');
$dir = (string) ($_GET['dir'] ?? 'desc');
$queryBase = array_filter([
    'q' => $listQ !== '' ? $listQ : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Install::isSchemaReady()) {
    Csrf::requireValid();
    $tid = (int) ($_POST['ticket_id'] ?? 0);
    $result = SupportTicketService::adminReply(
        $tid,
        (string) ($_POST['message'] ?? ''),
        isset($_POST['status']) ? (string) $_POST['status'] : null
    );
    admin_flash_set($result['ok'] ? 'Reply sent.' : ($result['message'] ?? 'Failed.'), $result['ok'] ? 'success' : 'error');
    header('Location: ' . url_base() . '/admin/support/index.php?id=' . $tid);
    exit;
}

admin_layout_start(['pageTitle' => 'Support tickets', 'activeAdminNav' => 'support']);

if ($ticketId > 0 && Install::isSchemaReady()) {
    $detail = SupportTicketService::adminDetailFor($ticketId);
    if ($detail === null) {
        echo '<p>Ticket not found.</p>';
        admin_layout_end();
        exit;
    }
    AdminNotificationService::markReadForTicket($ticketId);
    $ticket = $detail['ticket'];
    $pageHeading = (string) ($ticket['ticket_number'] ?? 'Ticket');
    $pageDescription = (string) ($ticket['subject'] ?? '');
    $breadcrumbs = [
        ['label' => 'Support', 'href' => url_base() . '/admin/support/index.php'],
        ['label' => $pageHeading],
    ];
    require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
    require APNI_TOKRI_ROOT . '/includes/partials/admin/support-ticket-detail.php';
    admin_layout_end();
    exit;
}

$pageHeading = 'Support tickets';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$baseList = url_base() . '/admin/support/index.php';
?>
<?php if (Install::isSchemaReady()): ?>
<form method="get" class="at-admin-card mb-4">
    <div class="at-admin-card__body flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="at-label" for="q">Search</label>
            <input id="q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Ticket #, subject, mobile, order">
        </div>
        <div>
            <label class="at-label" for="status">Status</label>
            <select id="status" name="status" class="at-select at-focusable">
                <option value="">All</option>
                <option value="open"<?= $filterStatus === 'open' ? ' selected' : '' ?>>Open</option>
                <option value="pending_customer"<?= $filterStatus === 'pending_customer' ? ' selected' : '' ?>>Pending customer</option>
                <option value="resolved"<?= $filterStatus === 'resolved' ? ' selected' : '' ?>>Resolved</option>
                <option value="closed"<?= $filterStatus === 'closed' ? ' selected' : '' ?>>Closed</option>
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
    $list = SupportTicketService::searchForAdmin(
        $listQ,
        $filterStatus !== '' ? $filterStatus : null,
        $listPage,
        $listPerPage,
        $sort,
        $dir
    );
    foreach ($list['rows'] as $t) {
        $rows[] = [
            'id' => (string) $t['ticket_number'],
            'customer' => (string) ($t['customer_mobile'] ?? 'Guest'),
            'cat' => SupportTicketService::categoryLabel((string) ($t['category'] ?? '')),
            'status' => (string) $t['status'],
            'order' => (string) ($t['order_number'] ?? '—'),
            'actions' => admin_action_edit_link($baseList . '?id=' . (int) $t['id'], 'Open'),
        ];
    }
}
$columns = [
    ['label' => 'Ticket', 'label_html' => AdminServerList::sortHeaderLink('ticket_number', 'Ticket', $sort, $dir, $baseList, $queryBase), 'key' => 'id'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Category', 'key' => 'cat'],
    ['label' => 'Order', 'key' => 'order'],
    ['label' => 'Status', 'label_html' => AdminServerList::sortHeaderLink('status', 'Status', $sort, $dir, $baseList, $queryBase), 'key' => 'status'],
    ['label' => 'Actions', 'key' => 'actions', 'align' => 'actions', 'raw' => true],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
if (Install::isSchemaReady() && (int) ($list['total_pages'] ?? 1) > 1) {
    admin_list_pagination((int) $list['page'], (int) $list['total'], $listPerPage, $queryBase, $baseList);
}
admin_layout_end();
