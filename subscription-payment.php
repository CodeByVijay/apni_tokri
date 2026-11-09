<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

if (!CustomerService::isLoggedIn()) {
    $_SESSION['checkout_return'] = url_base() . '/subscription-payment.php?' . ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . url_base() . '/login.php', true, 302);
    exit;
}

$subNumber = isset($_GET['sub']) && is_string($_GET['sub']) ? trim($_GET['sub']) : '';
$error = '';
$checkout = null;
$base = url_base();

if ($subNumber === '' || !CommerceGateway::useDatabase()) {
    header('Location: ' . $base . '/subscriptions.php', true, 302);
    exit;
}

$customerId = CustomerAuth::id();
if ($customerId === null) {
    header('Location: ' . $base . '/login.php', true, 302);
    exit;
}

$sub = CustomerSubscriptionRepository::findByNumberForCustomer($subNumber, $customerId);
if ($sub === null) {
    layout_public_start(['pageTitle' => 'Subscription payment — Veggie Tokri', 'activeNav' => 'account']);
    $emptyTitle = 'Subscription not found';
    $emptyMessage = 'This payment link is invalid.';
    $emptyActionHref = $base . '/subscriptions.php';
    $emptyActionLabel = 'Subscription plans';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

if ((string) $sub['status'] === 'active') {
    header('Location: ' . $base . '/subscription-details.php?sub=' . rawurlencode($subNumber), true, 302);
    exit;
}

$snapshot = CustomerSubscriptionRepository::decodeSnapshot($sub);
$amount = (float) ($snapshot['price_paid'] ?? $sub['price_paid'] ?? 0);
$planName = (string) ($snapshot['name'] ?? 'Subscription');

if ((string) $sub['status'] !== 'pending_payment') {
    $error = 'This subscription is not awaiting payment.';
}

$checkout = $_SESSION['checkout_payment'][$subNumber] ?? null;
if ($error === '' && ($checkout === null || !is_array($checkout))) {
    $subId = (int) $sub['id'];
    $mobile = (string) (CustomerService::profile()['mobile'] ?? '');
    $init = SubscriptionPaymentService::initiateForSubscription($subId, $subNumber, $amount, $mobile);
    if (!$init['ok']) {
        $error = $init['error'] ?? 'Could not start payment.';
    } else {
        $checkout = array_merge($init['checkout'] ?? [], ['payment_context' => 'subscription']);
        $_SESSION['checkout_payment'][$subNumber] = $checkout;
    }
}

$mode = is_array($checkout) ? (string) ($checkout['mode'] ?? 'simulate') : 'simulate';
$failed = isset($_GET['failed']);

layout_public_start([
    'pageTitle' => 'Pay for subscription — Veggie Tokri',
    'activeNav' => 'account',
    'showBottomNav' => false,
]);
if ($mode === 'razorpay'): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php elseif ($mode === 'cashfree'): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<?php endif;
?>
<div class="at-container py-8 md:py-12 max-w-lg">
    <h1 class="at-h1">Complete subscription payment</h1>
    <p class="at-muted mt-1"><?= Security::escape($planName) ?> · <span class="font-mono font-bold"><?= Security::escape($subNumber) ?></span></p>

    <?php if ($failed): ?>
        <div class="at-alert at-alert--warning mt-4" role="alert">Payment did not complete. You can try again below.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="at-alert at-alert--error mt-4" role="alert"><?= Security::escape($error) ?></div>
    <?php endif; ?>

    <div class="at-card mt-6">
        <div class="at-card__body grid gap-4">
            <dl class="at-summary-list">
                <div><dt>Amount due</dt><dd class="text-xl font-extrabold"><?= Security::escape(catalogue_format_price($amount)) ?></dd></div>
                <div><dt>Deliveries</dt><dd><?= (int) ($snapshot['delivery_count'] ?? 0) ?></dd></div>
                <div><dt>Currency</dt><dd>INR</dd></div>
            </dl>

            <?php if ($error === '' && $mode === 'simulate' && is_array($checkout)): ?>
                <p class="text-sm at-muted">Simulated gateway. Subscription activates only after server verification.</p>
                <div class="flex flex-col sm:flex-row gap-3" id="at-pay-simulate"
                    data-subscription="<?= Security::escape($subNumber) ?>"
                    data-token="<?= Security::escape((string) ($checkout['token'] ?? '')) ?>"
                    data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>">
                    <button type="button" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1" data-outcome="success"><?= at_icon('lock', 'w-5 h-5') ?> Pay (simulated success)</button>
                    <button type="button" class="at-btn at-btn--secondary at-focusable" data-outcome="fail">Simulate failure</button>
                </div>
            <?php elseif ($error === '' && $mode === 'razorpay' && is_array($checkout)): ?>
                <button type="button" id="at-razorpay-pay" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block"
                    data-key-id="<?= Security::escape((string) ($checkout['key_id'] ?? '')) ?>"
                    data-amount="<?= (int) ($checkout['amount_paise'] ?? 0) ?>"
                    data-order-id="<?= Security::escape((string) ($checkout['gateway_order_id'] ?? '')) ?>"
                    data-idempotency="<?= Security::escape((string) ($checkout['idempotency_key'] ?? '')) ?>"
                    data-subscription-number="<?= Security::escape($subNumber) ?>"
                    data-customer-name="<?= Security::escape((string) (CustomerService::profile()['name'] ?? 'Customer')) ?>"
                    data-customer-mobile="<?= Security::escape((string) (CustomerService::profile()['mobile'] ?? '')) ?>">
                    <?= at_icon('wallet', 'w-5 h-5') ?> Pay with Razorpay
                </button>
            <?php elseif ($error === '' && $mode === 'cashfree' && is_array($checkout)): ?>
                <button type="button" id="at-cashfree-pay" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block"
                    data-session="<?= Security::escape((string) ($checkout['payment_session_id'] ?? '')) ?>">
                    Pay with Cashfree
                </button>
                <p class="text-xs at-muted">After Cashfree redirects back, open subscription details to confirm activation (webhook verification).</p>
            <?php endif; ?>

            <p class="text-xs at-muted"><?= at_icon('shield', 'w-4 h-4 inline') ?> Card details never touch our servers.</p>
        </div>
    </div>
</div>
<script>
window.AT_PAYMENT = {
    csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>,
    verifyUrl: <?= json_encode($base . '/ajax/payment/verify.php', JSON_THROW_ON_ERROR) ?>,
    simulateUrl: <?= json_encode($base . '/ajax/payment/simulate.php', JSON_THROW_ON_ERROR) ?>
};
</script>
<?php layout_public_end(); ?>
