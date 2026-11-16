<?php

declare(strict_types=1);

/** @var array<string, mixed>|null $overview */
/** @var bool $showRetryPayment */
$pw = is_array($overview ?? null) ? ($overview['payment_window'] ?? null) : null;
$minutes = is_array($overview ?? null) ? (int) ($overview['payment_expiry_minutes'] ?? UnpaidOrderExpiryService::DEFAULT_MINUTES) : UnpaidOrderExpiryService::DEFAULT_MINUTES;
if (!is_array($pw) || !UnpaidOrderExpiryService::columnReady()) {
    return;
}
$payStatus = (string) ($overview['payment_status'] ?? '');
$status = (string) ($overview['status'] ?? '');
if ($payStatus === 'paid' || $status === OrderStatus::CANCELLED) {
    return;
}
if ($payStatus !== 'pending' && $payStatus !== 'failed') {
    return;
}
$open = !empty($pw['open']);
$expired = !empty($pw['expired']);
$expiresAt = (string) ($pw['expires_at'] ?? '');
$remaining = isset($pw['remaining_seconds']) ? (int) $pw['remaining_seconds'] : null;
?>
<section class="at-card at-order-payment-window" aria-live="polite">
    <div class="at-card__body">
        <h2 class="at-h3">Payment deadline</h2>
        <p class="text-sm mt-2">Unpaid orders are automatically cancelled if payment is not completed within <?= (int) $minutes ?> minutes of placing the order.</p>
        <?php if ($open && $expiresAt !== '' && $remaining !== null): ?>
            <p class="text-sm mt-2">
                <strong>Complete payment by:</strong>
                <?= Security::escape(customer_format_datetime($expiresAt)) ?>
                <span class="at-order-payment-window__countdown at-muted" data-expires-at="<?= Security::escape($expiresAt) ?>" data-remaining="<?= (int) $remaining ?>">
                    (about <?= max(1, (int) ceil($remaining / 60)) ?> min left)
                </span>
            </p>
        <?php elseif ($expired && $status !== OrderStatus::CANCELLED): ?>
            <p class="text-sm mt-2 at-order-payment-window__expired"><strong>Payment window ended.</strong> This order will be cancelled automatically if payment is not confirmed.</p>
        <?php endif; ?>
        <?php if ($expired && !$showRetryPayment && $status !== OrderStatus::CANCELLED): ?>
            <p class="text-sm mt-2 at-muted">Retry payment is no longer available for this order.</p>
        <?php endif; ?>
    </div>
</section>
<script <?= Security::cspNonceAttr() ?>>
(function () {
    var el = document.querySelector('.at-order-payment-window__countdown');
    if (!el) return;
    var exp = el.getAttribute('data-expires-at');
    if (!exp) return;
    var end = new Date(exp.replace(' ', 'T')).getTime();
    if (!end) return;
    function tick() {
        var left = Math.max(0, Math.floor((end - Date.now()) / 1000));
        if (left <= 0) {
            el.textContent = '(payment window ended)';
            return;
        }
        var m = Math.floor(left / 60);
        var s = left % 60;
        el.textContent = '(' + m + 'm ' + String(s).padStart(2, '0') + 's left)';
        window.setTimeout(tick, 1000);
    }
    tick();
})();
</script>
