<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$items = DemoCart::items();
$totals = DemoCart::totals();
$loadCartJs = true;

layout_public_start([
    'pageTitle' => 'Cart — Apni Tokri',
    'activeNav' => 'cart',
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
?>
<div class="at-pagehead">
    <div class="at-container pb-5">
        <nav class="at-breadcrumb" aria-label="Breadcrumb"><a href="<?= Security::escape(url_base()) ?>/index.php">Home</a><span class="sep">/</span><span aria-current="page"><?= Security::escape(__('nav_cart')) ?></span></nav>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h1 class="at-h1"><?= Security::escape(__('nav_cart')) ?><?php if ($items !== []): ?> <span class="at-muted text-lg font-sans font-semibold">(<?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>)</span><?php endif; ?></h1>
            <?php if ($items !== []): ?>
                <div class="at-steps-bar" aria-label="Checkout progress"><span class="is-current"><b>1</b>Cart</span><i class="line"></i><span><b>2</b>Details</span><i class="line"></i><span><b>3</b>Payment</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="at-container py-6 md:py-8">
<?php if ($items === []): ?>
    <?php
    $emptyTitle = 'Your cart is empty';
    $emptyMessage = 'Add a few fresh items and they will wait here. Your cart stays saved even if a payment fails.';
    $emptyIcon = 'cart';
    $emptyActionHref = url_base() . '/index.php';
    $emptyActionLabel = 'Continue shopping';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    ?>
<?php else:
    $freeThreshold = 499; // demo threshold; server decides at checkout
    $toFree = (float) ($totals['amount_to_free_delivery'] ?? 0);
    $freePct = $toFree > 0 ? max(4, min(100, (int) round((1 - $toFree / $freeThreshold) * 100))) : 100;
    ?>
    <div class="grid lg:grid-cols-[1fr_24rem] gap-6 lg:gap-10 items-start">
        <div>
            <div class="at-card at-card--flat mb-4">
                <div class="at-card__body !py-4">
                    <?php if ($toFree > 0): ?>
                        <p class="text-sm font-semibold flex items-center gap-2"><?= at_icon('truck', 'w-5 h-5 text-[var(--at-green)]') ?>Add <strong><?= Security::escape(catalogue_format_price($toFree)) ?></strong> more to get free delivery</p>
                    <?php else: ?>
                        <p class="text-sm font-semibold flex items-center gap-2 text-[var(--at-success)]"><?= at_icon('check', 'w-5 h-5') ?>You’ve unlocked free delivery</p>
                    <?php endif; ?>
                    <div class="at-progress mt-3" role="progressbar" aria-valuenow="<?= (int) $freePct ?>" aria-valuemin="0" aria-valuemax="100"><div class="at-progress__bar" style="width:<?= (int) $freePct ?>%"></div></div>
                    <p class="text-xs at-muted mt-2">Demo threshold ₹<?= (int) $freeThreshold ?>. The final delivery fee is confirmed at checkout.</p>
                </div>
            </div>

            <ul class="grid gap-3">
                <?php foreach ($items as $item): ?>
                    <li class="at-line-item" data-line-key="<?= Security::escape((string) $item['line_key']) ?>">
                        <a href="<?= Security::escape(url_base()) ?>/product.php?slug=<?= rawurlencode((string) $item['product_slug']) ?>" class="at-line-item__img at-focusable" tabindex="-1" aria-hidden="true">
                            <?php if (!empty($item['image'])): ?><img src="<?= Security::escape((string) $item['image']) ?>" alt="" loading="lazy"><?php endif; ?>
                        </a>
                        <div class="min-w-0">
                            <a href="<?= Security::escape(url_base()) ?>/product.php?slug=<?= rawurlencode((string) $item['product_slug']) ?>" class="font-bold hover:text-[var(--at-green)] at-focusable"><?= Security::escape((string) $item['name']) ?></a>
                            <p class="text-sm at-muted"><?= Security::escape((string) $item['label']) ?></p>
                            <p class="text-sm mt-1"><?= Security::escape(catalogue_format_price((float) $item['unit_price'])) ?> each</p>
                            <div class="flex flex-wrap items-center gap-3 mt-3">
                                <div class="at-qty at-qty--sm">
                                    <button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button>
                                    <select class="at-focusable js-cart-qty" aria-label="Quantity" min="1" max="10">
                                        <?php for ($q = 1; $q <= 10; $q++): ?>
                                            <option value="<?= $q ?>" <?= (int) $item['qty'] === $q ? 'selected' : '' ?>><?= $q ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <button type="button" data-qty-step="1" aria-label="Increase quantity">+</button>
                                </div>
                                <button type="button" class="text-sm font-semibold text-[var(--at-danger)] hover:underline js-cart-remove at-focusable flex items-center gap-1"><?= at_icon('trash', 'w-4 h-4') ?>Remove</button>
                            </div>
                        </div>
                        <p class="font-extrabold text-lg col-start-2 sm:col-start-auto sm:text-right">
                            <?= Security::escape(catalogue_format_price((float) $item['unit_price'] * (int) $item['qty'])) ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <form id="at-coupon-form" class="at-card at-card--flat mt-6">
                <div class="at-card__body !py-4">
                    <?= Csrf::field() ?>
                    <label class="at-label flex items-center gap-2" for="coupon_code"><?= at_icon('tag', 'w-4 h-4 text-[var(--at-green)]') ?>Have a coupon?</label>
                    <div class="flex flex-wrap gap-2">
                        <input id="coupon_code" name="coupon_code" class="at-input at-focusable flex-1 min-w-[10rem]" placeholder="Enter your coupon code" value="<?= Security::escape((string) ($totals['coupon_code'] ?? '')) ?>" autocomplete="off">
                        <button type="submit" id="at-coupon-apply" class="at-btn at-btn--secondary at-focusable" data-at-coupon-apply>Apply</button>
                        <?php if (catalogue_cart_coupon_applied($totals)): ?>
                            <button type="button" id="at-coupon-remove" class="at-btn at-btn--ghost at-focusable" data-at-coupon-remove>Remove</button>
                        <?php else: ?>
                            <button type="button" id="at-coupon-remove" class="at-btn at-btn--ghost at-focusable hidden" data-at-coupon-remove>Remove</button>
                        <?php endif; ?>
                    </div>
                    <p id="at-coupon-feedback" class="text-sm mt-2 hidden" data-at-coupon-feedback role="alert"></p>
                    <p class="at-field-hint mt-2">Eligibility is validated on the server. Discount appears in your order summary.</p>
                </div>
            </form>
        </div>

        <aside class="at-summary">
            <?php require APNI_TOKRI_ROOT . '/includes/partials/cart-summary.php'; ?>
            <a href="<?= Security::escape(url_base()) ?>/checkout.php" class="at-btn at-btn--primary at-btn--lg at-btn--block at-focusable mt-4">Proceed to checkout <?= at_icon('arrow-right', 'w-5 h-5') ?></a>
            <a href="<?= Security::escape(url_base()) ?>/index.php" class="at-btn at-btn--ghost at-btn--block at-focusable mt-2">Continue shopping</a>
        </aside>
    </div>
<?php endif; ?>
</div>
<?php layout_public_end(); ?>
