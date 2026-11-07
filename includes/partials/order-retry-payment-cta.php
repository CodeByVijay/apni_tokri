<?php

declare(strict_types=1);

/**
 * Prominent retry payment CTA (links to existing payment.php flow).
 *
 * @var bool $showRetryPayment
 * @var string $retryPaymentUrl
 * @var float $retryPaymentAmount
 */
if (empty($showRetryPayment) || $retryPaymentUrl === '') {
    return;
}
$amountLabel = catalogue_format_price($retryPaymentAmount);
?>
<section class="at-order-retry-pay" aria-labelledby="at-order-retry-pay-title">
    <p id="at-order-retry-pay-title" class="at-order-retry-pay__lead">Complete your payment to confirm your order.</p>
    <a href="<?= Security::escape($retryPaymentUrl) ?>"
       class="at-btn at-btn--primary at-focusable at-order-retry-pay__btn"
       data-at-retry-payment
       aria-label="<?= Security::escape('Retry payment, ' . $amountLabel) ?>">
        <span class="at-order-retry-pay__label">Retry Payment</span>
        <span class="at-order-retry-pay__amount" aria-hidden="true">· <?= Security::escape($amountLabel) ?></span>
    </a>
</section>
<script>
(function () {
    document.querySelectorAll('[data-at-retry-payment]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (el.getAttribute('data-at-retry-busy') === '1') {
                e.preventDefault();
                return;
            }
            el.setAttribute('data-at-retry-busy', '1');
            el.setAttribute('aria-disabled', 'true');
            el.classList.add('is-busy');
            var label = el.querySelector('.at-order-retry-pay__label');
            if (label) {
                label.textContent = 'Opening payment…';
            }
        });
    });
})();
</script>
