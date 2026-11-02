<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    $_SESSION['checkout_return'] = url_base() . '/payment.php?' . ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$orderNumber = isset($_GET['order']) && is_string($_GET['order']) ? trim($_GET['order']) : '';
$error = '';
$info = '';
$checkout = null;

if ($orderNumber === '' || !CommerceGateway::useDatabase()) {
    header('Location: ' . url_base() . '/checkout.php', true, 302);
    exit;
}

$customerId = CustomerAuth::id();
if ($customerId === null) {
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId);
if ($order === null) {
    layout_public_start(['pageTitle' => 'Payment — Apni Tokri', 'activeNav' => 'cart']);
    $emptyTitle = 'Order not found';
    $emptyMessage = 'This payment link is invalid.';
    $emptyActionHref = url_base() . '/my-orders.php';
    $emptyActionLabel = 'My orders';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

if ((string) $order['payment_status'] === 'paid') {
    header('Location: ' . url_base() . '/order.php?id=' . rawurlencode($orderNumber), true, 302);
    exit;
}

$latestPayment = PaymentRepository::latestPaymentForOrder((int) $order['id']);
$cashfreeActive = PaymentGatewayConfig::activeGateway() === 'cashfree' && !PaymentGatewayConfig::useSimulatedCheckout();
if ($cashfreeActive || PaymentService::hasOpenCashfreePayment($latestPayment)) {
    $sync = PaymentService::syncCashfreeOrderPayment($orderNumber, $customerId);
    if (!empty($sync['redirect'])) {
        header('Location: ' . $sync['redirect'], true, 302);
        exit;
    }
    if (($sync['state'] ?? '') === 'failed' && !empty($sync['redirect'])) {
        header('Location: ' . $sync['redirect'], true, 302);
        exit;
    }
    if (($sync['state'] ?? '') === 'reconciliation') {
        $error = (string) ($sync['message'] ?? 'Payment received; fulfilment pending.');
    } elseif (($sync['state'] ?? '') === 'error') {
        $error = (string) ($sync['message'] ?? 'Could not verify payment status.');
    } elseif (($sync['state'] ?? '') === 'pending') {
        $info = (string) ($sync['message'] ?? '');
    }
    $order = PaymentRepository::orderByNumberForCustomer($orderNumber, $customerId) ?? $order;
    if ((string) ($order['payment_status'] ?? '') === 'paid') {
        header('Location: ' . url_base() . '/order.php?id=' . rawurlencode($orderNumber), true, 302);
        exit;
    }
    $latestPayment = PaymentRepository::latestPaymentForOrder((int) $order['id']);
}

$checkout = $_SESSION['checkout_payment'][$orderNumber] ?? null;
if ($checkout === null || !is_array($checkout)) {
    if (PaymentService::hasOpenCashfreePayment($latestPayment) && is_array($latestPayment)) {
        $resume = PaymentService::resumeCashfreeCheckout($orderNumber, $latestPayment);
        if (!$resume['ok']) {
            $error = $resume['error'] ?? 'Could not resume Cashfree payment.';
        } else {
            $checkout = $resume['checkout'] ?? [];
            $_SESSION['checkout_payment'][$orderNumber] = $checkout;
        }
    } elseif ($latestPayment === null || (string) ($latestPayment['status'] ?? '') === 'failed') {
        $mobile = (string) (CustomerService::profile()['mobile'] ?? '');
        $init = PaymentService::initiateForOrder(
            (int) $order['id'],
            $orderNumber,
            (float) $order['grand_total'],
            $mobile
        );
        if (!$init['ok']) {
            $error = $init['error'] ?? 'Could not start payment.';
        } else {
            $checkout = $init['checkout'] ?? [];
            $_SESSION['checkout_payment'][$orderNumber] = $checkout;
        }
    }
}

$amount = (float) $order['grand_total'];
$mode = is_array($checkout) ? (string) ($checkout['mode'] ?? 'simulate') : 'simulate';
$cashfreeTestMode = PaymentGatewayConfig::isTestMode();

layout_public_start([
    'pageTitle' => 'Pay for order — Apni Tokri',
    'activeNav' => 'cart',
    'showBottomNav' => false,
]);
if ($mode === 'razorpay'): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php elseif ($mode === 'cashfree'): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<?php endif;
?>
<div class="at-container py-8 md:py-12 max-w-lg">
    <h1 class="at-h1">Complete payment</h1>
    <p class="at-muted mt-1">Order <span class="font-mono font-bold"><?= Security::escape($orderNumber) ?></span></p>

    <?php if ($error !== ''): ?>
        <div class="at-alert at-alert--error mt-4" role="alert"><?= Security::escape($error) ?></div>
    <?php endif; ?>
    <?php if ($info !== ''): ?>
        <div class="at-alert at-alert--info mt-4" role="status"><?= Security::escape($info) ?></div>
    <?php endif; ?>

    <div class="at-card mt-6">
        <div class="at-card__body grid gap-4">
            <dl class="at-summary-list">
                <div><dt>Amount due</dt><dd class="text-xl font-extrabold"><?= Security::escape(catalogue_format_price($amount)) ?></dd></div>
                <div><dt>Currency</dt><dd>INR</dd></div>
            </dl>

            <?php if ($mode === 'simulate' && is_array($checkout)): ?>
                <p class="text-sm at-muted">Simulated gateway (Stage 9). Payment is confirmed only after server verification — not from the browser alone.</p>
                <div class="flex flex-col sm:flex-row gap-3" id="at-pay-simulate" data-order="<?= Security::escape($orderNumber) ?>" data-token="<?= Security::escape((string) ($checkout['token'] ?? '')) ?>" data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>">
                    <button type="button" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" data-outcome="success"><?= at_icon('lock', 'w-5 h-5') ?> Pay (simulated success)</button>
                    <button type="button" class="at-btn at-btn--secondary at-focusable" data-outcome="fail">Simulate failure</button>
                </div>
            <?php elseif ($mode === 'razorpay' && is_array($checkout)): ?>
                <button type="button" id="at-razorpay-pay" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block"
                    data-key-id="<?= Security::escape((string) ($checkout['key_id'] ?? '')) ?>"
                    data-amount="<?= (int) ($checkout['amount_paise'] ?? 0) ?>"
                    data-order-id="<?= Security::escape((string) ($checkout['gateway_order_id'] ?? '')) ?>"
                    data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>"
                    data-order-number="<?= Security::escape($orderNumber) ?>"
                    data-customer-name="<?= Security::escape((string) (CustomerService::profile()['name'] ?? 'Customer')) ?>"
                    data-customer-mobile="<?= Security::escape((string) (CustomerService::profile()['mobile'] ?? '')) ?>">
                    <?= at_icon('wallet', 'w-5 h-5') ?> Pay with Razorpay
                </button>
            <?php elseif ($mode === 'cashfree' && is_array($checkout)): ?>
                <button type="button" id="at-cashfree-pay" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block"
                    data-session="<?= Security::escape((string) ($checkout['payment_session_id'] ?? '')) ?>"
                    data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>"
                    data-order-number="<?= Security::escape($orderNumber) ?>">
                    Pay with Cashfree
                </button>
                <button type="button" id="at-cashfree-refresh" class="at-btn at-btn--secondary at-focusable at-btn--block">
                    Refresh payment status
                </button>
            <?php endif; ?>

            <p class="text-xs at-muted"><?= at_icon('shield', 'w-4 h-4 inline') ?> Card details never touch our servers. Secrets stay on the server.</p>
        </div>
    </div>
</div>
<script>
window.AT_PAYMENT = {
    csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>,
    verifyUrl: <?= json_encode(url_base() . '/ajax/payment/verify.php', JSON_THROW_ON_ERROR) ?>,
    simulateUrl: <?= json_encode(url_base() . '/ajax/payment/simulate.php', JSON_THROW_ON_ERROR) ?>,
    orderSuccessUrl: <?= json_encode(url_base() . '/order.php?id=' . rawurlencode($orderNumber), JSON_THROW_ON_ERROR) ?>,
    paymentFailedUrl: <?= json_encode(url_base() . '/payment-failed.php?order=' . rawurlencode($orderNumber), JSON_THROW_ON_ERROR) ?>,
    cashfreeMode: <?= json_encode($cashfreeTestMode ? 'sandbox' : 'production', JSON_THROW_ON_ERROR) ?>
};
</script>
<?php layout_public_end(); ?>
