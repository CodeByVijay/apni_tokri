<?php

declare(strict_types=1);

/** @var array<string, mixed> $product */
$homeProductCard = !empty($homeProductCard);
$variant = catalogue_default_variant($product);
$variants = array_values($product['variants'] ?? []);
$multiVariant = count($variants) > 1;
$variantStates = [];
$pickerVariant = null;
$pickerVariantState = null;
$pickerPurchasableVariant = null;
$pickerPurchasableState = null;
$singleVariantCanQuickAdd = false;
if ($homeProductCard && $multiVariant) {
    foreach ($variants as $variantIndex => $candidateVariant) {
        $variantStates[$variantIndex] = catalogue_variant_order_state($candidateVariant);
        if ($pickerVariant === null && $variantStates[$variantIndex]['can_quick_add']) {
            $pickerVariant = $candidateVariant;
            $pickerVariantState = $variantStates[$variantIndex];
        }
        if ($pickerPurchasableVariant === null && $variantStates[$variantIndex]['purchasable']) {
            $pickerPurchasableVariant = $candidateVariant;
            $pickerPurchasableState = $variantStates[$variantIndex];
        }
    }
    if ($pickerVariant === null) {
        $pickerVariant = $pickerPurchasableVariant;
        $pickerVariantState = $pickerPurchasableState;
    }
} elseif (!$multiVariant && $variant !== null) {
    $variantStates[0] = catalogue_variant_order_state($variant);
    $singleVariantCanQuickAdd = $variantStates[0]['can_quick_add'];
}
$homeVariantPicker = $homeProductCard && $multiVariant && $pickerVariant !== null;
$displayVariant = $homeVariantPicker ? $pickerVariant : $variant;
$displayVariantState = null;
if ($displayVariant !== null) {
    foreach ($variantStates as $variantIndex => $variantState) {
        if ((string) (($variants[$variantIndex]['id'] ?? '')) === (string) ($displayVariant['id'] ?? '')) {
            $displayVariantState = $variantState;
            break;
        }
    }
}
$prices = $displayVariant ? catalogue_variant_prices($displayVariant) : ['display' => 0, 'regular' => 0, 'on_sale' => false];
$displayStock = $displayVariantState !== null
    ? (int) $displayVariantState['stock']
    : max(0, (int) ($displayVariant['stock'] ?? 0));
$displayLowStock = (int) ($displayVariant['low_stock'] ?? 5);
$stockLabel = null;
if ($displayVariant === null || $displayStock <= 0) {
    $stockLabel = 'Out of stock';
} elseif ($displayStock <= $displayLowStock) {
    $stockLabel = 'Low stock';
}
$inStock = catalogue_is_in_stock($product);
if ($variantStates !== []) {
    $inStock = false;
    foreach ($variantStates as $variantState) {
        if ($variantState['stock'] > 0) {
            $inStock = true;
            break;
        }
    }
}
$rating = demo_catalogue()->ratingSummary((string) $product['slug']);
$image = $product['images'][0] ?? asset_url('assets/images/logo-placeholder.svg');
$productUrl = catalogue_product_url($product);
// Honest discount: derived only from the real regular vs sale price.
$discountPct = $prices['on_sale'] && $prices['regular'] > 0
    ? (int) round((1 - $prices['display'] / $prices['regular']) * 100)
    : 0;
?>
<article class="at-product-card<?= $homeProductCard ? ' at-product-card--home' : '' ?>">
    <a href="<?= Security::escape($productUrl) ?>" class="at-product-card__media at-focusable" tabindex="-1" aria-hidden="true">
        <img src="<?= Security::escape($image) ?>" alt="" loading="lazy" width="400" height="400">
        <span class="at-product-card__flags">
            <span class="flex flex-col items-start gap-1">
                <?php if ($homeVariantPicker): ?>
                    <span class="at-badge at-badge--sale" data-card-discount<?= $discountPct === 0 ? ' hidden' : '' ?>><?= (int) $discountPct ?>% off</span>
                <?php elseif ($discountPct > 0): ?>
                    <span class="at-badge at-badge--sale"><?= (int) $discountPct ?>% off</span>
                <?php endif; ?>
                <?php if (in_array('new', $product['flags'] ?? [], true)): ?>
                    <span class="at-badge at-badge--green">New</span>
                <?php endif; ?>
            </span>
            <?php if (!$inStock): ?>
                <span class="at-badge at-badge--dark">Sold out</span>
            <?php endif; ?>
        </span>
    </a>
    <div class="at-product-card__body">
        <div class="at-product-card__main">
            <a href="<?= Security::escape($productUrl) ?>" class="at-product-card__title at-focusable"><?= Security::escape((string) $product['name']) ?></a>
            <p class="at-product-card__unit">
                <?= $displayVariant ? Security::escape((string) $displayVariant['label']) : '&nbsp;' ?><?= $multiVariant ? ' · +' . (count($product['variants']) - 1) . ' more' : '' ?>
            </p>
            <div class="at-product-card__extras">
                <?php if ($rating !== null): ?>
                    <p class="at-product-card__rating flex items-center gap-1 text-xs font-semibold">
                        <span class="text-[var(--at-yellow-dark)]" aria-hidden="true">★</span><?= Security::escape((string) $rating['average']) ?>
                        <span class="font-normal at-muted">(<?= (int) $rating['count'] ?>)</span>
                    </p>
                <?php endif; ?>
                <?php if ($homeVariantPicker): ?>
                    <span class="at-stockline<?= $stockLabel === 'Out of stock' ? ' at-stockline--out' : '' ?>" data-card-stock<?= $stockLabel === null ? ' hidden' : '' ?>><?php
                        if ($stockLabel === 'Low stock' && $displayVariant) {
                            echo 'Only ' . (int) $displayStock . ' left';
                        } elseif ($stockLabel === 'Out of stock') {
                            echo 'Out of stock';
                        }
                    ?></span>
                <?php elseif ($stockLabel === 'Low stock' && $displayVariant): ?>
                    <span class="at-stockline">Only <?= (int) $displayStock ?> left</span>
                <?php elseif (!$inStock): ?>
                    <span class="at-stockline at-stockline--out">Out of stock</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="at-product-card__foot">
            <div class="at-price">
                <span class="at-price__now"><?= Security::escape(catalogue_format_price($prices['display'])) ?></span>
                <?php if ($homeVariantPicker): ?>
                    <span class="at-price__was"<?= $prices['on_sale'] ? '' : ' hidden' ?>><?= Security::escape(catalogue_format_price($prices['regular'])) ?></span>
                <?php elseif ($prices['on_sale']): ?>
                    <span class="at-price__was"><?= Security::escape(catalogue_format_price($prices['regular'])) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($homeVariantPicker): ?>
                <a href="<?= Security::escape($productUrl) ?>" class="at-product-card__add at-product-card__options at-focusable">Options</a>
            <?php elseif ($singleVariantCanQuickAdd): ?>
                <button type="button" class="at-product-card__add at-focusable js-demo-add-cart"
                    data-product="<?= Security::escape((string) $product['slug']) ?>"
                    data-variant-id="<?= Security::escape((string) ($displayVariant['id'] ?? '')) ?>"
                    aria-label="Add <?= Security::escape((string) $product['name']) ?> to cart">
                    <?= at_icon('plus', 'w-4 h-4') ?>Add
                </button>
            <?php else: ?>
                <a href="<?= Security::escape($productUrl) ?>" class="at-product-card__add at-focusable">
                    <?= $multiVariant ? 'Options' : 'View' ?>
                </a>
            <?php endif; ?>
        </div>
        <?php if ($homeVariantPicker):
            $pickerCanQuickAdd = $pickerVariantState !== null && !empty($pickerVariantState['can_quick_add']);
            $pickerPdpUrl = $productUrl . '&variant_id=' . rawurlencode((string) ($pickerVariant['id'] ?? ''));
            ?>
        <div class="at-product-card__variant" data-card-variant-picker hidden>
            <select class="at-select at-product-card__variant-select" data-card-variant-select aria-label="Choose <?= Security::escape((string) $product['name']) ?> pack">
                <?php foreach ($variants as $variantIndex => $variantOption):
                    $optionState = $variantStates[$variantIndex] ?? catalogue_variant_order_state($variantOption);
                    $optionPrices = $optionState['prices'];
                    $optionStock = (int) $optionState['stock'];
                    $optionMin = (int) $optionState['order_min'];
                    $optionPdpUrl = $productUrl . '&variant_id=' . rawurlencode((string) ($variantOption['id'] ?? ''));
                    ?>
                    <option value="<?= Security::escape((string) ($variantOption['id'] ?? '')) ?>"
                        data-label="<?= Security::escape((string) ($variantOption['label'] ?? '')) ?>"
                        data-price="<?= Security::escape(catalogue_format_price($optionPrices['display'])) ?>"
                        data-price-value="<?= Security::escape((string) (float) $optionPrices['display']) ?>"
                        data-regular="<?= Security::escape(catalogue_format_price($optionPrices['regular'])) ?>"
                        data-sale="<?= $optionPrices['on_sale'] ? '1' : '0' ?>"
                        data-discount="<?= $optionPrices['on_sale'] && $optionPrices['regular'] > 0 ? (int) round((1 - $optionPrices['display'] / $optionPrices['regular']) * 100) : 0 ?>"
                        data-stock="<?= (int) $optionStock ?>"
                        data-low-stock="<?= (int) ($variantOption['low_stock'] ?? 5) ?>"
                        data-orderable="<?= $optionState['can_quick_add'] ? '1' : '0' ?>"
                        data-purchasable="<?= $optionState['purchasable'] ? '1' : '0' ?>"
                        data-order-cap="<?= (int) $optionState['order_cap'] ?>"
                        data-order-min="<?= $optionMin ?>"
                        <?= $optionState['purchasable'] && $optionMin > 1 ? 'data-pdp-url="' . Security::escape($optionPdpUrl) . '"' : '' ?>
                        <?= !$optionState['purchasable'] ? 'disabled' : '' ?>
                        <?= (string) ($variantOption['id'] ?? '') === (string) ($pickerVariant['id'] ?? '') ? 'selected' : '' ?>>
                        <?= Security::escape((string) ($variantOption['label'] ?? 'Pack')) ?> · <?= Security::escape(catalogue_format_price($optionPrices['display'])) ?><?= $optionMin > 1 ? ' · Min ' . $optionMin : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="at-product-card__add at-focusable js-demo-add-cart"
                data-product="<?= Security::escape((string) $product['slug']) ?>"
                data-variant-id="<?= $pickerCanQuickAdd ? Security::escape((string) ($pickerVariant['id'] ?? '')) : '' ?>"
                <?= !$pickerCanQuickAdd ? 'data-pdp-url="' . Security::escape($pickerPdpUrl) . '"' : '' ?>
                aria-label="<?= $pickerCanQuickAdd ? 'Add ' . Security::escape((string) $product['name']) . ' to cart' : 'View ' . Security::escape((string) $product['name']) ?>">
                <?= $pickerCanQuickAdd ? at_icon('plus', 'w-4 h-4') . 'Add' : 'View' ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</article>
