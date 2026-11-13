<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$items = DemoCart::items();
$totals = DemoCart::totals();
$loadCartJs = true;

layout_public_start([
    'pageTitle' => 'Cart — Veggie Tokri',
    'activeNav' => 'cart',
    'loadCartMobileCss' => true,
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
?>
<div class="at-pagehead">
    <div class="at-container pb-5">
        <nav class="at-breadcrumb" aria-label="Breadcrumb"><a href="<?= Security::escape(url_base()) ?>/index.php">Home</a><span class="sep">/</span><span aria-current="page"><?= Security::escape(__('nav_cart')) ?></span></nav>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h1 class="at-h1"><?= Security::escape(__('nav_cart')) ?><?php if ($items !== []): ?> <span class="at-muted text-lg font-sans font-semibold" data-cart-page-line-count>(<?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?>)</span><?php endif; ?></h1>
            <?php if ($items !== []): ?>
                <div class="at-steps-bar" aria-label="Checkout progress"><span class="is-current"><b>1</b>Cart</span><i class="line"></i><span><b>2</b>Details</span><i class="line"></i><span><b>3</b>Payment</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="at-container py-6 md:py-8 <?= $items !== [] ? 'at-cart-page--sticky' : '' ?>">
<div class="at-cart-undo hidden mb-4" data-cart-undo role="status" aria-live="polite">
    <span class="at-cart-undo__text" data-cart-undo-text></span>
    <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-cart-undo-btn>Undo</button>
</div>
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
    $deliveryBanner = CartDeliveryBanner::fromTotals($totals);
    $cartQtyBlocking = false;
    $cartMinOrderBlocking = !empty($totals['min_order_enabled']) && empty($totals['min_order_met']);
    $cartCheckoutBlocked = $cartQtyBlocking || $cartMinOrderBlocking;
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_24rem] gap-6 lg:gap-10 items-start">
        <div class="min-w-0">
            <div class="at-card at-card--flat mb-4" data-cart-delivery-banner>
                <div class="at-card__body !py-4">
                    <?php
                    $bannerIcon = $deliveryBanner['tone'] === 'success' ? 'check' : 'truck';
                    $bannerClass = $deliveryBanner['tone'] === 'success' ? 'text-[var(--at-success)]' : '';
                    ?>
                    <p class="text-sm font-semibold flex items-center gap-2 <?= Security::escape($bannerClass) ?>" data-cart-delivery-banner-text><?= at_icon($bannerIcon, 'w-5 h-5 ' . ($deliveryBanner['tone'] === 'success' ? '' : 'text-[var(--at-green)]')) ?><?= Security::escape((string) $deliveryBanner['message']) ?></p>
                    <?php if (!empty($deliveryBanner['cta_href'])): ?>
                    <a href="<?= Security::escape((string) $deliveryBanner['cta_href']) ?>" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-2" data-cart-delivery-banner-cta><?= Security::escape((string) ($deliveryBanner['cta_label'] ?? 'Select delivery address')) ?></a>
                    <?php else: ?>
                    <a href="<?= Security::escape(url_base()) ?>/checkout.php" class="at-btn at-btn--secondary at-btn--sm at-focusable mt-2 hidden" data-cart-delivery-banner-cta>Select delivery address</a>
                    <?php endif; ?>
                    <div class="at-progress mt-3 <?= $deliveryBanner['show_progress'] ? '' : 'hidden' ?>" data-cart-delivery-progress role="progressbar" aria-valuenow="<?= (int) $deliveryBanner['progress'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="at-progress__bar" data-cart-delivery-progress-bar style="width:<?= (int) $deliveryBanner['progress'] ?>%"></div></div>
                </div>
            </div>

            <ul class="grid gap-3">
                <?php foreach ($items as $item):
                    $lineCap = 0;
                    $stockAvail = 0;
                    $orderLimit = CartQuantityRules::DEFAULT_MAX_PER_ORDER;
                    $lineMin = CartQuantityRules::DEFAULT_MIN_PER_ORDER;
                    $vid = (int) ($item['variant_id'] ?? 0);
                    if ($vid > 0 && AppEnvironment::usePersistedStorefront()) {
                        $stockAvail = InventoryService::availableQty($vid);
                        $orderLimit = CartQuantityRules::maxPerOrderForVariantId($vid);
                        $lineMin = CartQuantityRules::minPerOrderForVariantId($vid);
                        $lineCap = CartQuantityRules::orderableQty($vid);
                    } else {
                        $p = app_catalogue()->product((string) ($item['product_slug'] ?? ''));
                        if ($p !== null) {
                            foreach ($p['variants'] ?? [] as $pv) {
                                if ((string) ($pv['id'] ?? '') === (string) ($item['variant_id'] ?? '')) {
                                    $stockAvail = max(0, (int) ($pv['stock'] ?? 0));
                                    $orderLimit = CartQuantityRules::maxPerOrderFromCatalogueVariant($pv);
                                    $lineMin = CartQuantityRules::minPerOrderFromCatalogueVariant($pv);
                                    $lineCap = CartQuantityRules::orderableQtyFromCatalogueVariant($pv);
                                    break;
                                }
                            }
                        }
                    }
                    $lineCap = max(0, $lineCap);
                    $lineCheck = CartQuantityRules::validateExistingCartLine(
                        (int) ($item['qty'] ?? 0),
                        $stockAvail,
                        $orderLimit,
                        $lineMin
                    );
                    if (!$lineCheck['ok']) {
                        $cartQtyBlocking = true;
                    }
                    $qtyLimitToast = CartQuantityRules::cartIncreaseBlockedMessage($stockAvail, $orderLimit, max(1, $lineCap));
                    $qtyDecreaseToast = CartQuantityRules::cartDecreaseBlockedMessage($lineMin);
                    $displayCap = max($lineMin, max(1, $lineCap));
                    $displayMin = min($lineMin, $displayCap);
                    $selQty = (int) ($item['qty'] ?? 1);
                    $rangeStart = $selQty < $displayMin ? max(1, $selQty) : $displayMin;
                    $packLabel = trim((string) ($item['label'] ?? ''));
                    $unitPrice = (float) ($item['unit_price'] ?? 0);
                    $regPrice = (float) ($item['regular_price'] ?? $unitPrice);
                    $isOnSale = !empty($item['on_sale']) && $regPrice > $unitPrice;
                    $qtySelectId = 'cart-qty-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $item['line_key']);
                    ?>
                    <li class="at-line-item" data-line-key="<?= Security::escape((string) $item['line_key']) ?>" data-product-slug="<?= Security::escape((string) ($item['product_slug'] ?? '')) ?>" data-variant-id="<?= Security::escape((string) ($item['variant_id'] ?? '')) ?>" data-item-name="<?= Security::escape((string) $item['name']) ?>" data-item-label="<?= Security::escape($packLabel) ?>" data-unit-price="<?= Security::escape((string) $unitPrice) ?>" data-qty-cap="<?= (int) $displayCap ?>" data-qty-min="<?= (int) $displayMin ?>" data-qty-limit-toast="<?= Security::escape($qtyLimitToast) ?>" data-qty-decrease-toast="<?= Security::escape($qtyDecreaseToast) ?>">
                        <a href="<?= Security::escape(url_base()) ?>/product.php?slug=<?= rawurlencode((string) $item['product_slug']) ?>" class="at-line-item__img at-focusable" tabindex="-1" aria-hidden="true">
                            <?php if (!empty($item['image'])): ?><img src="<?= Security::escape((string) $item['image']) ?>" alt="" loading="lazy"><?php endif; ?>
                        </a>
                        <div class="min-w-0">
                            <div class="at-line-item__head">
                                <div class="at-line-item__title min-w-0">
                                    <a href="<?= Security::escape(url_base()) ?>/product.php?slug=<?= rawurlencode((string) $item['product_slug']) ?>" class="font-bold hover:text-[var(--at-green)] at-focusable"><?= Security::escape((string) $item['name']) ?></a>
                                    <div class="at-line-item__meta">
                                        <?php if ($packLabel !== ''): ?>
                                            <span class="at-line-item__pack">Pack: <?= Security::escape($packLabel) ?></span>
                                        <?php endif; ?>
                                        <span class="at-line-item__unit">
                                            <?= Security::escape(catalogue_format_price($unitPrice)) ?><?= $packLabel !== '' ? ' / pack' : ' each' ?>
                                            <?php if ($isOnSale): ?><s class="at-muted ml-1"><?= Security::escape(catalogue_format_price($regPrice)) ?></s><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <p class="font-extrabold text-lg at-line-item__total tabular-nums" data-cart-line-total>
                                    <?= Security::escape(catalogue_format_price($unitPrice * $selQty)) ?>
                                </p>
                            </div>
                            <?php if (!$lineCheck['ok']): ?>
                                <p class="text-sm text-red-600 mt-2" role="alert"><?= Security::escape((string) ($lineCheck['message'] ?? '')) ?></p>
                            <?php endif; ?>
                            <div class="at-line-item__controls flex flex-wrap items-center justify-between gap-3 mt-3">
                                <div class="at-line-item__qty-group">
                                    <label for="<?= Security::escape($qtySelectId) ?>" class="at-line-item__qty-label">Qty</label>
                                    <div class="at-qty at-qty--sm">
                                        <button type="button" data-qty-step="-1" aria-label="Decrease quantity of <?= Security::escape((string) $item['name']) ?>">−</button>
                                        <select id="<?= Security::escape($qtySelectId) ?>" class="at-focusable js-cart-qty" min="<?= (int) $displayMin ?>" max="<?= (int) $displayCap ?>">
                                            <?php for ($q = $rangeStart; $q <= $displayCap; $q++): ?>
                                                <option value="<?= $q ?>" <?= $selQty === $q ? 'selected' : '' ?>><?= $q ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <button type="button" data-qty-step="1" aria-label="Increase quantity of <?= Security::escape((string) $item['name']) ?>">+</button>
                                    </div>
                                </div>
                                <button type="button" class="text-sm font-semibold text-[var(--at-danger)] hover:underline js-cart-remove at-focusable flex items-center gap-1" aria-label="Remove <?= Security::escape((string) $item['name']) ?>"><?= at_icon('trash', 'w-4 h-4') ?><span>Remove</span></button>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="mt-6" data-at-offers-wrap>
                <?php
                try {
                    $offers = CouponOfferService::forCurrentCart($totals);
                } catch (Throwable $e) {
                    error_log('cart offers: ' . get_class($e));
                    $offers = [];
                }
                require APNI_TOKRI_ROOT . '/includes/partials/cart-offers.php';
                ?>
            </div>

            <?php $cartCouponApplied = catalogue_cart_coupon_applied($totals); $cartAppliedCode = $cartCouponApplied ? trim((string) $totals['coupon_code']) : ''; ?>
            <form id="at-coupon-form" class="at-card at-card--flat mt-3"<?= $cartCouponApplied ? ' data-applied-code="' . Security::escape($cartAppliedCode) . '"' : '' ?>>
                <div class="at-card__body !py-4">
                    <?= Csrf::field() ?>
                    <div class="at-coupon-form__head">
                        <label class="at-label" for="coupon_code"><?= at_icon('tag', 'w-4 h-4 text-[var(--at-green)]') ?><span data-at-coupon-head-label><?= $cartCouponApplied ? Security::escape($cartAppliedCode) . ' applied' : 'Have a coupon?' ?></span></label>
                        <button type="button" id="at-coupon-remove" class="at-btn at-btn--ghost at-focusable<?= $cartCouponApplied ? '' : ' hidden' ?>" data-at-coupon-remove>Remove</button>
                    </div>
                    <details class="at-coupon-another" data-at-coupon-another<?= $cartCouponApplied ? '' : ' open' ?>>
                        <summary class="at-coupon-another__summary text-sm font-semibold text-[var(--at-green)] cursor-pointer at-focusable<?= $cartCouponApplied ? '' : ' hidden' ?>" data-at-coupon-another-summary>Have another coupon?</summary>
                        <div class="at-coupon-form__row mt-2">
                            <input id="coupon_code" name="coupon_code" class="at-input at-focusable flex-1 min-w-[10rem]" placeholder="Enter coupon code" value="<?= $cartCouponApplied ? '' : Security::escape((string) ($totals['coupon_code'] ?? '')) ?>" autocomplete="off">
                            <button type="submit" id="at-coupon-apply" class="at-btn at-btn--secondary at-focusable" data-at-coupon-apply>Apply</button>
                        </div>
                        <p id="at-coupon-feedback" class="text-sm mt-2 hidden" data-at-coupon-feedback role="alert"></p>
                    </details>
                </div>
            </form>
        </div>

        <aside class="at-summary">
            <?php require APNI_TOKRI_ROOT . '/includes/partials/cart-summary.php'; ?>
            <?php if ($cartQtyBlocking): ?>
                <p class="text-sm text-red-600 mb-3" role="alert">Fix quantity issues above before checkout.</p>
            <?php endif; ?>
            <a href="<?= $cartCheckoutBlocked ? '#' : Security::escape(url_base()) . '/checkout.php' ?>" class="at-btn at-btn--primary at-btn--lg at-btn--block at-focusable mt-4 <?= $cartCheckoutBlocked ? 'pointer-events-none opacity-50' : '' ?>" data-cart-checkout-cta <?= $cartCheckoutBlocked ? 'aria-disabled="true"' : '' ?>>Proceed to checkout <?= at_icon('chevron-right', 'w-5 h-5') ?></a>
            <a href="<?= Security::escape(url_base()) ?>/index.php" class="at-btn at-btn--ghost at-btn--block at-focusable mt-2">Continue shopping</a>
        </aside>
    </div>
    <?php
    $cartStickyQuoteReady = !empty($totals['delivery_quote_accurate'])
        && !empty($totals['delivery_serviceable'])
        && trim((string) ($totals['delivery_quote_message'] ?? '')) === '';
    ?>
    <div class="at-cart-sticky" data-at-cart-sticky>
        <div class="min-w-0">
            <p class="text-xs at-muted leading-tight" data-cart-sticky-label><?= $cartStickyQuoteReady ? 'Total payable' : 'Items payable' ?></p>
            <p class="font-extrabold text-lg leading-tight tabular-nums" data-cart-total><?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></p>
        </div>
        <a href="<?= $cartCheckoutBlocked ? '#' : Security::escape(url_base()) . '/checkout.php' ?>" class="at-btn at-btn--primary at-focusable at-cart-sticky__cta <?= $cartCheckoutBlocked ? 'pointer-events-none opacity-50' : '' ?>" data-cart-checkout-cta <?= $cartCheckoutBlocked ? 'aria-disabled="true"' : '' ?>>Checkout <?= at_icon('chevron-right', 'w-5 h-5') ?></a>
    </div>
    <template id="at-cart-empty-template">
        <?php
        $emptyTitle = 'Your cart is empty';
        $emptyMessage = 'Add a few fresh items and they will wait here. Your cart stays saved even if a payment fails.';
        $emptyIcon = 'cart';
        $emptyActionHref = url_base() . '/index.php';
        $emptyActionLabel = 'Continue shopping';
        require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
        ?>
    </template>
<?php endif; ?>
</div>
<?php layout_public_end(); ?>
