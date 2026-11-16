<?php

declare(strict_types=1);

/**
 * Admin assisted orders — Phase 5 hardening (disposable DB, mocked HTTP + notifications).
 *
 * php scripts/run-in-disposable-db.php scripts/test-admin-assisted-order-phase5.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/stage14_phase1_fixture.php';
require_once __DIR__ . '/includes/admin_assisted_test_helpers.php';
require_once __DIR__ . '/lib/test_script_safety.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();

$fail = 0;
function p5Ok(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();
TestScriptSafety::assertSafeToMutateSettings();

$superId = stage14_p1_super_admin_id($pdo);
stage14_p1_session($superId);
Permission::clearCache();

$pdo->prepare('DELETE FROM coupon_usages WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN (?, ?)))')
    ->execute(['9800555501', '9800555502']);
$pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN (?, ?)))')
    ->execute(['9800555501', '9800555502']);
$pdo->prepare('DELETE FROM orders WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN (?, ?))')->execute(['9800555501', '9800555502']);
$pdo->prepare('DELETE FROM customer_addresses WHERE customer_id IN (SELECT id FROM customers WHERE mobile IN (?, ?))')
    ->execute(['9800555501', '9800555502']);
$pdo->prepare('DELETE FROM customers WHERE mobile IN (?, ?)')->execute(['9800555501', '9800555502']);

$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800555501', 'P5 Owner', 'p5owner@example.invalid']);
$custA = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (mobile, name, email, is_active) VALUES (?, ?, ?, 1)')
    ->execute(['9800555502', 'P5 Other', 'p5other@example.invalid']);
$custB = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default, receiver_name, receiver_phone)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
)->execute([$custA, 'Home', 'Line 1', 'Indore', 'MP', '452001', 'P5', '9800555501']);
$addrA = (int) $pdo->lastInsertId();

$varId = (int) ($pdo->query(
    "SELECT v.id FROM product_variants v INNER JOIN products p ON p.id = v.product_id WHERE p.slug = 'tmp-tomato' LIMIT 1"
)->fetchColumn() ?: 0);
$stockStart = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);

$postBase = [
    'customer_id' => $custA,
    'address_id' => $addrA,
    'lines' => json_encode([['variant_id' => $varId, 'quantity' => 2]], JSON_THROW_ON_ERROR),
    'apply_delivery_charges' => '1',
    'free_delivery' => '0',
    'delivery_slot' => 'auto',
    'delivery_date' => '',
];

$q1 = AdminAssistedOrderService::quote(AdminAssistedOrderService::collectPostInput($postBase));
p5Ok(!empty($q1['ok']) && !empty($q1['quote']['place_submission_token']), 'quote issues place_submission_token');

$missingToken = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($postBase));
p5Ok(empty($missingToken['ok']), 'place without submission token rejected');

$bundle1 = admin_assisted_quote_and_place($postBase);
$place1 = $bundle1['place'] ?? [];
p5Ok(!empty($place1['ok']), 'first place succeeds');
$orderId1 = (int) ($place1['order_id'] ?? 0);
$orderNum1 = (string) ($place1['order_number'] ?? '');

$retry = AdminAssistedOrderService::placeOrder(AdminAssistedOrderService::collectPostInput($postBase + [
    'place_submission_token' => (string) ($bundle1['quote']['place_submission_token'] ?? ''),
    'client_grand_total' => (string) ($bundle1['quote']['grand_total'] ?? '0'),
]));
p5Ok(!empty($retry['ok']) && !empty($retry['reused']), 'same submission token reuses order');

$bundle2 = admin_assisted_quote_and_place($postBase);
$place2 = $bundle2['place'] ?? [];
p5Ok(!empty($place2['ok']) && (int) ($place2['order_id'] ?? 0) !== $orderId1, 'fresh quote token creates second order');

$countPending = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE customer_id = {$custA} AND order_source = 'admin_assisted' AND status = 'pending_payment'"
)->fetchColumn();
p5Ok($countPending >= 2, 'two intentional identical carts as separate pending orders');

$stockAfterPlace = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);
p5Ok($stockAfterPlace === $stockStart, 'no stock decrement before payment capture');

$queueReady = NotificationQueueService::tableReady();
if ($queueReady) {
    $nq = (int) $pdo->query(
        "SELECT COUNT(*) FROM notification_queue WHERE idempotency_key = " . $pdo->quote('order.placed:order:' . $orderId1 . ':email')
    )->fetchColumn();
    p5Ok($nq >= 1, 'pending-payment email queued on place');
} else {
    p5Ok(true, 'pending-payment email queued on place (notification_queue N/A)');
}

$resend = AdminAssistedOrderService::resendPendingPaymentEmail($orderId1);
p5Ok(!empty($resend['ok']), 'resend queues notification');
$orderCountAfterResend = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id = ' . $custA)->fetchColumn();
p5Ok($orderCountAfterResend === $countPending, 'resend does not create another order');

$invPending = (int) $pdo->query(
    'SELECT COUNT(*) FROM invoices WHERE order_id = ' . $orderId1
)->fetchColumn();
p5Ok($invPending === 0, 'no invoice while pending payment');

AppSettings::set('payment.test_mode', '1');
AppSettings::set('payment.active_gateway', 'simulate');
AppSettings::resetCache();

$row = $pdo->query('SELECT * FROM orders WHERE id = ' . $orderId1)->fetch(PDO::FETCH_ASSOC);
$grand = (float) ($row['grand_total'] ?? 0);
$mobile = '9800555501';
$init1 = PaymentService::initiateForOrder($orderId1, $orderNum1, $grand, $mobile);
p5Ok(!empty($init1['ok']), 'simulate payment initiation');
$payCount = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId1)->fetchColumn();
$idempotency = (string) ($pdo->query('SELECT idempotency_key FROM payments WHERE order_id = ' . $orderId1 . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '');
$init2 = PaymentService::initiateForOrder($orderId1, $orderNum1, $grand, $mobile);
$payCount2 = (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE order_id = ' . $orderId1)->fetchColumn();
p5Ok(!empty($init2['ok']) && $payCount2 >= $payCount, 'payment retry initiation (simulate gateway)');
p5Ok(abs((float) ($pdo->query('SELECT amount FROM payments WHERE order_id = ' . $orderId1 . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 0) - $grand) < 0.02,
    'payment amount matches grand_total');

$cap1 = PaymentService::completePayment(
    $orderNum1,
    'simulate',
    'pay_sim_' . $orderNum1,
    'gw_sim_' . $orderNum1,
    $grand,
    'INR',
    $idempotency,
    'evt_sim_1'
);
p5Ok(!empty($cap1['ok']) && empty($cap1['idempotent']), 'simulate capture succeeds');
$stockOnce = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);
p5Ok($stockOnce === $stockStart - 2, 'stock decrements once after capture');

$capDup = PaymentService::completePayment(
    $orderNum1,
    'simulate',
    'pay_sim_' . $orderNum1,
    'gw_sim_' . $orderNum1,
    $grand,
    'INR',
    $idempotency,
    'evt_sim_1_dup'
);
p5Ok(!empty($capDup['ok']) && !empty($capDup['idempotent']), 'duplicate capture is idempotent');
$stockDup = (int) ($pdo->query('SELECT stock_qty FROM product_variants WHERE id = ' . $varId)->fetchColumn() ?: 0);
p5Ok($stockDup === $stockOnce, 'duplicate capture does not decrement stock again');

$pdo->prepare('DELETE FROM coupons WHERE code = ?')->execute(['P5ASSIST']);
$pdo->prepare(
    'INSERT INTO coupons (code, discount_type, discount_value, min_cart_value, is_active, starts_at, ends_at)
     VALUES (?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY))'
)->execute(['P5ASSIST', 'fixed', 5, 0]);
$couponPost = $postBase;
$couponPost['coupon_code'] = 'P5ASSIST';
$cBundle = admin_assisted_quote_and_place($couponPost);
$cOrderId = (int) (($cBundle['place']['order_id'] ?? 0));
$usageBefore = (int) $pdo->query(
    'SELECT COUNT(*) FROM coupon_usages WHERE order_id = ' . $cOrderId
)->fetchColumn();
p5Ok($usageBefore === 0, 'coupon usage not recorded before capture');

$cNum = (string) ($cBundle['place']['order_number'] ?? '');
$cGrand = (float) ($pdo->query('SELECT grand_total FROM orders WHERE id = ' . $cOrderId)->fetchColumn() ?: 0);
$cIdem = (string) ($pdo->query('SELECT idempotency_key FROM payments WHERE order_id = ' . $cOrderId . ' LIMIT 1')->fetchColumn() ?: '');
if ($cIdem === '') {
    PaymentService::initiateForOrder($cOrderId, $cNum, $cGrand, $mobile);
    $cIdem = (string) ($pdo->query('SELECT idempotency_key FROM payments WHERE order_id = ' . $cOrderId . ' LIMIT 1')->fetchColumn() ?: '');
}
PaymentService::completePayment($cNum, 'simulate', 'pay_c_' . $cNum, 'gw_c_' . $cNum, $cGrand, 'INR', $cIdem, 'evt_c_1');
$usageAfter = (int) $pdo->query('SELECT COUNT(*) FROM coupon_usages WHERE order_id = ' . $cOrderId)->fetchColumn();
p5Ok($usageAfter === 1, 'coupon usage recorded on capture');
PaymentService::completePayment($cNum, 'simulate', 'pay_c_' . $cNum, 'gw_c_' . $cNum, $cGrand, 'INR', $cIdem, 'evt_c_dup');
$usageDup = (int) $pdo->query('SELECT COUNT(*) FROM coupon_usages WHERE order_id = ' . $cOrderId)->fetchColumn();
p5Ok($usageDup === 1, 'duplicate capture does not double coupon usage');

$assistedCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE customer_id = {$custA} AND order_source = 'admin_assisted'"
)->fetchColumn();
p5Ok($assistedCount >= 1, 'admin assisted orders persisted for customer');
$listA = OrderRepository::listForCustomer($custA, 1, 0);
p5Ok(count($listA['rows'] ?? []) >= 1, 'customer My Orders list returns orders');

CustomerAuth::loginById($custA);
p5Ok(PaymentRepository::orderByNumberForCustomer($orderNum1, $custA) !== null, 'owner can load order for payment');
CustomerAuth::loginById($custB);
p5Ok(PaymentRepository::orderByNumberForCustomer($orderNum1, $custB) === null, 'other customer cannot access order');

$invAfterCapture = (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE order_id = ' . $orderId1)->fetchColumn();
p5Ok($invAfterCapture === 0, 'invoice not auto-created on capture alone (existing invoice workflow)');

$gatewayOrderId = (int) ($place2['order_id'] ?? 0);
$gatewayOrderNum = (string) ($place2['order_number'] ?? '');
$gatewayGrand = (float) ($pdo->query('SELECT grand_total FROM orders WHERE id = ' . $gatewayOrderId)->fetchColumn() ?: 0);

$prevHandler = PaymentHttp::$testHandler;
AppSettings::set('payment.test_mode', '1');
AppSettings::set('payment.active_gateway', 'razorpay');
AppSettings::set('razorpay.enabled', '1');
AppSettings::set('razorpay.key_id', 'rzp_test_phase5mock');
AppSettings::set('razorpay.key_secret', 'mock_secret_phase5', false);
AppSettings::resetCache();

PaymentHttp::$testHandler = static function (
    string $method,
    string $url,
    ?array $body,
    array $headers,
    ?string $basicUser,
    ?string $basicPass
): array {
    if (str_contains($url, 'razorpay.com') && $method === 'POST') {
        $amt = (int) ($body['amount'] ?? 0);

        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"id":"order_rzp_p5_ok","amount":' . $amt . '}',
            'json' => ['id' => 'order_rzp_p5_ok', 'amount' => $amt],
        ];
    }

    return ['ok' => false, 'status' => 500, 'body' => '', 'json' => null, 'error' => 'unmocked'];
};
$pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$gatewayOrderId]);
p5Ok(!PaymentGatewayConfig::useSimulatedCheckout(), 'razorpay configured for live initiation in test');
$rzInit = PaymentService::initiateForOrder($gatewayOrderId, $gatewayOrderNum, $gatewayGrand, $mobile);
$rzMode = (string) (($rzInit['checkout']['mode'] ?? '') ?: '');
p5Ok(!empty($rzInit['ok']) && $rzMode === 'razorpay', 'razorpay initiation success (mocked HTTP)');

PaymentHttp::$testHandler = static function (string $method, string $url): array {
    if (str_contains($url, 'api.razorpay.com')) {
        return ['ok' => false, 'status' => 502, 'body' => '', 'json' => null, 'error' => 'mock fail'];
    }

    return ['ok' => false, 'status' => 500, 'body' => '', 'json' => null];
};
$pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$gatewayOrderId]);
$rzFail = PaymentService::initiateForOrder($gatewayOrderId, $gatewayOrderNum, $gatewayGrand, $mobile);
p5Ok(empty($rzFail['ok']), 'razorpay initiation failure (mocked)');

AppSettings::set('payment.active_gateway', 'cashfree');
AppSettings::set('cashfree.enabled', '1');
AppSettings::set('cashfree.app_id', 'CF_PHASE5_APP');
AppSettings::set('cashfree.secret_key', 'cf_secret_mock', false);
AppSettings::resetCache();

PaymentHttp::$testHandler = static function (string $method, string $url, ?array $body, array $headers, ?string $user, ?string $pass): array {
    if (str_contains($url, 'cashfree.com') && $method === 'POST') {
        return [
            'ok' => true,
            'status' => 200,
            'body' => '{"cf_order_id":"cf_p5","payment_session_id":"sess_p5"}',
            'json' => ['cf_order_id' => 'cf_p5', 'payment_session_id' => 'sess_p5', 'order_id' => 'cf_p5'],
        ];
    }

    return ['ok' => false, 'status' => 500, 'body' => '', 'json' => null];
};
$pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$gatewayOrderId]);
p5Ok(!PaymentGatewayConfig::useSimulatedCheckout(), 'cashfree configured for live initiation in test');
$cfOk = PaymentService::initiateForOrder($gatewayOrderId, $gatewayOrderNum, $gatewayGrand, $mobile);
$cfMode = (string) (($cfOk['checkout']['mode'] ?? '') ?: '');
p5Ok(!empty($cfOk['ok']) && $cfMode === 'cashfree', 'cashfree initiation success (mocked)');

PaymentHttp::$testHandler = static function (): array {
    return ['ok' => false, 'status' => 503, 'body' => '', 'json' => null];
};
$pdo->prepare('DELETE FROM payments WHERE order_id = ?')->execute([$gatewayOrderId]);
$cfFail = PaymentService::initiateForOrder($gatewayOrderId, $gatewayOrderNum, $gatewayGrand, $mobile);
p5Ok(empty($cfFail['ok']), 'cashfree initiation failure (mocked)');

PaymentHttp::$testHandler = $prevHandler;

$whUnknown = PaymentService::handleRazorpayWebhook([
    'payload' => ['payment' => ['entity' => [
        'id' => 'pay_unknown',
        'order_id' => 'order_unknown',
        'amount' => 10000,
        'currency' => 'INR',
        'notes' => ['idempotency_key' => 'nope'],
    ]]],
], 'evt_unknown_p5');
p5Ok(empty($whUnknown['ok']), 'invalid razorpay webhook rejected (unknown payment)');

$store = PaymentService::beginWebhookEvent('razorpay', 'evt_p5_dup', '{}');
p5Ok(($store['action'] ?? '') === 'process', 'webhook event stored');
$dupStore = PaymentService::beginWebhookEvent('razorpay', 'evt_p5_dup', '{}');
p5Ok(($dupStore['action'] ?? '') === 'duplicate_success' || ($dupStore['action'] ?? '') === 'in_flight', 'duplicate webhook event deduped');

$lines = [['qty' => 1, 'unit_price' => 40.0, 'regular_price' => 40.0, 'variant_id' => $varId, 'product_id' => 1, 'category_id' => 1]];
$totalsDefault = PricingService::totalsFromLines($lines, null, '452001');
p5Ok(isset($totalsDefault['subtotal']) && (float) $totalsDefault['subtotal'] === 40.0, 'storefront pricing totalsFromLines unchanged without customer id');

stage14_p1_clear_session();
unset($_SESSION['customer_id']);
AppSettings::set('payment.test_mode', '0');
AppSettings::set('payment.active_gateway', 'simulate');
AppSettings::resetCache();

echo $fail === 0 ? "All admin-assisted Phase 5 checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
