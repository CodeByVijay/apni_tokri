<?php

declare(strict_types=1);

/**
 * Gateway auto-launch (same client contract as payment.php).
 *
 * @var bool $retryPaymentAutoLaunch
 * @var array<string, mixed>|null $retryPaymentCheckout
 * @var string $displayId
 * @var bool $retryPaymentCashfreeSandbox
 */
if (empty($retryPaymentAutoLaunch) || !is_array($retryPaymentCheckout)) {
    return;
}
$mode = (string) ($retryPaymentCheckout['mode'] ?? '');
if ($mode === 'razorpay'): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<?php elseif ($mode === 'cashfree'): ?>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<?php endif; ?>
<div class="at-visually-hidden" aria-live="polite" id="at-pay-status">Opening secure checkout…</div>
<?php if ($mode === 'cashfree'): ?>
    <button type="button" id="at-cashfree-pay" class="hidden"
        data-session="<?= Security::escape((string) ($retryPaymentCheckout['payment_session_id'] ?? '')) ?>"
        data-idempotency="<?= Security::escape((string) ($retryPaymentCheckout['idempotency_key'] ?? '')) ?>"
        data-order-number="<?= Security::escape($displayId) ?>">Pay</button>
<?php elseif ($mode === 'razorpay'): ?>
    <button type="button" id="at-razorpay-pay" class="hidden"
        data-key-id="<?= Security::escape((string) ($retryPaymentCheckout['key_id'] ?? '')) ?>"
        data-amount="<?= (int) ($retryPaymentCheckout['amount_paise'] ?? 0) ?>"
        data-order-id="<?= Security::escape((string) ($retryPaymentCheckout['gateway_order_id'] ?? '')) ?>"
        data-idempotency="<?= Security::escape((string) ($retryPaymentCheckout['idempotency_key'] ?? '')) ?>"
        data-order-number="<?= Security::escape($displayId) ?>"
        data-customer-name="<?= Security::escape((string) (CustomerService::profile()['name'] ?? 'Customer')) ?>"
        data-customer-mobile="<?= Security::escape((string) (CustomerService::profile()['mobile'] ?? '')) ?>">Pay</button>
<?php endif; ?>
<script>
window.AT_PAYMENT = {
    csrf: <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>,
    verifyUrl: <?= json_encode(url_base() . '/ajax/payment/verify.php', JSON_THROW_ON_ERROR) ?>,
    simulateUrl: <?= json_encode(url_base() . '/ajax/payment/simulate.php', JSON_THROW_ON_ERROR) ?>,
    orderSuccessUrl: <?= json_encode(url_base() . '/order.php?id=' . rawurlencode($displayId), JSON_THROW_ON_ERROR) ?>,
    paymentFailedUrl: <?= json_encode(url_base() . '/payment-failed.php?order=' . rawurlencode($displayId), JSON_THROW_ON_ERROR) ?>,
    cashfreeMode: <?= json_encode($retryPaymentCashfreeSandbox ? 'sandbox' : 'production', JSON_THROW_ON_ERROR) ?>,
    autoLaunchGateway: true,
    gatewayMode: <?= json_encode($mode, JSON_THROW_ON_ERROR) ?>
};
</script>
