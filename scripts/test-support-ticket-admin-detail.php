<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
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
$otherStmt = $pdo->query('SELECT id FROM customers ORDER BY id DESC LIMIT 1');
$otherCustomerId = (int) ($otherStmt->fetchColumn() ?: 0);
if ($otherCustomerId === $customerId) {
    $otherCustomerId = $customerId + 999;
}

$orderStmt = $pdo->prepare('SELECT order_number FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1');
$orderStmt->execute([$customerId]);
$orderNumber = (string) ($orderStmt->fetchColumn() ?: '');
if ($orderNumber === '') {
    fwrite(STDERR, "Need at least one order for customer {$customerId}.\n");
    exit(1);
}

$withOrder = SupportTicketService::create($customerId, 'quality', 'Status', 'What is delivery status.', $orderNumber);
ok(!empty($withOrder['ok']), 'Ticket with valid linked order creates');
$ticketNumber = (string) ($withOrder['ticket_number'] ?? '');
$idStmt = $pdo->prepare('SELECT id FROM support_tickets WHERE ticket_number = ? LIMIT 1');
$idStmt->execute([$ticketNumber]);
$ticketId = (int) ($idStmt->fetchColumn() ?: 0);
$detail = SupportTicketService::adminDetailFor($ticketId);
ok($detail !== null, 'Admin detail loads');
ok($detail !== null && (string) ($detail['ticket']['order_number'] ?? '') === $orderNumber, 'Admin detail shows correct order number');
ok($detail !== null && ($detail['order_items'] ?? []) !== [], 'Admin detail includes order items snapshot');
ok(count($detail['messages'] ?? []) >= 1, 'Conversation includes initial customer message');

$noOrder = SupportTicketService::create($customerId, 'other', 'Help', 'General question.', null);
ok(!empty($noOrder['ok']), 'Ticket without order creates');
$idStmt->execute([(string) ($noOrder['ticket_number'] ?? '')]);
$noDetail = SupportTicketService::adminDetailFor((int) ($idStmt->fetchColumn() ?: 0));
ok($noDetail !== null && (int) ($noDetail['ticket']['order_id'] ?? 0) === 0, 'Ticket without order has no order_id');

$badOrder = SupportTicketService::create($customerId, 'payment', 'Pay', 'Issue', 'AT-FAKE-NOT-MINE');
ok(empty($badOrder['ok']), 'Foreign order number rejected on create');

if ($otherCustomerId > 0 && $otherCustomerId !== $customerId) {
    $foreign = SupportTicketService::byNumberForCustomer($ticketNumber, $otherCustomerId);
    ok($foreign === null, 'Customer cannot access another customer ticket');
}

$reply = SupportTicketService::adminReply((int) $detail['ticket']['id'], 'We are checking delivery.', TicketStatus::IN_PROGRESS);
ok(!empty($reply['ok']), 'Admin reply still works');
$detail2 = SupportTicketService::adminDetailFor((int) $detail['ticket']['id']);
ok(count($detail2['messages'] ?? []) >= 2, 'Multiple conversation messages in order');

$dupReply = SupportTicketService::adminReply((int) $detail['ticket']['id'], 'Follow up.', null);
ok(!empty($dupReply['ok']), 'Second admin reply works');

$nullOrderCount = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets WHERE order_id IS NULL')->fetchColumn();
echo "INFO: tickets_without_order_id={$nullOrderCount}\n";

echo $fail === 0 ? "PASS: support ticket admin detail checks.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
