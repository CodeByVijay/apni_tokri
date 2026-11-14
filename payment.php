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
$fulfilmentReconciliation = null;

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
    layout_public_start(['pageTitle' => 'Payment — Veggie Tokri', 'activeNav' => 'cart']);
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

$orderId = (int) $order['id'];
$fulfilmentReconciliation = PaymentService::storefrontFulfilmentReconciliationContext($orderId);

if (isset($_GET['retry']) && (string) $_GET['retry'] === '1') {
    if ($fulfilmentReconciliation !== null) {
        header('Location: ' . url_base() . '/payment.php?order=' . rawurlencode($orderNumber), true, 302);
        exit;
    }
    unset($_SESSION['checkout_payment'][$orderNumber]);
    $_SESSION['checkout_payment_autolaunch'][$orderNumber] = true;
}

$latestPayment = PaymentRepository::latestPaymentForOrder($orderId);
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
        PaymentService::clearStorefrontCheckoutSession($orderNumber);
        $fulfilmentReconciliation = PaymentService::storefrontFulfilmentReconciliationContext($orderId)
            ?? [
                'customer_message' => 'Payment received — order confirmation pending. Our team is completing your order; you will not be charged again.',
                'idempotency_key' => is_array($latestPayment) ? (string) ($latestPayment['idempotency_key'] ?? '') : '',
                'gateway' => 'cashfree',
                'failure_reason' => (string) ($sync['message'] ?? ''),
                'failure_category' => '',
            ];
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
    $latestPayment = PaymentRepository::latestPaymentForOrder($orderId);
    $fulfilmentReconciliation = PaymentService::storefrontFulfilmentReconciliationContext($orderId) ?? $fulfilmentReconciliation;
}

if ($fulfilmentReconciliation !== null) {
    PaymentService::clearStorefrontCheckoutSession($orderNumber);
    $checkout = null;
} else {
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
                $orderId,
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
}

$amount = (float) $order['grand_total'];
$mode = is_array($checkout) ? (string) ($checkout['mode'] ?? 'simulate') : 'simulate';
$cashfreeTestMode = PaymentGatewayConfig::usesSandboxPayments();
$gatewayLabel = PaymentGatewayConfig::gatewayLabel($mode === 'simulate' ? 'simulate' : (string) PaymentGatewayConfig::activeGateway());
$orderEnvironmentBadge = null;
if (is_array($latestPayment) && $latestPayment !== []) {
    $orderEnvironmentBadge = OrderPaymentEnvironmentBadge::badgeForPaymentRow($latestPayment);
}
if ($orderEnvironmentBadge === null && $mode === 'simulate') {
    $orderEnvironmentBadge = OrderPaymentEnvironmentBadge::badgeForPaymentRow(['gateway' => 'simulate', 'metadata_json' => null]);
}
if ($orderEnvironmentBadge === null && $mode !== 'simulate') {
    $gw = $mode === 'razorpay' || $mode === 'cashfree' ? $mode : (string) PaymentGatewayConfig::activeGateway();
    $env = PaymentGatewayConfig::recordedPaymentEnvironment($gw);
    if ($env !== 'production') {
        $orderEnvironmentBadge = OrderPaymentEnvironmentBadge::badgeForPaymentRow([
            'gateway' => $gw,
            'metadata_json' => json_encode(['payment_environment' => $env]),
        ]);
    }
}
$payReference = is_array($checkout) ? (string) ($checkout['reference_id'] ?? '') : '';
$canRetry = $error !== '' && $fulfilmentReconciliation === null && (string) ($order['payment_status'] ?? '') !== 'paid';
$autoLaunchGatewayCheckout = false;
if ($fulfilmentReconciliation === null && $error === '' && is_array($checkout) && !empty($_SESSION['checkout_payment_autolaunch'][$orderNumber])) {
    $gatewayCheckoutReady = ($mode === 'cashfree' && (string) ($checkout['payment_session_id'] ?? '') !== '')
        || ($mode === 'razorpay' && (string) ($checkout['gateway_order_id'] ?? '') !== '');
    if ($gatewayCheckoutReady) {
        $autoLaunchGatewayCheckout = true;
        unset($_SESSION['checkout_payment_autolaunch'][$orderNumber]);
    }
}
$gatewayResumeManual = $fulfilmentReconciliation === null
    && in_array($mode, ['cashfree', 'razorpay'], true)
    && is_array($checkout)
    && !$autoLaunchGatewayCheckout
    && $error === '';
$showGatewayCheckout = $fulfilmentReconciliation === null && is_array($checkout);

layout_public_start([
    'pageTitle' => 'Pay for order — Veggie Tokri',
    'activeNav' => 'cart',
    'showBottomNav' => false,
]);
if ($showGatewayCheckout && $mode === 'razorpay'): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php elseif ($showGatewayCheckout && $mode === 'cashfree'): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<?php endif;
?>
<div class="at-container py-8 md:py-12 max-w-lg at-payment-page">
    <h1 class="at-h1">Complete payment</h1>
    <p class="at-muted mt-1">Order <span class="font-mono font-bold"><?= Security::escape($orderNumber) ?></span><?php require APNI_TOKRI_ROOT . '/includes/partials/order-environment-badge.php'; ?></p>

    <?php if ($fulfilmentReconciliation !== null): ?>
        <div class="at-alert at-alert--info mt-4" role="status">
            <p class="font-bold"><?= Security::escape((string) $fulfilmentReconciliation['customer_message']) ?></p>
            <p class="text-sm mt-2 at-muted">If this page opened after paying, do not pay again. Use <strong>Check payment status</strong> below or open your order from My orders.</p>
        </div>
    <?php elseif ($error !== ''): ?>
        <div class="at-alert at-alert--error mt-4" role="alert">
            <span><?= Security::escape($error) ?></span>
            <?php if ($canRetry): ?>
                <p class="mt-2 text-sm"><a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/payment.php?order=<?= rawurlencode($orderNumber) ?>&amp;retry=1">Try again</a></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($info !== ''): ?>
        <div class="at-alert at-alert--info mt-4" role="status"><?= Security::escape($info) ?></div>
    <?php endif; ?>

    <div class="at-card mt-6">
        <div class="at-card__body grid gap-4">
            <div class="at-checkout-pay__card">
                <div class="at-checkout-pay__row">
                    <span class="at-checkout-pay__label">Payment gateway</span>
                    <span class="at-checkout-pay__value"><?= Security::escape($gatewayLabel) ?></span>
                </div>
                <div class="at-checkout-pay__row">
                    <span class="at-checkout-pay__label">Amount payable</span>
                    <span class="at-checkout-pay__value at-checkout-pay__amount"><?= Security::escape(catalogue_format_price($amount)) ?></span>
                </div>
                <div class="at-checkout-pay__row"><span class="at-checkout-pay__label">Currency</span><span class="at-checkout-pay__value">INR</span></div>
                <?php if ($payReference !== ''): ?>
                    <p class="text-xs at-muted">Reference: <span class="font-mono"><?= Security::escape($payReference) ?></span></p>
                <?php endif; ?>
            </div>

            <?php if ($fulfilmentReconciliation !== null): ?>
                <p class="text-sm at-muted" id="at-pay-status" aria-live="polite">We are confirming your payment with our systems. This usually takes a short time.</p>
                <div id="at-payment-reconciliation-check" class="hidden"
                     data-order-number="<?= Security::escape($orderNumber) ?>"
                     data-idempotency="<?= Security::escape((string) ($fulfilmentReconciliation['idempotency_key'] ?? '')) ?>"
                     data-gateway="<?= Security::escape((string) ($fulfilmentReconciliation['gateway'] ?? 'cashfree')) ?>"></div>
                <button type="button" id="at-cashfree-refresh" class="at-btn at-btn--primary at-focusable at-btn--block">
                    Check payment status
                </button>
                <a href="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode($orderNumber) ?>" class="at-btn at-btn--secondary at-focusable at-btn--block text-center">
                    View order details
                </a>
                <a href="<?= Security::escape(url_base()) ?>/support.php?order=<?= rawurlencode($orderNumber) ?>" class="at-btn at-btn--ghost at-focusable at-btn--block text-center">
                    Contact support
                </a>
            <?php elseif ($mode === 'simulate' && is_array($checkout) && PaymentGatewayConfig::useSimulatedCheckout()): ?>
                <p class="text-sm at-muted">Simulated gateway. Payment is confirmed only after server verification — not from the browser alone.</p>
                <div class="flex flex-col sm:flex-row gap-3" id="at-pay-simulate" data-order="<?= Security::escape($orderNumber) ?>" data-token="<?= Security::escape((string) ($checkout['token'] ?? '')) ?>" data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>">
                    <button type="button" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" data-outcome="success"><?= at_icon('lock', 'w-5 h-5') ?> Pay (simulated success)</button>
                    <button type="button" class="at-btn at-btn--secondary at-focusable" data-outcome="fail">Simulate failure</button>
                </div>
            <?php elseif ($showGatewayCheckout && $mode === 'razorpay'): ?>
                <p class="text-sm at-muted" id="at-pay-status" aria-live="polite"><?= $autoLaunchGatewayCheckout ? 'Opening secure Razorpay checkout…' : ($gatewayResumeManual ? 'Payment not completed yet. Continue to Razorpay below.' : 'Preparing Razorpay…') ?></p>
                <button type="button" id="at-razorpay-pay" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block<?= $autoLaunchGatewayCheckout ? ' hidden' : '' ?>"
                    data-key-id="<?= Security::escape((string) ($checkout['key_id'] ?? '')) ?>"
                    data-amount="<?= (int) ($checkout['amount_paise'] ?? 0) ?>"
                    data-order-id="<?= Security::escape((string) ($checkout['gateway_order_id'] ?? '')) ?>"
                    data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>"
                    data-order-number="<?= Security::escape($orderNumber) ?>"
                    data-customer-name="<?= Security::escape((string) (CustomerService::profile()['name'] ?? 'Customer')) ?>"
                    data-customer-mobile="<?= Security::escape((string) (CustomerService::profile()['mobile'] ?? '')) ?>">
                    <?= $gatewayResumeManual ? 'Continue to Razorpay' : 'Pay with Razorpay' ?>
                </button>
            <?php elseif ($showGatewayCheckout && $mode === 'cashfree'): ?>
                <p class="text-sm at-muted" id="at-pay-status" aria-live="polite"><?= $autoLaunchGatewayCheckout ? 'Opening secure Cashfree checkout…' : ($gatewayResumeManual ? 'Payment not completed yet. Continue to Cashfree or refresh status below.' : 'Preparing Cashfree…') ?></p>
                <button type="button" id="at-cashfree-pay" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block<?= $autoLaunchGatewayCheckout ? ' hidden' : '' ?>"
                    data-session="<?= Security::escape((string) ($checkout['payment_session_id'] ?? '')) ?>"
                    data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>"
                    data-order-number="<?= Security::escape($orderNumber) ?>">
                    <?= $gatewayResumeManual ? 'Continue to Cashfree' : 'Pay with Cashfree' ?>
                </button>
                <button type="button" id="at-cashfree-refresh" class="at-btn at-btn--secondary at-focusable at-btn--block">
                    Refresh payment status
                </button>
                <a href="<?= Security::escape(url_base()) ?>/my-orders.php" class="at-btn at-btn--ghost at-focusable at-btn--block text-center">
                    <?= at_icon('receipt', 'w-4 h-4') ?> View My Orders
                </a>
            <?php endif; ?>

            <p class="text-xs at-muted"><?= at_icon('shield', 'w-4 h-4 inline') ?> Card details never touch our servers. Secrets stay on the server.</p>
        </div>
    </div>
</div>
<script <?= Security::cspNonceAttr() ?>>
window.AT_PAYMENT = {
    csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>,
    verifyUrl: <?= json_encode(url_base() . '/ajax/payment/verify.php', JSON_THROW_ON_ERROR) ?>,
    simulateUrl: <?= json_encode(url_base() . '/ajax/payment/simulate.php', JSON_THROW_ON_ERROR) ?>,
    orderSuccessUrl: <?= json_encode(url_base() . '/order.php?id=' . rawurlencode($orderNumber), JSON_THROW_ON_ERROR) ?>,
    paymentFailedUrl: <?= json_encode(url_base() . '/payment-failed.php?order=' . rawurlencode($orderNumber), JSON_THROW_ON_ERROR) ?>,
    cashfreeMode: <?= json_encode($cashfreeTestMode ? 'sandbox' : 'production', JSON_THROW_ON_ERROR) ?>,
    autoLaunchGateway: <?= $autoLaunchGatewayCheckout ? 'true' : 'false' ?>,
    gatewayMode: <?= json_encode($mode, JSON_THROW_ON_ERROR) ?>,
    fulfilmentReconciliation: <?= $fulfilmentReconciliation !== null ? 'true' : 'false' ?>
};
</script>
<?php layout_public_end(); ?>
