<?php

declare(strict_types=1);

/**
 * Cashfree payment reconciliation + admin refresh (mocked PG API; no real payments).
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-payment-cashfree-reconcile.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

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

TestScriptSafety::assertSafeToMutateSettings();

Install::ensureConnection();
$pdo = Database::pdo();

$settingsSnap = TestScriptSafety::snapshotPaymentGatewaySettings();
$origCfSecret = TestScriptSafety::stashCashfreeSecret();

AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::set('payment.test_mode', '1');
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_reconcile_test_secret', true);
AppSettings::resetCache();

$adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
okR($adminId > 0, 'Admin user exists for audit log');
if ($adminId <= 0) {
    TestScriptSafety::restorePaymentGatewaySettings($settingsSnap);
    TestScriptSafety::restoreCashfreeSecret($origCfSecret);
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
        if (($mockResponses['order_http'] ?? 0) === 404) {
            return ['ok' => false, 'status' => 404, 'body' => '{"message":"not found"}', 'json' => ['message' => 'not found']];
        }
        $body = $mockResponses['order'] ?? ['order_status' => 'ACTIVE', 'cf_order_id' => '7366818047', 'order_amount' => 65];

        return ['ok' => true, 'status' => 200, 'body' => json_encode($body), 'json' => $body];
    }

    return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
};

try {
    $custId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn());
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

    $mockResponses['order'] = ['order_status' => 'ACTIVE', 'cf_order_id' => $cfOrderId, 'order_amount' => 65];
    $mockResponses['payments'] = ['payments' => [['payment_status' => 'USER_DROPPED']]];
    $drop = PaymentService::reconcileCashfreeOrderFromGateway($orderNumber, null, 'admin_refresh', $adminId);
    okR(($drop['state'] ?? '') === 'pending', 'Admin reconcile USER_DROPPED → pending');
    $st = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'pending', 'Order stays pending after USER_DROPPED');

    $mockResponses['payments'] = ['payments' => [['payment_status' => 'FAILED']]];
    $failSync = PaymentService::reconcileCashfreeOrderFromGateway($orderNumber, null, 'admin_refresh', $adminId);
    okR(($failSync['state'] ?? '') === 'failed', 'Admin reconcile FAILED → failed state');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'failed', 'Order payment_status failed after hard failure');

    $payCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId)->fetchColumn();
    PaymentService::adminRefreshCashfreePayment($orderNumber, $adminId);
    $payCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId)->fetchColumn();
    okR($payCountBefore === $payCountAfter, 'Repeated refresh does not duplicate payment rows');

    $pdo->prepare('UPDATE orders SET payment_status = ?, status = ? WHERE id = ?')->execute(['pending', 'pending_payment', $orderId]);
    $pdo->prepare('UPDATE payments SET status = ? WHERE order_id = ?')->execute(['pending', $orderId]);

    PaymentHttp::$testHandler = static function (): array {
        return ['ok' => false, 'status' => 401, 'body' => '', 'json' => ['message' => 'auth'], 'error' => 'auth'];
    };
    $apiErr = PaymentService::adminRefreshCashfreePayment($orderNumber, $adminId);
    okR(empty($apiErr['ok']) && ($apiErr['state'] ?? '') === 'error', 'API 401 does not confirm payment');
    okR(str_contains((string) ($apiErr['message'] ?? ''), 'credentials'), '401 admin message mentions credentials');
    $st->execute([$orderId]);
    okR((string) $st->fetchColumn() === 'pending', 'Order unchanged after API 401');

    $statusCases = [
        404 => 'merchant ID',
        429 => 'rate-limited',
        503 => 'server error',
    ];
    foreach ($statusCases as $http => $needle) {
        PaymentHttp::$testHandler = static function () use ($http): array {
            return ['ok' => false, 'status' => $http, 'body' => '', 'json' => null];
        };
        $fetch = CashfreeGateway::fetchOrder($orderNumber, 'production');
        okR(empty($fetch['ok']), 'fetchOrder fails on HTTP ' . $http);
        okR(str_contains(strtolower((string) ($fetch['error'] ?? '')), strtolower($needle)), 'HTTP ' . $http . ' admin message is descriptive');
    }

    PaymentHttp::$testHandler = static function (): array {
        return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'timeout'];
    };
    $to = CashfreeGateway::fetchOrder($orderNumber, 'production');
    okR(str_contains((string) ($to['error'] ?? ''), 'timed out') || str_contains((string) ($to['error'] ?? ''), 'network'), 'Network timeout message');

    PaymentHttp::$testHandler = static function (string $method, string $url) use (&$mockResponses): array {
        if ($method === 'GET' && str_contains($url, '/orders/') && !str_contains($url, '/payments')) {
            return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
        }
        if ($method === 'GET') {
            return ['ok' => true, 'status' => 200, 'body' => '{"payments":[]}', 'json' => ['payments' => []]];
        }

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
    };

    $noGwOrder = 'AT-NOGW-' . $tag;
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
         payment_status, placed_at) VALUES (?, ?, ?, 5, 0, 0, 5, ?, NOW())'
    )->execute([$noGwOrder, $custId, 'pending_payment', 'failed']);
    $noGwId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key, metadata_json)
         VALUES (?, ?, NULL, 5, ?, ?, ?, ?)'
    )->execute([
        $noGwId,
        'cashfree',
        'INR',
        'failed',
        'pay_' . $noGwOrder . '_1',
        json_encode(['payment_environment' => 'production'], JSON_THROW_ON_ERROR),
    ]);
    $nogw = PaymentService::adminRefreshCashfreePayment($noGwOrder, $adminId);
    okR(($nogw['state'] ?? '') === 'error', 'Failed initiation + 404 returns error');
    okR(
        str_contains((string) ($nogw['message'] ?? ''), 'without a gateway order ID')
        || str_contains((string) ($nogw['message'] ?? ''), 'not created'),
        'Missing CF order explains failed initiation'
    );

    PaymentHttp::$testHandler = static function (string $method, string $url) use ($cfOrderId): array {
        if ($method === 'GET' && str_contains($url, '/orders/') && !str_contains($url, '/payments')) {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode(['order_status' => 'ACTIVE', 'cf_order_id' => $cfOrderId, 'order_amount' => 5]),
                'json' => ['order_status' => 'ACTIVE', 'cf_order_id' => $cfOrderId, 'order_amount' => 5],
            ];
        }
        if ($method === 'GET') {
            return ['ok' => true, 'status' => 200, 'body' => '{"payments":[]}', 'json' => ['payments' => []]];
        }

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
    };
    $backfill = PaymentService::adminRefreshCashfreePayment($noGwOrder, $adminId);
    okR(($backfill['state'] ?? '') === 'pending' || ($backfill['state'] ?? '') === 'error', 'Backfill attempt completes without new payment row');
    $storedCf = (string) $pdo->query(
        'SELECT gateway_order_id FROM payments WHERE order_id = ' . $noGwId . ' LIMIT 1'
    )->fetchColumn();
    okR($storedCf === $cfOrderId, 'Backfills gateway_order_id from Cashfree GET when empty');

    okR(
        PaymentService::adminRefreshCashfreePayment('AT-NO-SUCH-' . $tag, $adminId)['state'] === 'error',
        'Missing order returns error'
    );

    $sandboxUrls = [];
    PaymentHttp::$testHandler = static function (string $method, string $url) use (&$sandboxUrls): array {
        $sandboxUrls[] = $url;

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
    };
    CashfreeGateway::fetchOrder('AT-SBX-' . $tag, 'sandbox');
    okR($sandboxUrls !== [] && str_contains((string) $sandboxUrls[0], 'sandbox.cashfree.com'), 'Sandbox metadata uses sandbox API host');
} finally {
    PaymentHttp::$testHandler = null;
    TestScriptSafety::restorePaymentGatewaySettings($settingsSnap);
    TestScriptSafety::restoreCashfreeSecret($origCfSecret);
    if (isset($orderId) && $orderId > 0) {
        $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        $pdo->prepare("DELETE FROM admin_audit_logs WHERE entity_type = 'orders' AND entity_id = ?")->execute([(string) $orderId]);
    }
    if (isset($noGwId) && $noGwId > 0) {
        $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$noGwId]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$noGwId]);
    }
}

echo $fail === 0 ? "All Cashfree reconcile tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
