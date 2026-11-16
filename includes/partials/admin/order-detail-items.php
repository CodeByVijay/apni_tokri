<?php

declare(strict_types=1);

/** @var array<string, mixed> $order */
$pricing = is_array($order['pricing'] ?? null) ? $order['pricing'] : [];
$discount = (float) ($pricing['discount'] ?? $order['discount_total'] ?? 0);
$coupon = $order['coupon_code'] ?? ($pricing['coupon_code'] ?? null);
?>
<section class="at-card" aria-labelledby="at-admin-od-items-title">
    <div class="at-card__body">
        <h2 id="at-admin-od-items-title" class="at-admin-od-section-title">Order items &amp; pricing</h2>
        <?php if (($order['items'] ?? []) === []): ?>
            <p class="text-sm at-muted mt-2">No line items recorded.</p>
        <?php else: ?>
            <div class="at-admin-order-detail__items">
                <?php $orderItems = $order['items']; require APNI_TOKRI_ROOT . '/includes/partials/order-items.php'; ?>
            </div>
        <?php endif; ?>
        <dl class="at-order-totals mt-4">
            <div><dt>Subtotal</dt><dd><?= Security::escape(catalogue_format_price((float) ($pricing['subtotal'] ?? $order['subtotal'] ?? 0))) ?></dd></div>
            <?php if ($discount > 0): ?>
                <div>
                    <dt>Discounts<?php if ($coupon): ?> (coupon <?= Security::escape((string) $coupon) ?>)<?php endif; ?></dt>
                    <dd>−<?= Security::escape(catalogue_format_price($discount)) ?></dd>
                </div>
                <p class="text-xs at-muted col-span-full">Coupon and automatic offer discounts are stored as a single discount total on the order.</p>
            <?php endif; ?>
            <div><dt>Delivery fee</dt><dd><?= (float) ($pricing['delivery_fee'] ?? $order['delivery_fee'] ?? 0) === 0.0 ? 'Free' : Security::escape(catalogue_format_price((float) ($pricing['delivery_fee'] ?? $order['delivery_fee'] ?? 0))) ?></dd></div>
            <div><dt>Tax</dt><dd><span class="at-muted"><?= isset($pricing['tax']) && $pricing['tax'] !== null ? Security::escape(catalogue_format_price((float) $pricing['tax'])) : 'Not recorded' ?></span></dd></div>
            <div class="is-grand"><dt>Grand total</dt><dd><?= Security::escape(catalogue_format_price((float) ($pricing['grand_total'] ?? $order['grand_total'] ?? 0))) ?></dd></div>
            <?php if (!empty($pricing['amount_paid']) || !empty($pricing['amount_refunded'])): ?>
                <div><dt>Captured (payments)</dt><dd><?= Security::escape(catalogue_format_price((float) ($pricing['amount_paid'] ?? 0))) ?></dd></div>
                <?php if ((float) ($pricing['amount_refunded'] ?? 0) > 0): ?>
                    <div><dt>Refunded (payments)</dt><dd><?= Security::escape(catalogue_format_price((float) $pricing['amount_refunded'])) ?></dd></div>
                <?php endif; ?>
            <?php endif; ?>
        </dl>
    </div>
</section>
