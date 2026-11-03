<?php

declare(strict_types=1);

/** @var array<string, mixed> $product */
$variant = catalogue_default_variant($product);
$prices = $variant ? catalogue_variant_prices($variant) : ['display' => 0, 'regular' => 0, 'on_sale' => false];
$stockLabel = $variant ? catalogue_stock_label($variant) : 'Out of stock';
$inStock = catalogue_is_in_stock($product);
$rating = demo_catalogue()->ratingSummary((string) $product['slug']);
$image = $product['images'][0] ?? asset_url('assets/images/logo-placeholder.svg');
$productUrl = catalogue_product_url($product);
$multiVariant = count($product['variants'] ?? []) > 1;
// Honest discount: derived only from the real regular vs sale price.
$discountPct = $prices['on_sale'] && $prices['regular'] > 0
    ? (int) round((1 - $prices['display'] / $prices['regular']) * 100)
    : 0;
?>
<article class="at-product-card">
    <a href="<?= Security::escape($productUrl) ?>" class="at-product-card__media at-focusable" tabindex="-1" aria-hidden="true">
        <img src="<?= Security::escape($image) ?>" alt="" loading="lazy" width="400" height="400">
        <span class="at-product-card__flags">
            <span class="flex flex-col items-start gap-1">
                <?php if ($discountPct > 0): ?>
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
                <?= $variant ? Security::escape((string) $variant['label']) : '&nbsp;' ?><?= $multiVariant ? ' · +' . (count($product['variants']) - 1) . ' more' : '' ?>
            </p>
            <div class="at-product-card__extras">
                <?php if ($rating !== null): ?>
                    <p class="at-product-card__rating flex items-center gap-1 text-xs font-semibold">
                        <span class="text-[var(--at-yellow-dark)]" aria-hidden="true">★</span><?= Security::escape((string) $rating['average']) ?>
                        <span class="font-normal at-muted">(<?= (int) $rating['count'] ?>)</span>
                    </p>
                <?php endif; ?>
                <?php if ($stockLabel === 'Low stock' && $variant): ?>
                    <span class="at-stockline">Only <?= (int) $variant['stock'] ?> left</span>
                <?php elseif (!$inStock): ?>
                    <span class="at-stockline at-stockline--out">Out of stock</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="at-product-card__foot">
            <div class="at-price">
                <span class="at-price__now"><?= Security::escape(catalogue_format_price($prices['display'])) ?></span>
                <?php if ($prices['on_sale']): ?>
                    <span class="at-price__was"><?= Security::escape(catalogue_format_price($prices['regular'])) ?></span>
                <?php endif; ?>
            </div>
            <?php if (catalogue_can_quick_add($product)): ?>
                <button type="button" class="at-product-card__add at-focusable js-demo-add-cart"
                    data-product="<?= Security::escape((string) $product['slug']) ?>"
                    data-variant-id="<?= Security::escape((string) ($variant['id'] ?? '')) ?>"
                    aria-label="Add <?= Security::escape((string) $product['name']) ?> to cart">
                    <?= at_icon('plus', 'w-4 h-4') ?>Add
                </button>
            <?php else: ?>
                <a href="<?= Security::escape($productUrl) ?>" class="at-product-card__add at-focusable">
                    <?= $multiVariant ? 'Options' : 'View' ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</article>
