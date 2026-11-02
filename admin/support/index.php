<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('support');

$ticketId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

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
admin_flash_render();

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
$rows = [];
if (Install::isSchemaReady()) {
    foreach (SupportTicketService::listForAdmin(null, 100) as $t) {
        $rows[] = [
            'id' => (string) $t['ticket_number'],
            'customer' => (string) ($t['customer_mobile'] ?? 'Guest'),
            'cat' => SupportTicketService::categoryLabel((string) ($t['category'] ?? '')),
            'status' => (string) $t['status'],
            'order' => (string) ($t['order_number'] ?? '—'),
            'link' => 'Open',
            'action_href' => url_base() . '/admin/support/index.php?id=' . (int) $t['id'],
        ];
    }
}
$columns = [
    ['label' => 'Ticket', 'key' => 'id'],
    ['label' => 'Customer', 'key' => 'customer'],
    ['label' => 'Category', 'key' => 'cat'],
    ['label' => 'Order', 'key' => 'order'],
    ['label' => 'Status', 'key' => 'status'],
    ['label' => '', 'key' => 'link', 'align' => 'actions'],
];
require APNI_TOKRI_ROOT . '/includes/partials/admin/data-table.php';
admin_layout_end();
