<?php

declare(strict_types=1);

/**
 * Production-safety cases for unpaid order expiry (mocked gateways only).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-unpaid-order-expiry-safety.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function oks(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

TestScriptSafety::assertSafeToMutateSettings();

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$settingsBefore = TestScriptSafety::snapshotPaymentGatewaySettings();
$cfSecretBefore = TestScriptSafety::stashCashfreeSecret();
$rzSecretBefore = AppSettings::getSecret('razorpay.key_secret', null);

Install::ensureConnection();
$pdo = Database::pdo();

AppSettings::set('payment.active_gateway', 'razorpay');
AppSettings::set('payment.test_mode', '1');
AppSettings::set('razorpay.enabled', '1');
AppSettings::set('razorpay.key_id', 'rzp_test_expiry_safety');
AppSettings::set('razorpay.key_secret', 'rzp_test_secret_safety', true);
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_expiry_safety', true);
AppSettings::resetCache();

$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$custId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v WHERE v.is_active = 1 LIMIT 1'
)->fetchColumn() ?: 0);
if ($custId <= 0 || $variantId <= 0) {
    fwrite(STDERR, "Fixtures missing.\n");
    exit(1);
}

try {
    $mk = static function (string $num, string $expAt) use ($pdo, $custId): int {
        $pdo->prepare(
            'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
             payment_status, placed_at, payment_expires_at, order_source)
             VALUES (?, ?, ?, 100, 0, 0, 100, ?, DATE_SUB(NOW(), INTERVAL 30 MINUTE), ?, ?)'
        )->execute([$num, $custId, OrderStatus::PENDING_PAYMENT, 'pending', $expAt, 'storefront']);

        return (int) $pdo->lastInsertId();
    };

    $past = date('Y-m-d H:i:s', time() - 60);

    // Razorpay: captured at gateway before webhook
    $rzNum = 'AT-EXP-RZ-' . $tag;
    $rzOid = $mk($rzNum, $past);
    $rzGw = 'order_rzp_' . $tag;
    $idem = 'pay_' . $rzNum;
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key, metadata_json)
         VALUES (?, ?, ?, 100, ?, ?, ?, ?)'
    )->execute([
        $rzOid,
        'razorpay',
        $rzGw,
        'INR',
        'pending',
        $idem,
        json_encode(['payment_environment' => 'test'], JSON_THROW_ON_ERROR),
    ]);
  $pdo->prepare(
      'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
       VALUES (?, ?, ?, ?, 1, 100, 100)'
  )->execute([$rzOid, $variantId, 'T', 'V']);

    PaymentHttp::$testHandler = static function (string $method, string $url) use ($rzGw): array {
        if ($method === 'GET' && str_contains($url, '/orders/' . $rzGw . '/payments')) {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode(['items' => [['id' => 'pay_' . $rzGw, 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR']]]),
                'json' => ['items' => [['id' => 'pay_' . $rzGw, 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR']]],
            ];
        }

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
    };
    $outRz = UnpaidOrderExpiryService::processOrder($rzOid);
    oks(($outRz['action'] ?? '') === 'completed_payment', 'Razorpay late capture: expiry defers cancel and completes payment');
    oks(
        (string) $pdo->query('SELECT payment_status FROM orders WHERE id = ' . $rzOid)->fetchColumn() === 'paid',
        'Razorpay late capture: order paid'
    );

    // Cashfree: API timeout → defer (no cancel)
    $cfNum = 'AT-EXP-CF-' . $tag;
    $cfOid = $mk($cfNum, $past);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key, metadata_json)
         VALUES (?, ?, ?, 100, ?, ?, ?, ?)'
    )->execute([
        $cfOid,
        'cashfree',
        '12345',
        'INR',
        'pending',
        'pay_' . $cfNum,
        json_encode(['payment_environment' => 'production'], JSON_THROW_ON_ERROR),
    ]);
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 1, 100, 100)'
    )->execute([$cfOid, $variantId, 'T', 'V']);

    PaymentHttp::$testHandler = static function (): array {
        return ['ok' => false, 'status' => 0, 'body' => '', 'json' => null, 'error' => 'timeout'];
    };
    $outCf = UnpaidOrderExpiryService::processOrder($cfOid);
    oks(($outCf['action'] ?? '') === 'defer', 'Cashfree timeout: cancellation deferred');
    oks(
        (string) $pdo->query('SELECT status FROM orders WHERE id = ' . $cfOid)->fetchColumn() === OrderStatus::PENDING_PAYMENT,
        'Cashfree timeout: order still pending_payment'
    );

    // Retry near expiry: window still open
    $near = date('Y-m-d H:i:s', time() + 120);
    $nearNum = 'AT-EXP-NEAR-' . $tag;
    $nearOid = $mk($nearNum, $near);
    $nearRow = $pdo->query('SELECT * FROM orders WHERE id = ' . $nearOid)->fetch(PDO::FETCH_ASSOC);
    oks(UnpaidOrderExpiryService::isPaymentWindowOpen($nearRow), 'Payment retry allowed inside window');
    oks(PaymentService::customerCanRetryStorefrontPayment($nearRow), 'Retry CTA eligible before expiry');

    // Duplicate cron cancellation idempotent
    $dupNum = 'AT-EXP-DUP-' . $tag;
    $dupOid = $mk($dupNum, $past);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key)
         VALUES (?, ?, 100, ?, ?, ?)'
    )->execute([$dupOid, 'simulate', 'INR', 'pending', 'pay_' . $dupNum]);
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 1, 100, 100)'
    )->execute([$dupOid, $variantId, 'T', 'V']);
    PaymentHttp::$testHandler = null;
    UnpaidOrderExpiryService::processOrder($dupOid);
    $nEv = (int) $pdo->query('SELECT COUNT(*) FROM order_cancellation_events WHERE order_id = ' . $dupOid)->fetchColumn();
    UnpaidOrderExpiryService::processOrder($dupOid);
    $nEv2 = (int) $pdo->query('SELECT COUNT(*) FROM order_cancellation_events WHERE order_id = ' . $dupOid)->fetchColumn();
    oks($nEv2 === $nEv && $nEv === 1, 'Duplicate expiry cron does not duplicate cancellation events');

    // completePayment on cancelled order opens reconciliation (not silent paid)
    $canNum = 'AT-EXP-CANPAY-' . $tag;
    $canOid = $mk($canNum, $past);
    $pdo->prepare('UPDATE orders SET status = ?, payment_status = ? WHERE id = ?')
        ->execute([OrderStatus::CANCELLED, 'pending', $canOid]);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, gateway_order_id, amount, currency, status, idempotency_key)
         VALUES (?, ?, ?, 100, ?, ?, ?)'
    )->execute([$canOid, 'razorpay', 'order_can_' . $tag, 'INR', 'pending', 'pay_' . $canNum]);
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 1, 100, 100)'
    )->execute([$canOid, $variantId, 'T', 'V']);
    $comp = PaymentService::completePayment($canNum, 'razorpay', 'pay_can_' . $tag, 'order_can_' . $tag, 100, 'INR', 'pay_' . $canNum, 'test:cancelled');
    oks(empty($comp['ok']), 'completePayment rejects cancelled order (reconciliation)');
    oks(!empty($comp['reconciliation']), 'Late capture returns reconciliation flag');
    oks(
        (string) $pdo->query('SELECT payment_status FROM orders WHERE id = ' . $canOid)->fetchColumn() !== 'paid',
        'Cancelled order not flipped to paid after late webhook path'
    );
    $excCnt = PaymentCompletionExceptionService::tableReady()
        ? (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_completion_exceptions WHERE order_id = {$canOid} AND status = 'open'"
        )->fetchColumn()
        : 0;
    oks($excCnt === 1, 'payment_completion_exceptions row persisted for late capture on cancelled order');
    $paySt = (string) $pdo->query('SELECT status FROM payments WHERE order_id = ' . $canOid . ' LIMIT 1')->fetchColumn();
    oks($paySt === 'reconciliation_required', 'Payment row marked reconciliation_required');
    $refCnt = (int) $pdo->query('SELECT COUNT(*) FROM refund_requests WHERE order_id = ' . $canOid)->fetchColumn();
    oks($refCnt === 0, 'No automatic refund request on reconciliation exception');
} finally {
    PaymentHttp::$testHandler = null;
    TestScriptSafety::restorePaymentGatewaySettings($settingsBefore);
    TestScriptSafety::restoreCashfreeSecret($cfSecretBefore);
    if ($rzSecretBefore !== null) {
        AppSettings::set('razorpay.key_secret', $rzSecretBefore, true);
    }
    AppSettings::resetCache();
    oks(
        AppSettings::get('payment.test_mode', '') === ($settingsBefore['payment.test_mode'] ?? AppSettings::get('payment.test_mode', '')),
        'payment.test_mode restored after test'
    );
    foreach ([$rzOid ?? 0, $cfOid ?? 0, $nearOid ?? 0, $dupOid ?? 0, $canOid ?? 0] as $oid) {
        if ($oid > 0) {
            $pdo->prepare('DELETE FROM order_cancellation_events WHERE order_id = ?')->execute([$oid]);
            $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$oid]);
            $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$oid]);
            $pdo->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$oid]);
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
        }
    }
}

echo $fail === 0 ? "Unpaid expiry safety checks passed.\n" : "Failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
