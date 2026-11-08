<?php

declare(strict_types=1);

/**
 * Expiry cron Cashfree HTTP 404 → unpaid policy (mocked PG API only).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-unpaid-order-expiry-cashfree-404-policy.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okp(bool $c, string $m): void
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
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_expiry_404_test_secret', true);
AppSettings::resetCache();

$custId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
$variantId = (int) ($pdo->query(
    'SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 LIMIT 1'
)->fetchColumn() ?: 0);
if ($custId <= 0 || $variantId <= 0) {
    fwrite(STDERR, "Missing customer/variant fixtures.\n");
    exit(1);
}

$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$pastExp = date('Y-m-d H:i:s', time() - 3600);

$mkOrder = static function (PDO $pdo, int $cid, int $vid, string $num, string $payStatus = 'failed') use ($pastExp): int {
    $pdo->prepare(
        'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total,
         payment_status, placed_at, payment_expires_at, order_source)
         VALUES (?, ?, ?, 100, 0, 0, 100, ?, DATE_SUB(NOW(), INTERVAL 20 MINUTE), ?, ?)'
    )->execute([$num, $cid, OrderStatus::PENDING_PAYMENT, $payStatus, $pastExp, 'storefront']);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 1, 100, 100)'
    )->execute([$oid, $vid, 'Test', 'Var']);

    return $oid;
};

$addFailedCf = static function (PDO $pdo, int $oid, string $idem, ?string $meta = null): void {
    $metaJson = $meta ?? json_encode(['payment_environment' => 'production'], JSON_THROW_ON_ERROR);
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, metadata_json)
         VALUES (?, ?, 100, ?, ?, ?, ?)'
    )->execute([$oid, 'cashfree', 'INR', 'failed', $idem, $metaJson]);
};

$mock404 = static function (): array {
    return ['ok' => false, 'status' => 404, 'body' => '{"message":"not found"}', 'json' => ['message' => 'not found']];
};

$prevHandler = PaymentHttp::$testHandler;
try {
  // 1. Safe 404 → expiry cron state failed (unpaid path)
    PaymentHttp::$testHandler = static function (string $method, string $url): array {
        if ($method === 'GET' && str_contains($url, '/orders/') && !str_contains($url, '/payments')) {
            return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
        }

        return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null];
    };
    $n1 = 'AT-404OK-' . $tag;
    $o1 = $mkOrder($pdo, $custId, $variantId, $n1);
    $addFailedCf($pdo, $o1, 'f1-' . $tag);
    $sync1 = PaymentService::reconcileCashfreeOrderFromGateway($n1, null, 'expiry_cron', null);
    okp(($sync1['state'] ?? '') === 'failed' && !empty($sync1['ok']), 'safe 404 expiry_cron → state failed (unpaid)');
    $out1 = UnpaidOrderExpiryService::processOrder($o1);
    okp(($out1['action'] ?? '') === 'cancelled', 'safe 404 processOrder cancels');
    $audit1 = (int) $pdo->query(
        "SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'payment.cashfree_reconcile'
         AND entity_id = '" . (int) $o1 . "'
         AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.outcome')) = 'expiry_unpaid_verified_404'"
    )->fetchColumn();
    okp($audit1 >= 1, 'safe 404 writes expiry_unpaid_verified_404 audit');

  // 2. Same fixture admin_refresh still error (unchanged)
    $adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    $n2 = 'AT-404AD-' . $tag;
    $o2 = $mkOrder($pdo, $custId, $variantId, $n2);
    $addFailedCf($pdo, $o2, 'f2-' . $tag);
    $adm = PaymentService::reconcileCashfreeOrderFromGateway($n2, null, 'admin_refresh', $adminId > 0 ? $adminId : null);
    okp(($adm['state'] ?? '') === 'error', 'admin_refresh 404 still error state');

  // 3. Unsafe 404: pending cashfree row
    $n3 = 'AT-404PD-' . $tag;
    $o3 = $mkOrder($pdo, $custId, $variantId, $n3, 'pending');
    $pdo->prepare(
        'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, metadata_json)
         VALUES (?, ?, 100, ?, ?, ?, ?)'
    )->execute([$o3, 'cashfree', 'INR', 'pending', 'p3-' . $tag, '{"payment_environment":"production"}']);
    $sync3 = PaymentService::reconcileCashfreeOrderFromGateway($n3, null, 'expiry_cron', null);
    okp(($sync3['state'] ?? '') === 'error', 'pending payment 404 stays error');
    $out3 = UnpaidOrderExpiryService::processOrder($o3);
    okp(($out3['action'] ?? '') === 'defer', 'pending payment defers cancel');

  // 4. Captured payment blocks cancel
    $n4 = 'AT-404CP-' . $tag;
    $o4 = $mkOrder($pdo, $custId, $variantId, $n4);
    $addFailedCf($pdo, $o4, 'f4-' . $tag);
    $pdo->prepare(
        "INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, captured_at)
         VALUES (?, 'cashfree', 100, 'INR', 'captured', ?, NOW())"
    )->execute([$o4, 'cap4-' . $tag]);
    $out4 = UnpaidOrderExpiryService::processOrder($o4);
    okp(in_array((string) ($out4['action'] ?? ''), ['skip', 'paid', 'defer'], true), 'captured payment prevents timeout cancel');

  // 5. Open payment_completion_exception
    $pceReady = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_completion_exceptions'"
    )->fetchColumn() > 0;
    if ($pceReady) {
        $n5 = 'AT-404EX-' . $tag;
        $o5 = $mkOrder($pdo, $custId, $variantId, $n5);
        $addFailedCf($pdo, $o5, 'f5-' . $tag);
        $pdo->prepare(
            "INSERT INTO payment_completion_exceptions
             (order_id, gateway, gateway_payment_id, captured_amount_inr, failure_reason, failure_category, status)
             VALUES (?, 'cashfree', ?, 100.00, 'test open PCE', 'test', 'open')"
        )->execute([$o5, 'pce-test-' . $tag . '-5']);
        $out5 = UnpaidOrderExpiryService::processOrder($o5);
        okp(($out5['action'] ?? '') === 'defer', 'open PCE defers before cancel');
        $pdo->prepare("DELETE FROM payment_completion_exceptions WHERE order_id = ?")->execute([$o5]);
    } else {
        echo "SKIP: payment_completion_exceptions table missing\n";
    }

  // 6. Success webhook evidence
    $whReady = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_webhook_events'"
    )->fetchColumn() > 0;
    if ($whReady) {
        $n6 = 'AT-404WH-' . $tag;
        $o6 = $mkOrder($pdo, $custId, $variantId, $n6);
        $addFailedCf($pdo, $o6, 'f6-' . $tag);
        $payload = json_encode([
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => ['order_id' => $n6],
                'payment' => ['payment_status' => 'SUCCESS', 'cf_payment_id' => 'pay_test'],
            ],
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payment_webhook_events (gateway, event_id, payload_json, process_status)
             VALUES (?, ?, ?, ?)'
        )->execute(['cashfree', 'wh-' . $tag . '-6', $payload, 'processed']);
        $sync6 = PaymentService::reconcileCashfreeOrderFromGateway($n6, null, 'expiry_cron', null);
        okp(($sync6['state'] ?? '') === 'error', 'success webhook blocks 404-unpaid');
        $pdo->prepare("DELETE FROM payment_webhook_events WHERE event_id = ?")->execute(['wh-' . $tag . '-6']);
    } else {
        echo "SKIP: payment_webhook_events table missing\n";
    }

  // 7. API 401 / 5xx / timeout still error
    foreach ([401 => 'error', 503 => 'error', 0 => 'error'] as $http => $expectState) {
        PaymentHttp::$testHandler = static function () use ($http): array {
            return [
                'ok' => false,
                'status' => $http,
                'body' => '',
                'json' => $http === 401 ? ['message' => 'auth'] : null,
                'error' => $http === 0 ? 'timeout' : null,
            ];
        };
        $nX = 'AT-404H' . $http . '-' . $tag;
        $oX = $mkOrder($pdo, $custId, $variantId, $nX);
        $addFailedCf($pdo, $oX, 'fx' . $http . $tag);
        $syncX = PaymentService::reconcileCashfreeOrderFromGateway($nX, null, 'expiry_cron', null);
        okp(($syncX['state'] ?? '') === $expectState, 'HTTP ' . $http . ' expiry_cron stays error/defer path');
    }

    $pdo->exec(
        "DELETE FROM admin_audit_logs WHERE entity_type = 'orders' AND entity_id IN (
            SELECT CAST(id AS CHAR) FROM orders WHERE order_number LIKE 'AT-404%-$tag'
        )"
    );
    $nums = $pdo->query("SELECT id FROM orders WHERE order_number LIKE 'AT-404%-$tag'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($nums as $id) {
        $id = (int) $id;
        $pdo->prepare('DELETE FROM order_cancellation_events WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM order_status_history WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    }
} finally {
    PaymentHttp::$testHandler = $prevHandler;
    TestScriptSafety::restorePaymentGatewaySettings($settingsSnap);
    TestScriptSafety::restoreCashfreeSecret($origCfSecret);
}

echo $fail === 0 ? "All Cashfree 404 expiry policy checks passed.\n" : "Failures: $fail\n";
exit($fail > 0 ? 1 : 0);
