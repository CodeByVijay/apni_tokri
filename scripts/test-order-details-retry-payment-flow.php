<?php

declare(strict_types=1);

/**
 * Order-details Retry Payment + Cashfree idempotency (mocked PG only).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-order-details-retry-payment-flow.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
function okR(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$origHandler = PaymentHttp::$testHandler;
$origActive = AppSettings::get('payment.active_gateway', null);
$origTest = AppSettings::get('payment.test_mode', null);
$origCfEn = AppSettings::get('cashfree.enabled', null);
$origCfId = AppSettings::get('cashfree.app_id', null);
$origCfSec = AppSettings::getSecret('cashfree.secret_key', null);

Install::ensureConnection();
$pdo = Database::pdo();
$tag = substr(bin2hex(random_bytes(4)), 0, 6);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9000000088']);
$pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute(['9000000088', 'Retry Test']);
$cid = (int) $pdo->lastInsertId();

AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::set('payment.test_mode', '1');
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_retry_test_secret', true);
AppSettings::resetCache();

$createCount = 0;
$failNextCreate = false;

PaymentHttp::$testHandler = static function (string $method, string $url, ?array $body) use (&$createCount, &$failNextCreate): array {
    if ($method === 'POST' && str_contains($url, '/orders') && !str_contains($url, '/refunds')) {
        $createCount++;
        if ($failNextCreate) {
            return [
                'ok' => false,
                'status' => 502,
                'body' => '{"message":"upstream"}',
                'json' => ['message' => 'upstream'],
                'error' => null,
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"cf_order_id":"888001","payment_session_id":"session_retry_' . $createCount . '"}',
            'json' => ['cf_order_id' => '888001', 'payment_session_id' => 'session_retry_' . $createCount],
            'error' => null,
        ];
    }
    if ($method === 'GET' && str_contains($url, '/orders/')) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"order_status":"ACTIVE","cf_order_id":"888001","order_amount":75,"payment_session_id":"session_retry_1"}',
            'json' => [
                'order_status' => 'ACTIVE',
                'cf_order_id' => '888001',
                'order_amount' => 75,
                'payment_session_id' => 'session_retry_1',
            ],
            'error' => null,
        ];
    }
    if ($method === 'GET' && str_contains($url, '/orders/') && str_contains($url, '/payments')) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '[]',
            'json' => [],
            'error' => null,
        ];
    }

    return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null, 'error' => null];
};

try {
    $_SESSION['customer_id'] = $cid;
    $orderNumber = 'VT-RETRY-' . $tag;
    $pdo->prepare(
        "INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, delivery_fee, payment_status, placed_at)
         VALUES (?, ?, 'pending_payment', 70, 75, 5, 'pending', NOW())"
    )->execute([$orderNumber, $cid]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, 1, ?, ?, 1, 70, 70)'
    )->execute([$orderId, 'Item', '1']);

    $beforeOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $cid)->fetchColumn();

  // 1. Retry preparation does not throw and returns checkout or safe error
    $prep = null;
    $threw = false;
    try {
        $prep = PaymentService::prepareStorefrontOrderPayment($orderNumber, $cid, true);
    } catch (Throwable $e) {
        $threw = true;
    }
    okR(!$threw, '1 retry prepare does not throw (no HTTP 500 class failure)');
    okR(is_array($prep), '1 retry prepare returns array');

    // 2. Success path includes Cashfree session for redirect/autolaunch
    if (!empty($prep['ok'])) {
        okR(
            trim((string) (($prep['checkout'] ?? [])['payment_session_id'] ?? '')) !== '',
            '2 Cashfree success includes payment_session_id'
        );
        okR(($prep['checkout']['mode'] ?? '') === 'cashfree', '2 checkout mode is cashfree');
    } else {
        okR(isset($prep['error']) && $prep['error'] !== '', '2 API failure returns customer-safe error');
    }

    // 3. API failure shows safe message
    $failNextCreate = true;
    $pdo->prepare('UPDATE payments SET status = ? WHERE order_id = ?')->execute(['failed', $orderId]);
    unset($_SESSION['checkout_payment'][$orderNumber], $_SESSION['checkout_payment_autolaunch'][$orderNumber]);
    $bad = PaymentService::prepareStorefrontOrderPayment($orderNumber, $cid, true);
    okR(empty($bad['ok']) && !empty($bad['error']), '3 Cashfree API failure surfaces safe customer message');
    okR(!str_contains(strtolower((string) ($bad['error'] ?? '')), 'stack'), '3 error message is customer-safe');

    $failNextCreate = false;

    // 4–5. No extra order rows; limit duplicate payment rows on idempotent retry
    $afterOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $cid)->fetchColumn();
    okR($afterOrders === $beforeOrders, '4 retry does not create another order');
    $payCountAfterFail = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId)->fetchColumn();

    $prepA = PaymentService::prepareStorefrontOrderPayment($orderNumber, $cid, true);
  $payCountA = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId)->fetchColumn();
    unset($_SESSION['checkout_payment'][$orderNumber]);
    $prepB = PaymentService::prepareStorefrontOrderPayment($orderNumber, $cid, true);
    $payCountB = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId)->fetchColumn();
    okR($payCountB <= $payCountA + 1, '5 repeated retry does not explode payment attempt rows');
    if (!empty($prepA['ok']) && !empty($prepB['ok'])) {
        okR(
            ($prepA['checkout']['payment_session_id'] ?? '') === ($prepB['checkout']['payment_session_id'] ?? '')
            || $createCount <= 2,
            '9 repeated successful retry is idempotent or reuses session'
        );
    }

    // 6–8. Timeline rules for pending_payment
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $stmt->execute([$orderId]);
    $orderRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $payments = PaymentRepository::listPaymentsForOrder($orderId);
    $timeline = CustomerOrderTimeline::build($orderRow, $payments, $pdo);
    $pending = [];
    foreach ($timeline as $step) {
        if (empty($step['done'])) {
            $pending[] = (string) ($step['status'] ?? '');
        }
    }
    okR(in_array('Awaiting payment', $pending, true), '6 pending_payment current step is Awaiting payment');
    okR(!in_array('Order confirmed', $pending, true), '7 pending_payment never highlights Order confirmed');

    // Simulate verified payment → confirmed
    $pdo->prepare('UPDATE orders SET status = ?, payment_status = ? WHERE id = ?')
        ->execute([OrderStatus::CONFIRMED, 'paid', $orderId]);
    $pdo->prepare(
        'INSERT INTO order_status_history (order_id, status, note, created_at) VALUES (?, ?, ?, NOW())'
    )->execute([$orderId, OrderStatus::CONFIRMED, 'Payment verified server-side']);
    $stmt->execute([$orderId]);
    $orderRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $timelinePaid = CustomerOrderTimeline::build($orderRow, $payments, $pdo);
    $pendingPaid = [];
    foreach ($timelinePaid as $step) {
        if (empty($step['done'])) {
            $pendingPaid[] = (string) ($step['status'] ?? '');
        }
    }
    okR(
        in_array('Order processing', $pendingPaid, true) || in_array('Out for delivery', $pendingPaid, true),
        '8 verified payment shows fulfilment milestones'
    );

    // Static: order-details guards retry POST
    $od = (string) file_get_contents(APNI_TOKRI_ROOT . '/order-details.php');
    okR(str_contains($od, 'storefront_retry_payment:'), 'order-details logs retry Throwable safely');
    okR(str_contains($od, 'isRetryPaymentPost'), 'order-details skips sync on retry POST');

    $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
} finally {
    PaymentHttp::$testHandler = $origHandler;
    if ($origActive !== null) {
        AppSettings::set('payment.active_gateway', $origActive);
    }
    if ($origTest !== null) {
        AppSettings::set('payment.test_mode', $origTest);
    }
    if ($origCfEn !== null) {
        AppSettings::set('cashfree.enabled', $origCfEn);
    }
    if ($origCfId !== null) {
        AppSettings::set('cashfree.app_id', $origCfId);
    }
    if ($origCfSec !== null) {
        AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, $origCfSec, true);
    }
    AppSettings::resetCache();
}

echo $fail > 0 ? "FAILURES: $fail\n" : "All retry-payment flow checks passed.\n";
exit($fail > 0 ? 1 : 0);
