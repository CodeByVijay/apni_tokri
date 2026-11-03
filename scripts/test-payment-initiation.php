<?php

declare(strict_types=1);

/**
 * Payment initiation + gateway readiness (mocked HTTP — no real charges).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
function ok(bool $c, string $m): void
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
$origRzEn = AppSettings::get('razorpay.enabled', null);
$origRzKid = AppSettings::get('razorpay.key_id', null);
$origRzSec = AppSettings::getSecret('razorpay.key_secret', null);
$origCfEn = AppSettings::get('cashfree.enabled', null);
$origCfId = AppSettings::get('cashfree.app_id', null);
$origCfSec = AppSettings::getSecret('cashfree.secret_key', null);

Install::ensureConnection();
$pdo = Database::pdo();
$tag = substr(md5((string) microtime(true)), 0, 6);
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9000000099']);
$pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute(['9000000099', 'Pay Test']);
$cid = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customer_addresses (customer_id, label, line1, pincode, city, state, is_default) VALUES (?, ?, ?, ?, ?, ?, 1)')
    ->execute([$cid, 'Home', '1 Pay St', '452010', 'Indore', 'Madhya Pradesh']);
$addrId = (int) $pdo->lastInsertId();
$pdo->prepare(
    "INSERT INTO orders (order_number, customer_id, customer_address_id, status, payment_status, subtotal, grand_total, delivery_fee)
     VALUES (?, ?, ?, 'pending_payment', 'pending', 100, 100, 0)"
)->execute(["AT-PAY-{$tag}", $cid, $addrId]);
$orderId = (int) $pdo->lastInsertId();
$orderNo = "AT-PAY-{$tag}";

try {
    AppSettings::set('payment.test_mode', '1');
    AppSettings::set('razorpay.enabled', '1');
    AppSettings::set('razorpay.key_id', 'rzp_test_mock123456');
    AppSettings::set('razorpay.key_secret', 'mock_secret_12345', true);
    AppSettings::set('cashfree.enabled', '1');
    AppSettings::set('cashfree.app_id', 'TESTAPPID123456789012345678901234567890');
    AppSettings::set('cashfree.secret_key', 'cfsk_mock_secret', true);
    AppSettings::resetCache();

    ok(PaymentGatewayConfig::isRazorpayOperational(), 'Razorpay operational with valid-looking test keys');
    ok(PaymentGatewayConfig::readinessFor('razorpay')['ok'], 'readiness razorpay passes');

    AppSettings::set('razorpay.key_id', 'bad_key');
    AppSettings::resetCache();
    ok(!PaymentGatewayConfig::isRazorpayOperational(), 'invalid razorpay key id not operational');
    ok(empty(PaymentGatewayConfig::readinessFor('razorpay')['ok']), 'readiness rejects invalid key id');
    AppSettings::set('razorpay.key_id', 'rzp_test_mock123456');
    AppSettings::set('payment.test_mode', '0');
    AppSettings::resetCache();
    ok(empty(PaymentGatewayConfig::readinessFor('razorpay')['ok']), 'test key rejected in live mode');
    AppSettings::set('payment.test_mode', '1');
    AppSettings::resetCache();

    PaymentHttp::$testHandler = static function (string $method, string $url, ?array $body, array $headers, ?string $u, ?string $p): array {
        if (strpos($url, 'api.razorpay.com') !== false) {
            return ['ok' => true, 'status' => 200, 'body' => '{"id":"order_mock_rzp"}', 'json' => ['id' => 'order_mock_rzp'], 'error' => null];
        }
        if (strpos($url, 'cashfree.com') !== false && strpos($url, '/orders') !== false && $method === 'POST') {
            return [
                'ok' => true, 'status' => 200,
                'body' => '{"cf_order_id":123,"payment_session_id":"session_mock_cf"}',
                'json' => ['cf_order_id' => '123', 'payment_session_id' => 'session_mock_cf'],
                'error' => null,
            ];
        }

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null, 'error' => 'unexpected url'];
    };

    AppSettings::set('payment.active_gateway', 'razorpay');
    AppSettings::resetCache();
    $rz = PaymentService::initiateForOrder($orderId, $orderNo, 100.0, '9000000099');
    ok(!empty($rz['ok']) && ($rz['checkout']['gateway_order_id'] ?? '') === 'order_mock_rzp', 'Razorpay mocked order creation');
    $pay1 = PaymentRepository::latestPaymentForOrder($orderId);
    ok(($pay1['status'] ?? '') === 'pending', 'Razorpay payment row pending after success');

    PaymentHttp::$testHandler = static function (): array {
        return ['ok' => false, 'status' => 401, 'body' => '{"error":{"code":"BAD_REQUEST_ERROR","description":"Authentication failed"}}', 'json' => ['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']], 'error' => null];
    };
    $bad = PaymentService::initiateForOrder($orderId, $orderNo, 100.0, '9000000099');
    ok(empty($bad['ok']) && !empty($bad['reference_id']) && strpos((string) $bad['error'], 'Reference:') !== false, 'Razorpay auth failure returns reference id');
    $pay2 = $pdo->query('SELECT status FROM payments WHERE order_id = ' . $orderId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    ok($pay2 === 'failed', 'Failed initiation marks latest payment failed');

    PaymentHttp::$testHandler = static function (string $method, string $url): array {
        if ($method === 'POST') {
            return ['ok' => true, 'status' => 200, 'body' => '{"cf_order_id":"99"}', 'json' => ['cf_order_id' => '99'], 'error' => null];
        }

        return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => ''];
    };
    AppSettings::set('payment.active_gateway', 'cashfree');
    AppSettings::resetCache();
    $missingSession = PaymentService::initiateForOrder($orderId, $orderNo, 100.0, '9000000099');
    ok(empty($missingSession['ok']), 'Cashfree missing payment_session_id rejected');

    PaymentHttp::$testHandler = static function (string $method, string $url): array {
        if ($method === 'POST') {
            return [
                'ok' => true, 'status' => 200,
                'body' => '{"cf_order_id":"cf_1","payment_session_id":"sess_abc"}',
                'json' => ['cf_order_id' => 'cf_1', 'payment_session_id' => 'sess_abc'],
                'error' => null,
            ];
        }

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null, 'error' => null];
    };
    $cf = PaymentService::initiateForOrder($orderId, $orderNo, 50.0, '9000000099');
    ok(!empty($cf['ok']) && ($cf['checkout']['payment_session_id'] ?? '') === 'sess_abc', 'Cashfree mocked session creation');

    AppSettings::set('razorpay.enabled', '0');
    AppSettings::set('payment.active_gateway', 'razorpay');
    AppSettings::resetCache();
    ok(empty(PaymentGatewaySettingsService::saveFromPost(['active_gateway' => 'razorpay', 'razorpay_enabled' => ''])['ok']), 'Admin save blocks active razorpay when disabled');

    ok(RazorpayGateway::verifyPaymentSignature('order_x', 'pay_y', hash_hmac('sha256', 'order_x|pay_y', 'mock_secret_12345')), 'Razorpay signature verify success');
    ok(!RazorpayGateway::verifyPaymentSignature('order_x', 'pay_y', 'bad'), 'Razorpay signature verify failure');
} finally {
    PaymentHttp::$testHandler = $origHandler;
    AppSettings::set('payment.active_gateway', $origActive ?? 'simulate');
    AppSettings::set('payment.test_mode', $origTest ?? '1');
    AppSettings::set('razorpay.enabled', $origRzEn ?? '0');
    AppSettings::set('razorpay.key_id', $origRzKid ?? '');
    if ($origRzSec !== null && $origRzSec !== '') {
        AppSettings::set('razorpay.key_secret', $origRzSec, true);
    }
    AppSettings::set('cashfree.enabled', $origCfEn ?? '0');
    AppSettings::set('cashfree.app_id', $origCfId ?? '');
    if ($origCfSec !== null && $origCfSec !== '') {
        AppSettings::set('cashfree.secret_key', $origCfSec, true);
    }
    AppSettings::resetCache();
    $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
    $pdo->prepare('DELETE FROM customer_addresses WHERE customer_id = ?')->execute([$cid]);
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
}

echo $fail === 0 ? "\nPASS: payment initiation tests\n" : "\nFAILURES: {$fail}\n";
exit($fail === 0 ? 0 : 1);
