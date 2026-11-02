<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}
Install::ensureConnection();
$pdo = Database::pdo();
$fail = 0;
function ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $l . "\n";
    if (!$c) {
        $fail++;
    }
}

$ordA = $pdo->query('SELECT order_number, customer_id FROM orders WHERE customer_id IS NOT NULL ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT customer_id, order_number FROM orders WHERE customer_id IS NOT NULL AND customer_id <> ? ORDER BY id DESC LIMIT 1');
$stmt->execute([(int) $ordA['customer_id']]);
$ordB = $stmt->fetch(PDO::FETCH_ASSOC);
$custA = (int) $ordA['customer_id'];
$custB = (int) $ordB['customer_id'];
$created = [];

// Guest general ticket
$g = SupportTicketService::create(null, 'other', 'QA guest', 'Guest general ticket from QA', null);
ok(!empty($g['ok']) && !empty($g['ticket_number']), 'Guest can create general ticket');
$created[] = (string) ($g['ticket_number'] ?? '');
$gRow = SupportTicketService::byNumber((string) $g['ticket_number']);
ok($gRow !== null && $gRow['customer_id'] === null && $gRow['order_id'] === null, 'Guest ticket has no customer/order');

// Guest supplying an order number must not link it
$g2 = SupportTicketService::create(null, 'other', 'QA guest order', 'Guest tries order link', (string) $ordA['order_number']);
$created[] = (string) ($g2['ticket_number'] ?? '');
$g2Row = !empty($g2['ticket_number']) ? SupportTicketService::byNumber((string) $g2['ticket_number']) : null;
ok($g2Row !== null && $g2Row['order_id'] === null, 'Guest cannot link an order');

// Customer with own order
$own = SupportTicketService::create($custA, 'quality', 'QA own', 'Own order ticket', (string) $ordA['order_number']);
ok(!empty($own['ok']), 'Customer can select own order');
$created[] = (string) ($own['ticket_number'] ?? '');
$ownRow = SupportTicketService::byNumber((string) $own['ticket_number']);
ok($ownRow !== null && (int) $ownRow['order_id'] > 0, 'Own order id stored');

// Foreign order rejected
$before = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
$bad = SupportTicketService::create($custA, 'quality', 'QA foreign', 'Foreign order', (string) $ordB['order_number']);
$after = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
ok(empty($bad['ok']) && $before === $after, 'Customer cannot link another customer\'s order (no ticket created)');

// IDOR on ticket read
ok(SupportTicketService::byNumberForCustomer((string) $own['ticket_number'], $custB) === null, 'Other customer cannot open ticket');
ok(SupportTicketService::byNumberForCustomer((string) $own['ticket_number'], $custA) !== null, 'Owner can open ticket');

// Customer view hides non customer/admin message types
$src = (string) file_get_contents(dirname(__DIR__) . '/support-ticket.php');
ok(strpos($src, "!== 'customer' && !\$isTeam") !== false, 'support-ticket.php skips non customer/admin message senders');
ok(strpos($src, 'internal_notes') === false, 'support-ticket.php never reads internal notes');

// Refund internal notes stripped for customers
$ref = (string) file_get_contents(dirname(__DIR__) . '/includes/trust/refund.php');
ok(strpos($ref, "unset(\$row['internal_notes'])") !== false, 'Refund customer rows unset internal_notes');

// Cleanup
foreach ($created as $tn) {
    if ($tn === '') {
        continue;
    }
    $t = SupportTicketService::byNumber($tn);
    if ($t) {
        $pdo->prepare('DELETE FROM support_messages WHERE ticket_id = ?')->execute([(int) $t['id']]);
        $pdo->prepare('DELETE FROM support_tickets WHERE id = ?')->execute([(int) $t['id']]);
    }
}

echo $fail === 0 ? "\nPASS: support access tests\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
