<?php

declare(strict_types=1);

/**
 * Prominent retry payment CTA (server POST → gateway auto-launch on order details).
 *
 * @var bool $showRetryPayment
 * @var float $retryPaymentAmount
 * @var string $retryPaymentError
 * @var string $retryPaymentInfo
 */
if (empty($showRetryPayment)) {
    return;
}
$amountLabel = catalogue_format_price($retryPaymentAmount);
?>
<section class="at-order-retry-pay at-card" aria-labelledby="at-order-retry-pay-title">
    <div class="at-card__body">
        <p class="at-order-retry-pay__eyebrow">Payment</p>
        <p id="at-order-retry-pay-title" class="at-order-retry-pay__lead">Complete your payment to confirm your order.</p>
        <?php if ($retryPaymentError !== ''): ?>
            <div class="at-alert at-alert--error mt-2 text-sm" role="alert"><?= Security::escape($retryPaymentError) ?></div>
        <?php endif; ?>
        <?php if ($retryPaymentInfo !== ''): ?>
            <div class="at-alert at-alert--info mt-2 text-sm" role="status"><?= Security::escape($retryPaymentInfo) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= Security::escape(url_base()) ?>/order-details.php?id=<?= rawurlencode((string) ($id ?? '')) ?>" class="at-order-retry-pay__form">
            <?= Csrf::field() ?>
            <input type="hidden" name="storefront_retry_payment" value="1">
            <button type="submit"
                    class="at-btn at-btn--primary at-focusable at-order-retry-pay__btn"
                    data-at-retry-payment
                    aria-label="<?= Security::escape('Retry payment, ' . $amountLabel) ?>">
                <span class="at-order-retry-pay__label">Retry Payment</span>
                <span class="at-order-retry-pay__amount" aria-hidden="true">· <?= Security::escape($amountLabel) ?></span>
            </button>
        </form>
    </div>
</section>
<script <?= Security::cspNonceAttr() ?>>
(function () {
    document.querySelectorAll('[data-at-retry-payment]').forEach(function (el) {
        var form = el.closest('form');
        if (!form) return;
        form.addEventListener('submit', function () {
            if (el.getAttribute('data-at-retry-busy') === '1') {
                return;
            }
            el.setAttribute('data-at-retry-busy', '1');
            el.setAttribute('aria-disabled', 'true');
            el.disabled = true;
            el.classList.add('is-busy');
            var label = el.querySelector('.at-order-retry-pay__label');
            if (label) {
                label.textContent = 'Opening payment…';
            }
        });
    });
})();
</script>
