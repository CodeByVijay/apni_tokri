<?php

declare(strict_types=1);

/** @var array<string, float|int|string|null> $totals */
$totals = $totals ?? DemoCart::totals();
$compact = !empty($compact);
$couponApplied = catalogue_cart_coupon_applied($totals);
?>
<div class="at-cart-summary <?= $compact ? '' : 'at-card' ?>">
    <div class="<?= $compact ? '' : 'at-card__body' ?>">
        <?php if (!$compact): ?>
            <h2 class="at-card__title">Order summary</h2>
        <?php endif; ?>
        <dl class="mt-3 at-summary-list">
            <div><dt>Items total <span class="at-muted font-normal">(after product discounts)</span></dt><dd data-cart-subtotal><?= Security::escape(catalogue_format_price((float) $totals['subtotal'])) ?></dd></div>
            <div class="is-saving<?= (float) ($totals['product_savings'] ?? 0) > 0 ? '' : ' hidden' ?>" data-cart-product-savings-row>
                <dt>You saved on products</dt>
                <dd data-cart-product-savings><?= (float) ($totals['product_savings'] ?? 0) > 0 ? Security::escape(catalogue_format_price((float) $totals['product_savings'])) : '' ?></dd>
            </div>
            <?php if ((float) ($totals['offer_discount'] ?? 0) > 0): ?>
                <div class="is-saving" data-cart-offer-row><dt><?= Security::escape((string) ($totals['offer_label'] ?? 'Automatic offer')) ?></dt><dd data-cart-offer-amount>−<?= Security::escape(catalogue_format_price((float) $totals['offer_discount'])) ?></dd></div>
            <?php else: ?>
                <div class="is-saving hidden" data-cart-offer-row><dt data-cart-offer-label>Automatic offer</dt><dd data-cart-offer-amount></dd></div>
            <?php endif; ?>
            <div data-cart-offer-countdown-wrap class="<?= !empty($totals['offer_countdown_end_unix']) ? '' : 'hidden' ?>">
                <?php
                $countdownEndUnix = (int) ($totals['offer_countdown_end_unix'] ?? 0);
                require APNI_TOKRI_ROOT . '/includes/partials/offer-countdown.php';
                ?>
            </div>
            <div class="is-saving<?= $couponApplied ? '' : ' hidden' ?>" data-cart-coupon-row<?= $couponApplied ? '' : ' hidden' ?>>
                <dt>Coupon (<span data-cart-coupon-code><?= $couponApplied ? Security::escape(trim((string) $totals['coupon_code'])) : '' ?></span>)</dt>
                <dd data-cart-coupon-amount><?= $couponApplied ? '−' . Security::escape(catalogue_format_price((float) $totals['coupon_discount'])) : '' ?></dd>
            </div>
            <div><dt>Delivery fee <span class="at-muted font-normal text-xs" data-cart-delivery-context><?= !empty($totals['delivery_zone']) ? '· to ' . Security::escape((string) $totals['delivery_zone']) : '' ?></span></dt><dd data-cart-delivery><?php if (empty($totals['delivery_quote_accurate']) && !empty($totals['delivery_quote_message'])): ?><span class="at-muted">—</span><?php elseif ((float) $totals['delivery_fee'] === 0.0): ?>Free<?php else: ?><?= Security::escape(catalogue_format_price((float) $totals['delivery_fee'])) ?><?php endif; ?></dd></div>
            <div class="is-total"><dt>Total</dt><dd data-cart-total><?= Security::escape(catalogue_format_price((float) $totals['total'])) ?></dd></div>
        </dl>
        <?php if (!empty($totals['delivery_quote_message'])): ?>
            <p class="text-xs mt-3 px-3 py-2 rounded-lg bg-[var(--at-cream-2)] at-muted" data-cart-quote-message role="status"><?= Security::escape((string) $totals['delivery_quote_message']) ?></p>
        <?php else: ?>
            <p class="text-xs mt-3 px-3 py-2 rounded-lg bg-[var(--at-cream-2)] at-muted hidden" data-cart-quote-message role="status"></p>
        <?php endif; ?>
        <?php if (!empty($totals['min_order_enabled']) && empty($totals['min_order_met'])): ?>
            <div data-cart-min-order class="at-cart-min-order text-sm mt-3 px-3 py-3 rounded-lg bg-[var(--at-yellow-100)] border border-[var(--at-yellow-200,var(--at-line))] space-y-1.5" role="status">
                <p class="font-semibold text-[var(--at-yellow-ink)] leading-snug" data-cart-min-order-text>
                    <span data-cart-min-order-min>Is area mein minimum order <?= Security::escape(catalogue_format_price((float) ($totals['min_order_amount_inr'] ?? 0))) ?> hai.</span>
                </p>
                <p class="text-[var(--at-yellow-ink)] leading-snug" data-cart-min-order-merch>
                    Aapka discounted product value <?= Security::escape(catalogue_format_price((float) ($totals['min_order_merchandise_inr'] ?? 0))) ?> hai.
                </p>
                <p class="leading-snug" data-cart-min-order-remaining>
                    Checkout ke liye <span class="font-bold tabular-nums"><?= Security::escape(catalogue_format_price((float) ($totals['min_order_shortfall'] ?? 0))) ?></span> aur products add karein.
                </p>
                <p class="text-xs at-muted pt-0.5" data-cart-min-order-note>Delivery fee minimum order mein count nahi hoti. Your applied discounts are safe.</p>
            </div>
        <?php else: ?>
            <div data-cart-min-order class="at-cart-min-order text-sm mt-3 px-3 py-3 rounded-lg bg-[var(--at-yellow-100)] border border-[var(--at-yellow-200,var(--at-line))] space-y-1.5 hidden" role="status">
                <p class="font-semibold leading-snug" data-cart-min-order-text><span data-cart-min-order-min></span></p>
                <p class="leading-snug" data-cart-min-order-merch></p>
                <p class="leading-snug" data-cart-min-order-remaining></p>
                <p class="text-xs at-muted pt-0.5" data-cart-min-order-note>Delivery fee minimum order mein count nahi hoti. Your applied discounts are safe.</p>
            </div>
        <?php endif; ?>
        <?php if (empty($totals['delivery_quote_message']) && !empty($totals['delivery_quote_accurate']) && !empty($totals['delivery_serviceable']) && !empty($totals['free_delivery_eligible']) && (float) ($totals['amount_to_free_delivery'] ?? 0) > 0): ?>
            <p data-cart-free-delivery class="text-xs mt-3 px-3 py-2 rounded-lg bg-[var(--at-yellow-100)] text-[var(--at-yellow-ink)] font-semibold">
                Free delivery (optional saving): add <?= Security::escape(catalogue_format_price((float) $totals['amount_to_free_delivery'])) ?> more in eligible items.
            </p>
        <?php else: ?>
            <p data-cart-free-delivery class="text-xs mt-3 px-3 py-2 rounded-lg bg-[var(--at-yellow-100)] text-[var(--at-yellow-ink)] font-semibold hidden"></p>
        <?php endif; ?>
        <?php if (empty($totals['delivery_quote_message']) && empty($totals['delivery_serviceable']) && empty($totals['free_delivery_eligible'])): ?>
            <p class="text-xs mt-3 at-muted" data-cart-quote-hint>Check your pincode to see delivery fee and free-delivery progress.</p>
        <?php endif; ?>
        <p class="text-xs at-muted mt-3">All charges are shown here — nothing is added at payment. The server recalculates the final amount at checkout.</p>
    </div>
</div>
