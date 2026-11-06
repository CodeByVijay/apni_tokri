<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$cartCount = DemoCart::itemCount();
$preserved = $cartCount > 0 || !empty($_SESSION['payment_failed_demo']);
unset($_SESSION['payment_failed_demo']);

layout_public_start([
    'pageTitle' => 'Payment failed — Veggie Tokri',
    'activeNav' => 'cart',
    'showBottomNav' => false,
]);
?>
<div class="at-container py-10 md:py-16 max-w-xl">
    <div class="text-center">
        <span class="inline-flex w-16 h-16 rounded-full bg-[var(--at-danger-bg)] text-[var(--at-danger)] items-center justify-center"><?= at_icon('info', 'w-8 h-8') ?></span>
        <h1 class="at-h1 mt-4">Payment didn’t go through</h1>
        <p class="mt-2 at-muted">Your cart and checkout details are saved — you can retry without adding items again.</p>
    </div>
    <div class="at-card mt-8">
        <div class="at-card__body grid gap-3">
            <p class="text-sm text-[var(--at-ink-2)]">If money was debited, it is reversed as per the payment gateway’s policy. Share your order reference with support if you need help.</p>
            <a href="<?= Security::escape(url_base()) ?>/checkout.php" class="at-btn at-btn--primary at-btn--lg at-focusable at-btn--block">Back to checkout</a>
            <?php
            $retryOrder = isset($_GET['order']) && is_string($_GET['order']) ? trim($_GET['order']) : '';
            if ($retryOrder !== '' && CommerceGateway::useDatabase() && CustomerService::isLoggedIn()):
                ?>
            <a href="<?= Security::escape(url_base()) ?>/payment.php?order=<?= rawurlencode($retryOrder) ?>" class="at-btn at-btn--secondary at-focusable at-btn--block">Retry payment for <?= Security::escape($retryOrder) ?></a>
            <?php endif; ?>
            <a href="<?= Security::escape(url_base()) ?>/cart.php" class="at-btn at-btn--secondary at-focusable at-btn--block">Review cart (<?= (int) $cartCount ?> items)</a>
            <a href="<?= Security::escape(url_base()) ?>/support.php" class="at-btn at-btn--ghost at-focusable at-btn--block">Contact support</a>
        </div>
    </div>
    <?php if (!$preserved): ?>
        <p class="text-xs at-muted mt-4 text-center">Note: the cart was empty in this session — add products to test the retry flow.</p>
    <?php endif; ?>
</div>
<?php layout_public_end(); ?>
