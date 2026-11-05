<?php

declare(strict_types=1);

/**
 * Order/payment Demo vs Test vs Live badge detection (mocked; no real payments).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;

function okB(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function payRow(string $gateway, ?string $env, string $status = 'captured'): array
{
    $meta = $env !== null ? json_encode(['payment_environment' => $env]) : null;

    return ['gateway' => $gateway, 'status' => $status, 'metadata_json' => $meta];
}

okB(OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('cashfree', 'production')) === null, 'Cashfree live success → no badge');
okB(OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('razorpay', 'production')) === null, 'Razorpay live success → no badge');
okB(
    (OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('cashfree', 'sandbox'))['text'] ?? '') === 'Test payment',
    'Cashfree sandbox success → Test payment'
);
okB(
    (OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('razorpay', 'test'))['text'] ?? '') === 'Test payment',
    'Razorpay test success → Test payment'
);
okB(
    (OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('simulate', 'simulate'))['text'] ?? '') === 'Demo',
    'Simulate gateway → Demo'
);
okB(
    OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('cashfree', 'production', 'failed')) === null,
    'Cashfree live failed attempt → no badge'
);
okB(
    OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('cashfree', 'production', 'pending')) === null,
    'Cashfree live pending → no badge'
);
if (AppEnvironment::isProduction()) {
    okB(
        OrderPaymentEnvironmentBadge::badgeForPaymentRow(payRow('cashfree', null, 'failed')) === null,
        'Production fallback: Cashfree failed without metadata → no badge'
    );
} else {
    okB(true, 'Production-only fallback test skipped in non-production runtime');
}

okB(
    PaymentGatewayConfig::recordedPaymentEnvironment('simulate') === 'simulate',
    'recordedPaymentEnvironment simulate'
);

$orderPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/order.php');
okB(!str_contains($orderPhp, '(demo)'), 'order.php no longer hardcodes (demo)');
okB(str_contains($orderPhp, 'OrderPaymentEnvironmentBadge'), 'order.php uses environment badge helper');
okB(str_contains($orderPhp, '$loadedFromDatabase'), 'order.php tracks database vs session order source');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $tag = bin2hex(random_bytes(3));
    $custId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($custId > 0) {
        $num = 'AT-BADGE-' . strtoupper($tag);
        $pdo->prepare(
            'INSERT INTO orders (order_number, customer_id, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot, placed_at)
             VALUES (?, ?, ?, 10, 0, 0, 10, ?, ?, NOW())'
        )->execute([$num, $custId, 'confirmed', 'paid', 'morning']);
        $oid = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payments (order_id, gateway, amount, currency, status, idempotency_key, metadata_json, captured_at)
             VALUES (?, ?, 10, ?, ?, ?, ?, NOW())'
        )->execute([$oid, 'cashfree', 'INR', 'captured', 'pay_' . $num, json_encode(['payment_environment' => 'production'])]);
        okB(
            OrderPaymentEnvironmentBadge::forOrderConfirmation($num, true) === null,
            'Persisted live Cashfree order → no confirmation badge'
        );
        $pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$oid]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
    } else {
        okB(true, 'DB fixture skipped (no customer)');
    }
} else {
    okB(true, 'DB integration checks skipped');
}

echo $fail === 0 ? "All order payment environment badge tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
