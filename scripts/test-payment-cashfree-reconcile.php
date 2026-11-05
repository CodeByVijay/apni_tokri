<?php

declare(strict_types=1);

/**
 * Cashfree payment reconciliation (mocked PG API; no real payments).
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-payment-cashfree-reconcile.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function okR(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();

AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::set('payment.test_mode', '1');
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_reconcile_test_secret', true);
AppSettings::resetCache();

$adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
okR($adminId > 0, 'Admin user exists for audit log');
if ($adminId <= 0) {
    exit(1);
}

$tag = substr(bin2hex(random_bytes(4)), 0, 8);
$orderNumber = 'AT-TEST-' . $tag;
$cfOrderId = '7366818047';

/** @var array<string, mixed> $mockResponses */
$mockResponses = [];

PaymentHttp::$testHandler = static function (string $method, string $url) use (&$mockResponses): array {
    if ($method === 'GET' && str_contains($url, '/orders/') && str_contains($url, '/payments')) {
        $body = $mockResponses['payments'] ?? ['payments' => []];

        return ['ok' => true, 'status' => 200, 'body' => json_encode($body), 'json' => $body];
    }
    if ($method === 'GET' && str_contains($url, '/orders/')) {
        $body = $mockResponses['order'] ?? ['order_status' => 'ACTIVE', 'cf_order_id' => '7366818047', 'order_amount' => 65];

        return ['ok' => true, 'status' => 200, 'body' => json_encode($body), 'json' => $body];
    }

    return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
};

try {
    $custId = (int) $pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($custId <= 0) {
        $pdo->exec("INSERT INTO customers (mobile, name, is_active) VALUES ('9999{$tag}', 'Reconcile Test', 1)");
        $custId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
         payment_status, delivery_slot, placed_at)
         VALUES (?, ?, ?, 60, 0, 5, 65, ?, ?, NOW())'
    )->execute([$orderNumber, $custId, 'pending_payment', 'pending', 'morning']);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key)
         VALUES (?, ?, ?, 65, ?, ?, ?)'
    )->execute([$orderId, 'cashfree', $cfOrderId, 'INR', 'pending', 'pay_' . $orderNumber . '_1']);

    okR(!CashfreeGateway::hasHardGatewayFailure([['payment_status' => 'USER_DROPPED']]), 'USER_DROPPED is not hard failure');
    $analysisDrop = CashfreeGateway::analyzeGatewayPayments([['payment_status' => 'USER_DROPPED']]);
    okR(!empty($analysisDrop['retryable']) && empty($analysisDrop['should_mark_order_failed']), 'USER_DROPPED stays retryable pending');

    $mockResponses['order'] = ['order_status' => 'ACTIVE', 'cf_order_id' => $cfOrderId, 'order_amount' => 65];
    $mockResponses['payments'] = ['payments' => [['payment_status' => 'USER_DROPPED']]];
    $drop = PaymentService::reconcileCashfreeOrderFromGateway($orderNumber, null, 'admin_refresh', $adminId);
    okR(($drop['state'] ?? '') === 'pending', 'Admin reconcile USER_DROPPED → pending');
    $st = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'pending', 'Order stays pending after USER_DROPPED');

    $mockResponses['payments'] = ['payments' => [['payment_status' => 'FAILED']]];
    $analysisFail = CashfreeGateway::analyzeGatewayPayments($mockResponses['payments']['payments']);
    okR(!empty($analysisFail['should_mark_order_failed']), 'FAILED alone marks order failed');
    $failSync = PaymentService::reconcileCashfreeOrderFromGateway($orderNumber, null, 'admin_refresh', $adminId);
    okR(($failSync['state'] ?? '') === 'failed', 'Admin reconcile FAILED → failed state');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'failed', 'Order payment_status failed after hard failure');

    $pdo->prepare('UPDATE orders SET payment_status = ?, status = ? WHERE id = ?')->execute(['pending', 'pending_payment', $orderId]);
    $pdo->prepare('UPDATE payments SET status = ? WHERE order_id = ?')->execute(['pending', $orderId]);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key)
         VALUES (?, ?, ?, 65, ?, ?, ?)'
    )->execute([$orderId, 'cashfree', $cfOrderId, 'INR', 'pending', 'pay_' . $orderNumber . '_2']);

    $mockResponses['payments'] = ['payments' => [
        ['payment_status' => 'FAILED', 'cf_payment_id' => 'old_fail'],
        ['payment_status' => 'PENDING'],
    ]];
    $mixed = PaymentService::reconcileCashfreeOrderFromGateway($orderNumber, null, 'admin_refresh', $adminId);
    okR(($mixed['state'] ?? '') === 'pending', 'Open PENDING attempt prevents order fail despite older FAILED');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'pending', 'Order still pending with newer open attempt');

    $mockResponses = ['order' => null, 'payments' => null];
    PaymentHttp::$testHandler = static function (): array {
        return ['ok' => false, 'status' => 401, 'body' => '', 'json' => null, 'error' => 'auth'];
    };
    $apiErr = PaymentService::adminRefreshCashfreePayment($orderNumber, $adminId);
    okR(empty($apiErr['ok']) && ($apiErr['state'] ?? '') === 'error', 'API auth error does not confirm payment');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'pending', 'Order unchanged after API error');

    $audit = $pdo->prepare(
        "SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'payment.cashfree_reconcile' AND entity_id = ?"
    );
    $audit->execute([(string) $orderId]);
    okR((int) $audit->fetchColumn() >= 1, 'Reconciliation writes admin audit log');

    okR(
        PaymentService::adminRefreshCashfreePayment('AT-NO-SUCH-' . $tag, $adminId)['state'] === 'error',
        'Missing order returns error'
    );
} finally {
    PaymentHttp::$testHandler = null;
    if (isset($orderId) && $orderId > 0) {
        $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $pdo->prepare("DELETE FROM admin_audit_logs WHERE entity_type = 'orders' AND entity_id = ?")->execute([(string) $orderId]);
    }
}

echo $fail === 0 ? "All Cashfree reconcile tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
