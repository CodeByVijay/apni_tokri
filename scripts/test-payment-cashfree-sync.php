<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$successPayment = [
    'payment_status' => 'SUCCESS',
    'is_captured' => true,
    'cf_payment_id' => 'cf_pay_test_1',
    'payment_amount' => 100.0,
    'payment_currency' => 'INR',
];
$found = CashfreeGateway::findSuccessfulCapturedPayment([$successPayment]);
ok($found !== null && ($found['cf_payment_id'] ?? '') === 'cf_pay_test_1', 'findSuccessfulCapturedPayment detects SUCCESS+captured');

ok(
    CashfreeGateway::findSuccessfulCapturedPayment([['payment_status' => 'SUCCESS', 'is_captured' => false]]) === null,
    'Uncaptured SUCCESS not treated as complete'
);

ok(
    !CashfreeGateway::hasHardGatewayFailure([['payment_status' => 'USER_DROPPED']]),
    'USER_DROPPED is not treated as hard gateway failure'
);
ok(
    CashfreeGateway::hasHardGatewayFailure([['payment_status' => 'FAILED']]),
    'FAILED is hard gateway failure'
);

ok(
    !CashfreeGateway::hasTerminalFailure([['payment_status' => 'PENDING']]),
    'Pending payment not terminal failure'
);

$badSync = PaymentService::syncCashfreeOrderPayment('AT-NO-SUCH-ORDER', 1);
ok(($badSync['state'] ?? '') === 'error', 'Invalid order reference returns error state');

if (Install::isSchemaReady()) {
    $stmt = Database::pdo()->prepare('SELECT customer_id FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute(['AT-261102-5DB0C9']);
    $cid = (int) ($stmt->fetchColumn() ?: 0);
    if ($cid > 0 && CashfreeGateway::isConfigured()) {
        $before = Database::pdo()->prepare('SELECT payment_status, status FROM orders WHERE order_number = ?');
        $before->execute(['AT-261102-5DB0C9']);
        $b = $before->fetch(PDO::FETCH_ASSOC) ?: [];
        $sync1 = PaymentService::syncCashfreeOrderPayment('AT-261102-5DB0C9', $cid);
        $sync2 = PaymentService::syncCashfreeOrderPayment('AT-261102-5DB0C9', $cid);
        ok(in_array($sync1['state'] ?? '', ['confirmed', 'pending', 'reconciliation', 'error'], true), 'Live sync returns known state');
        ok(
            ($sync2['state'] ?? '') === 'confirmed' || !empty($sync2['idempotent']),
            'Duplicate sync is idempotent when already confirmed'
        );
        $after = Database::pdo()->prepare(
            'SELECT o.payment_status, o.status, p.status AS pay_status, p.gateway_payment_id IS NOT NULL AS has_gw_pay
             FROM orders o LEFT JOIN payments p ON p.order_id = o.id AND p.idempotency_key = ?
             WHERE o.order_number = ?'
        );
        $after->execute(['pay_AT-261102-5DB0C9_1', 'AT-261102-5DB0C9']);
        $a = $after->fetch(PDO::FETCH_ASSOC) ?: [];
        if (($sync1['state'] ?? '') === 'confirmed') {
            ok(($a['payment_status'] ?? '') === 'paid', 'Verified sync sets order payment_status paid');
            ok(($a['pay_status'] ?? '') === 'captured', 'Payment row captured after API sync');
        } else {
            echo "SKIP: live Cashfree sync state=" . ($sync1['state'] ?? '') . " (sandbox/API)\n";
        }
    } else {
        echo "SKIP: fixture order or Cashfree config unavailable\n";
    }
}

echo $fail === 0 ? "PASS: Cashfree payment sync checks.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
