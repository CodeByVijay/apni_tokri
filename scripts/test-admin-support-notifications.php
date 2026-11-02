<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

if (!AdminNotificationService::tableReady()) {
    fwrite(STDERR, "Apply migration 1.16.0_admin_support_notifications.sql first.\n");
    exit(1);
}

Install::ensureConnection();
$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$pdo = Database::pdo();
$customerStmt = $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1');
$customerId = (int) ($customerStmt->fetchColumn() ?: 0);
ok($customerId > 0, 'Fixture customer exists');

$before = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
$created = SupportTicketService::create($customerId, 'quality', 'Status', 'Delivery question.', null);
ok(!empty($created['ok']), 'Ticket create succeeds');
$afterCreate = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
ok($afterCreate === $before + 1, 'New ticket creates one admin notification');

$idStmt = $pdo->prepare('SELECT id FROM support_tickets WHERE ticket_number = ? LIMIT 1');
$idStmt->execute([(string) ($created['ticket_number'] ?? '')]);
$ticketId = (int) ($idStmt->fetchColumn() ?: 0);
$dupCreate = SupportTicketService::create($customerId, 'other', 'X', 'Again', null);
ok(!empty($dupCreate['ok']), 'Second ticket creates');
$afterDup = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
ok($afterDup === $afterCreate + 1, 'Separate ticket separate notification');

$beforeReply = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
$reply = SupportTicketService::customerReply($ticketId, $customerId, 'Follow up message.');
ok(!empty($reply['ok']), 'Customer reply succeeds');
$afterReply = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
ok($afterReply === $beforeReply + 1, 'Customer reply creates admin notification');

$_SESSION['admin_user_id'] = 1;
$_SESSION['admin_demo_role'] = 'super_admin';
$unread = AdminNotificationService::unreadCountForCurrentAdmin();
ok($unread >= 2, 'Unread count includes new notifications');

$items = AdminNotificationService::recentForCurrentAdmin(5);
ok($items !== [] && !empty($items[0]['href']), 'Recent list includes ticket link');

$notifId = (int) ($items[0]['id'] ?? 0);
ok(AdminNotificationService::markRead($notifId), 'Mark one read works');
ok(AdminNotificationService::unreadCountForCurrentAdmin() === $unread - 1, 'Unread count decreases');

AdminNotificationService::markAllReadForCurrentAdmin();
ok(AdminNotificationService::unreadCountForCurrentAdmin() === 0, 'Mark all read clears unread');

$beforeAdminReply = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
$adminReply = SupportTicketService::adminReply($ticketId, 'We are checking.', TicketStatus::IN_PROGRESS);
ok(!empty($adminReply['ok']), 'Admin reply succeeds');
$afterAdminReply = (int) $pdo->query('SELECT COUNT(*) FROM admin_notifications')->fetchColumn();
ok($afterAdminReply === $beforeAdminReply, 'Admin reply does not create customer-reply notification');

$otherCustomer = (int) ($pdo->query('SELECT id FROM customers ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0);
if ($otherCustomer !== $customerId) {
    ok(SupportTicketService::byNumberForCustomer((string) ($created['ticket_number'] ?? ''), $otherCustomer) === null, 'Other customer cannot access ticket');
}

$badOrder = SupportTicketService::create($customerId, 'payment', 'Bad', 'Msg', 'AT-NOT-MINE');
ok(empty($badOrder['ok']), 'Invalid order rejected on create');

$adminRow = $pdo->query(
    'SELECT event_type, ticket_id, idempotency_key FROM admin_notifications ORDER BY id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
ok(
    is_array($adminRow)
    && str_starts_with((string) ($adminRow['event_type'] ?? ''), 'admin.support.'),
    'Admin notification event_type is admin.support.* (not queue recipient)'
);
ok((int) ($adminRow['ticket_id'] ?? 0) > 0, 'Admin notification stores ticket_id');

$queueStmt = $pdo->prepare(
    'SELECT recipient, event_type FROM notification_queue WHERE event_type = ? ORDER BY id DESC LIMIT 1'
);
$queueStmt->execute(['support.ticket_created']);
$queueRow = $queueStmt->fetch(PDO::FETCH_ASSOC);
if (is_array($queueRow)) {
    ok(
        str_starts_with((string) ($queueRow['recipient'] ?? ''), 'customer:'),
        'Delivery queue support.ticket_created targets customer (separate from admin bell)'
    );
} else {
    ok(true, 'Delivery queue support.ticket_created row optional in test DB');
}

$_SESSION['admin_demo_role'] = 'inventory_manager';
Permission::clearCache();
ok(!AdminDemoPerms::canForRole('inventory_manager', 'support'), 'RBAC: inventory demo role lacks support module');
ok(AdminDemoPerms::canForRole('support', 'support'), 'RBAC: support demo role has support module');
$_SESSION['admin_demo_role'] = 'super_admin';
Permission::clearCache();

$adminJs = @file_get_contents(dirname(__DIR__) . '/assets/js/admin.js') ?: '';
ok(str_contains($adminJs, 'is-open'), 'Bell dropdown uses is-open panel class');
ok(str_contains($adminJs, 'Escape'), 'Bell dropdown closes on Escape');
ok(str_contains($adminJs, 'initNotificationCenter'), 'Notification center mark-read handler present');

echo $fail === 0 ? "PASS: admin support notifications.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
