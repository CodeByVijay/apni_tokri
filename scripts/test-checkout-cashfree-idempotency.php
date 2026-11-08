<?php

declare(strict_types=1);

/**
 * Checkout idempotency + Cashfree session retry (mocked PG only).
 * CLI: php7.3 scripts/run-in-disposable-db.php scripts/test-checkout-cashfree-idempotency.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

$fail = 0;
function okI(bool $c, string $m): void
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
$pdo->prepare('DELETE FROM customers WHERE mobile = ?')->execute(['9000000077']);
$pdo->prepare('INSERT INTO customers (mobile, name, is_active) VALUES (?, ?, 1)')->execute(['9000000077', 'Idem Test']);
$cid = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, pincode, city, state, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$cid, 'Home', '1 Idem St', '452010', 'Indore', 'Madhya Pradesh', 'Idem Test', '9000000077']);
$addrId = (int) $pdo->lastInsertId();

AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::set('payment.test_mode', '1');
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CFTESTAPP12345678');
AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, 'cf_idem_test_secret', true);
AppSettings::resetCache();

$cfCreateFails = true;
$cfCreateCount = 0;

PaymentHttp::$testHandler = static function (string $method, string $url, ?array $body) use (&$cfCreateFails, &$cfCreateCount): array {
    if ($method === 'POST' && str_contains($url, '/orders') && !str_contains($url, '/refunds')) {
        $cfCreateCount++;
        if ($cfCreateFails) {
            return [
                'ok' => false,
                'status' => 401,
                'body' => '{"code":"request_failed","message":"Authentication Failed"}',
                'json' => ['code' => 'request_failed', 'message' => 'Authentication Failed'],
                'error' => null,
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"cf_order_id":"999001","payment_session_id":"session_idem_1"}',
            'json' => ['cf_order_id' => '999001', 'payment_session_id' => 'session_idem_1'],
            'error' => null,
        ];
    }
    if ($method === 'GET' && str_contains($url, '/orders/')) {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"order_status":"ACTIVE","cf_order_id":"999001","order_amount":75,"payment_session_id":"session_idem_1"}',
            'json' => [
                'order_status' => 'ACTIVE',
                'cf_order_id' => '999001',
                'order_amount' => 75,
                'payment_session_id' => 'session_idem_1',
            ],
            'error' => null,
        ];
    }

    return ['ok' => false, 'status' => 404, 'body' => '', 'json' => null, 'error' => null];
};

try {
    $_SESSION['customer_id'] = $cid;
    $lines = [['variant_id' => 1, 'qty' => 1, 'name' => 'Test', 'label' => '1', 'unit_price' => 75.0]];

    $spec = CheckoutIdempotencyService::buildSpec($cid, $addrId, 'morning', '2026-11-10', null, 75.0, null, $lines);
    $orderNumber = 'VT-IDEM-' . $tag;

    $created = Database::transaction(static function () use ($pdo, $spec, $lines, $cid, $addrId, $orderNumber): array {
        return CheckoutIdempotencyService::resolveOrCreateOrder(
            $pdo,
            $spec,
            $lines,
            static function () use ($pdo, $cid, $addrId, $orderNumber, $spec): array {
                $pdo->prepare(
                    "INSERT INTO orders (order_number, customer_id, customer_address_id, status, subtotal, grand_total,
                     delivery_fee, payment_status, delivery_slot, delivery_date, checkout_nonce, placed_at)
                     VALUES (?, ?, ?, 'pending_payment', 70, 75, 5, 'pending', 'morning', '2026-11-10', ?, NOW())"
                )->execute([$orderNumber, $cid, $addrId, (string) $spec['signature']]);
                $oid = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, quantity, unit_price, line_total)
                     VALUES (?, 1, ?, ?, 1, 70, 70)'
                )->execute([$oid, 'Item', '1']);

                return ['ok' => true, 'order_id' => $oid, 'order_number' => $orderNumber, 'reused' => false];
            }
        );
    });
    okI(!empty($created['ok']) && empty($created['reused']), 'First checkout creates one order');
    $orderId = (int) ($created['order_id'] ?? 0);

    $init1 = PaymentService::initiateForOrder($orderId, $orderNumber, 75.0, '9000000077');
    okI(empty($init1['ok']) && !empty($init1['reference_id']), 'Cashfree session failure returns customer reference');
    $countOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $cid)->fetchColumn();
    okI($countOrders === 1, 'Session failure does not create another order row');

    $reused = Database::transaction(static function () use ($pdo, $spec, $lines): array {
        return CheckoutIdempotencyService::resolveOrCreateOrder(
            $pdo,
            $spec,
            $lines,
            static function (): array {
                return ['ok' => false, 'message' => 'should not create'];
            }
        );
    });
    okI(!empty($reused['reused']) && (int) ($reused['order_id'] ?? 0) === $orderId, 'Retry reuses eligible unpaid order');
    okI($countOrders === (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $cid)->fetchColumn(), 'Reuse does not add order');

    $cfCreateFails = false;
    $init2 = PaymentService::initiateForOrder($orderId, $orderNumber, 75.0, '9000000077');
    okI(!empty($init2['ok']) && ($init2['checkout']['payment_session_id'] ?? '') === 'session_idem_1', 'Second init succeeds after mocked gateway recovery');
    okI($cfCreateCount >= 1, 'Cashfree create attempted at least once');

    $spec2 = CheckoutIdempotencyService::buildSpec($cid, $addrId, 'morning', '2026-11-10', null, 99.0, null, $lines);
    Database::transaction(static function () use ($pdo, $spec2, $lines, $orderNumber): void {
        CheckoutIdempotencyService::resolveOrCreateOrder(
            $pdo,
            $spec2,
            $lines,
            static function () use ($pdo, $spec2, $orderNumber): array {
                $pdo->prepare(
                    "INSERT INTO orders (order_number, customer_id, status, subtotal, grand_total, payment_status,
                     delivery_slot, delivery_date, checkout_nonce, placed_at)
                     VALUES (?, 1, 'pending_payment', 90, 99, 'pending', 'morning', '2026-11-10', ?, NOW())"
                )->execute([$orderNumber . '-NEW', (string) $spec2['signature']]);

                return ['ok' => true, 'order_id' => (int) $pdo->lastInsertId(), 'order_number' => $orderNumber . '-NEW'];
            }
        );
    });
    $reusedBad = Database::transaction(static function () use ($pdo, $spec2, $lines): array {
        return CheckoutIdempotencyService::resolveOrCreateOrder(
            $pdo,
            $spec2,
            $lines,
            static function (): array {
                return ['ok' => false, 'message' => 'skip'];
            }
        );
    });
    okI(empty($reusedBad['reused']) || (int) ($reusedBad['order_id'] ?? 0) !== $orderId, 'Changed amount does not reuse old order id');

    $sync = PaymentService::syncCashfreeOrderPayment($orderNumber, $cid);
    okI(($sync['state'] ?? '') !== 'confirmed' || empty($sync['redirect']), 'Webhook/sync path still authoritative (mock has no paid)');

    echo $fail === 0 ? "PASS: checkout Cashfree idempotency tests.\n" : "Failures: {$fail}\n";
} finally {
    PaymentHttp::$testHandler = $origHandler;
    AppSettings::set('payment.active_gateway', $origActive ?? 'simulate');
    AppSettings::set('payment.test_mode', $origTest ?? '1');
    AppSettings::set('cashfree.enabled', $origCfEn ?? '0');
    AppSettings::set('cashfree.app_id', $origCfId ?? '');
    if ($origCfSec !== null && $origCfSec !== '') {
        AppSettings::set(PaymentGatewaySettingsService::KEY_CASHFREE_SECRET_KEY, $origCfSec, true);
    }
    AppSettings::resetCache();
}

exit($fail > 0 ? 1 : 0);
