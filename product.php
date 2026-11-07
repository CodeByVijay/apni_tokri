<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? trim($_GET['slug']) : '';
$product = $slug !== '' ? demo_catalogue()->product($slug) : null;
$loadCatalogueJs = true;

if ($product === null) {
    http_response_code(404);
    layout_public_start(['seo' => SeoResolver::notFound(), 'activeNav' => '']);
    require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';
    $emptyTitle = 'Product not found';
    $emptyMessage = 'This item may be unavailable or the link is incorrect.';
    $emptyActionHref = url_base() . '/index.php';
    $emptyActionLabel = 'Back to home';
    require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
    layout_public_end();
    exit;
}

$variants = $product['variants'] ?? [];
$defaultVariant = catalogue_default_variant($product);
$rating = demo_catalogue()->ratingSummary($slug);
$reviews = demo_catalogue()->approvedReviewsFor($slug);
$related = demo_catalogue()->relatedFor($product, 4);
$mainProduct = $product; // product-grid partial reuses $product for related cards
$cat = demo_catalogue()->category((string) $product['category_slug']);

$seoProduct = SeoResolver::forStorefrontProduct($product);
layout_public_start([
    'seo' => $seoProduct,
    'jsonLd' => SeoStructuredData::forProductPage($product, $cat ?: null, $rating, $reviews, $seoProduct),
    'activeNav' => 'categories',
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';

$prices = $defaultVariant ? catalogue_variant_prices($defaultVariant) : ['display' => 0, 'regular' => 0, 'on_sale' => false];
$saveAmt = $prices['on_sale'] ? $prices['regular'] - $prices['display'] : 0;
$savePct = $prices['on_sale'] && $prices['regular'] > 0 ? (int) round($saveAmt / $prices['regular'] * 100) : 0;
$storageAttrs = [];
$otherAttrs = [];
foreach ($product['attributes'] ?? [] as $attr) {
    if (in_array($attr['label'], ['Storage', 'Use-by', 'Shelf life', 'Preparation'], true)) {
        $storageAttrs[] = $attr;
    } else {
        $otherAttrs[] = $attr;
    }
}
$isPackaged = ($product['type'] ?? '') === 'packaged';
$pdpVariantsInCart = DemoCart::variantIdsForProduct($slug);
$pdpCartUrl = url_base() . '/cart.php';
?>
<div class="at-container">
    <nav class="at-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= Security::escape(url_base()) ?>/index.php">Home</a><span class="sep">/</span>
        <?php if ($cat): ?>
            <a href="<?= Security::escape(catalogue_category_url($cat)) ?>"><?= Security::escape($cat['name']) ?></a><span class="sep">/</span>
        <?php endif; ?>
        <span aria-current="page"><?= Security::escape((string) $product['name']) ?></span>
    </nav>
</div>

<div class="at-container pb-10 grid lg:grid-cols-[1.05fr_1fr] gap-8 lg:gap-14 items-start">
    <div class="lg:sticky lg:top-40">
        <?php require APNI_TOKRI_ROOT . '/includes/partials/product-gallery.php'; ?>
    </div>

    <div class="at-buybox">
        <div>
            <?php if ($cat): ?><p class="at-eyebrow"><?= Security::escape($cat['name']) ?></p><?php endif; ?>
            <h1 class="at-h1 mt-1"><?= Security::escape((string) $product['name']) ?></h1>
            <p class="mt-2 at-muted"><?= Security::escape((string) ($product['short_description'] ?? '')) ?></p>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                <?php if ($rating !== null): ?>
                    <a href="#reviews" class="flex items-center gap-2 font-semibold no-underline">
                        <span class="at-stars" aria-hidden="true"><?= str_repeat('★', (int) round($rating['average'])) ?><span class="is-off"><?= str_repeat('★', 5 - (int) round($rating['average'])) ?></span></span>
                        <?= Security::escape((string) $rating['average']) ?>
                        <span class="at-muted font-normal">(<?= (int) $rating['count'] ?> <?= storefront_uses_demo_catalogue() ? 'sample ' : '' ?>review<?= $rating['count'] === 1 ? '' : 's' ?>)</span>
                    </a>
                <?php else: ?>
                    <span class="at-muted">No reviews yet — verified customers can review after delivery</span>
                <?php endif; ?>
                <span class="at-muted">SKU <?= Security::escape((string) ($product['sku'] ?? '')) ?></span>
            </div>
        </div>

        <div class="at-card at-card--flat">
            <div class="at-card__body !py-4">
                <div class="flex items-end gap-3 flex-wrap">
                    <span class="at-price at-price--lg"><span class="at-price__now" id="at-product-price"><?= Security::escape(catalogue_format_price($prices['display'])) ?></span></span>
                    <span id="at-product-price-regular" class="text-lg at-muted line-through pb-1 <?= $prices['on_sale'] ? '' : 'hidden' ?>"><?= Security::escape(catalogue_format_price($prices['regular'])) ?></span>
                    <?php if ($savePct > 0): ?><span class="at-badge at-badge--sale mb-1.5">You save <?= Security::escape(catalogue_format_price($saveAmt)) ?> (<?= (int) $savePct ?>%)</span><?php endif; ?>
                </div>
                <p class="text-xs at-muted mt-1">Inclusive of all taxes. Delivery fee is shown in your cart before you pay.</p>
                <p id="at-product-stock-msg" class="mt-3 text-sm"></p>
            </div>
        </div>

        <form class="space-y-5" data-at-product-form action="#" method="post"
            data-product-slug="<?= Security::escape((string) $product['slug']) ?>"
            data-checkout-url="<?= Security::escape(url_base()) ?>/checkout.php"
            data-cart-url="<?= Security::escape($pdpCartUrl) ?>"
            data-variants-in-cart="<?= Security::escape(json_encode($pdpVariantsInCart, JSON_THROW_ON_ERROR)) ?>">
            <?= Csrf::field() ?>
            <?php if (count($variants) > 1): ?>
                <fieldset>
                    <legend class="at-label">Choose weight / size</legend>
                    <div class="flex flex-wrap gap-2 mt-2">
                        <?php foreach ($variants as $i => $v):
                            $vp = catalogue_variant_prices($v);
                            $orderCap = CartQuantityRules::orderableQtyFromCatalogueVariant($v);
                            $id = 'variant-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $v['id']);
                            ?>
                            <label class="inline-flex">
                                <input type="radio" name="variant_id" id="<?= $id ?>" class="sr-only"
                                    value="<?= Security::escape((string) $v['id']) ?>"
                                    data-price="<?= Security::escape((string) $vp['display']) ?>"
                                    data-regular="<?= Security::escape((string) $vp['regular']) ?>"
                                    data-sale="<?= $vp['on_sale'] ? '1' : '0' ?>"
                                    data-stock="<?= (int) ($v['stock'] ?? 0) ?>"
                                    data-order-cap="<?= (int) $orderCap ?>"
                                    data-low="<?= (int) ($v['low_stock'] ?? 5) ?>"
                                    <?= $i === 0 ? 'checked' : '' ?>>
                                <span class="at-chip"><?= Security::escape((string) $v['label']) ?> · <?= Security::escape(catalogue_format_price($vp['display'])) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php elseif (count($variants) === 1):
                $v = $variants[0];
                $vp = catalogue_variant_prices($v);
                $orderCap = CartQuantityRules::orderableQtyFromCatalogueVariant($v);
                ?>
                <input type="radio" name="variant_id" class="sr-only" checked
                    value="<?= Security::escape((string) $v['id']) ?>"
                    data-price="<?= Security::escape((string) $vp['display']) ?>"
                    data-regular="<?= Security::escape((string) $vp['regular']) ?>"
                    data-sale="<?= $vp['on_sale'] ? '1' : '0' ?>"
                    data-stock="<?= (int) ($v['stock'] ?? 0) ?>"
                    data-order-cap="<?= (int) $orderCap ?>"
                    data-low="<?= (int) ($v['low_stock'] ?? 5) ?>">
                <p class="text-sm"><span class="font-semibold">Pack size:</span> <?= Security::escape((string) $v['label']) ?></p>
            <?php endif; ?>

            <div>
                <label class="at-label" for="qty">Quantity</label>
                <div class="at-qty">
                    <button type="button" data-qty-step="-1" aria-label="Decrease quantity">−</button>
                    <?php
                    $qtyCapVariant = $defaultVariant ?? ($variants[0] ?? ['stock' => 0, 'max_qty_per_order' => CartQuantityRules::DEFAULT_MAX_PER_ORDER]);
                    $pdpQtyMax = max(1, CartQuantityRules::orderableQtyFromCatalogueVariant($qtyCapVariant));
                    ?>
                    <input id="qty" name="qty" type="number" min="1" max="<?= (int) $pdpQtyMax ?>" value="1" inputmode="numeric">
                    <button type="button" data-qty-step="1" aria-label="Increase quantity">+</button>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" id="at-add-cart" class="at-btn at-btn--primary at-btn--lg at-focusable flex-1"><?= at_icon('cart', 'w-5 h-5') ?> Add to cart</button>
                <button type="button" id="at-buy-now" class="at-btn at-btn--accent at-btn--lg at-focusable flex-1">Buy now</button>
            </div>
        </form>

        <div class="at-pdp-points">
            <div><?= at_icon('truck') ?><span><strong>Delivery in Indore.</strong> Fee and time slot are shown at checkout — nothing is added without your say.</span></div>
            <div><?= at_icon('refresh') ?><span><strong>Problem with your order?</strong> See our <a class="underline font-semibold" href="<?= Security::escape(url_base()) ?>/page.php?slug=refund-policy">refund policy</a> or raise a ticket.</span></div>
            <?php if (($product['type'] ?? '') === 'cut'): ?>
                <div><?= at_icon('clock') ?><span><strong>Cut to order.</strong> Check the use-by guidance below and keep refrigerated.</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="at-section--white">
    <div class="at-container py-8 md:py-12 grid lg:grid-cols-2 gap-8 lg:gap-14">
        <div>
            <h2 class="at-h2 !text-2xl">About this product</h2>
            <p class="mt-3 leading-relaxed text-[var(--at-ink-2)]"><?= Security::escape((string) ($product['description'] ?? '')) ?></p>
            <?php if ($storageAttrs !== []): ?>
                <h3 class="at-h3 mt-6 flex items-center gap-2"><?= at_icon('box', 'w-5 h-5 text-[var(--at-green)]') ?>Storage &amp; preparation</h3>
                <dl class="at-spec mt-3">
                    <?php foreach ($storageAttrs as $attr): ?>
                        <div><dt><?= Security::escape($attr['label']) ?></dt><dd><?= Security::escape($attr['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </div>
        <div>
            <h2 class="at-h2 !text-2xl"><?= $isPackaged ? 'Ingredients &amp; label details' : 'Product information' ?></h2>
            <?php if ($otherAttrs !== []): ?>
                <dl class="at-spec mt-3">
                    <?php foreach ($otherAttrs as $attr): ?>
                        <div><dt><?= Security::escape($attr['label']) ?></dt><dd><?= Security::escape($attr['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php else: ?>
                <p class="mt-3 text-sm at-muted">No extra specifications for this item.</p>
            <?php endif; ?>
            <?php if ($isPackaged): ?>
                <div class="at-alert at-alert--warning mt-4 text-sm"><?= at_icon('info', 'w-5 h-5 flex-shrink-0') ?><span><strong>Allergens:</strong> always check the pack label for allergen information. Details shown here must match the printed label at launch.</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<section class="at-container py-8 md:py-12" id="reviews">
    <div class="at-section-head">
        <div>
            <h2 class="at-h2 !text-2xl">Customer reviews</h2>
            <p>Only verified purchases from delivered orders can review, and every review is approved before it appears.</p>
        </div>
    </div>
    <?php if ($reviews === []): ?>
        <div class="at-card at-card--tint"><div class="at-card__body flex flex-wrap items-center justify-between gap-4">
            <div><h3 class="at-h3">No reviews yet</h3><p class="text-sm at-muted mt-1">Be the first to share how this arrived once your order is delivered.</p></div>
            <a href="<?= Security::escape(url_base()) ?>/submit-review.php" class="at-btn at-btn--secondary at-focusable">Write a review</a>
        </div></div>
    <?php else: ?>
        <div class="grid md:grid-cols-[16rem_1fr] gap-6">
            <div class="at-card"><div class="at-card__body text-center">
                <p class="at-display !text-5xl"><?= Security::escape((string) $rating['average']) ?></p>
                <p class="at-stars mt-1" aria-hidden="true"><?= str_repeat('★', (int) round($rating['average'])) ?><span class="is-off"><?= str_repeat('★', 5 - (int) round($rating['average'])) ?></span></p>
                <p class="text-xs at-muted mt-1">Based on <?= (int) $rating['count'] ?> <?= storefront_uses_demo_catalogue() ? 'sample ' : '' ?>review<?= $rating['count'] === 1 ? '' : 's' ?></p>
                <a href="<?= Security::escape(url_base()) ?>/submit-review.php" class="at-btn at-btn--secondary at-btn--sm at-btn--block at-focusable mt-4">Write a review</a>
            </div></div>
            <ul class="grid gap-3">
                <?php foreach ($reviews as $review): ?>
                    <li class="at-review">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="at-stars" aria-label="<?= (int) ($review['rating'] ?? 0) ?> out of 5"><?= str_repeat('★', (int) ($review['rating'] ?? 0)) ?><span class="is-off"><?= str_repeat('★', 5 - (int) ($review['rating'] ?? 0)) ?></span></span>
                            <?php if (!empty($review['verified'])): ?><span class="at-badge at-badge--green"><?= at_icon('check', 'w-3 h-3') ?>Verified purchase</span><?php endif; ?>
                            <?php if (storefront_uses_demo_catalogue() && !empty($review['is_sample'])): ?><span class="at-badge at-badge--neutral">Sample UI review</span><?php endif; ?>
                        </div>
                        <p class="mt-2 font-bold"><?= Security::escape($review['title'] ?? '') ?></p>
                        <p class="mt-1 text-[var(--at-ink-2)]"><?= Security::escape($review['body'] ?? '') ?></p>
                        <p class="mt-3 text-xs at-muted"><?= Security::escape($review['author'] ?? '') ?> · <?= Security::escape($review['date'] ?? '') ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>

<?php if ($related !== []): ?>
    <div class="at-section--white">
        <?php
        $sectionTitle = 'Pairs well with';
        $sectionSubtitle = 'Add anything you like — nothing is added to your cart automatically.';
        $sectionHref = null;
        $sectionLinkLabel = null;
        $products = $related;
        require APNI_TOKRI_ROOT . '/includes/partials/home-section.php';
        ?>
    </div>
<?php endif; ?>

<!-- Sticky mobile buy bar -->
<div id="at-sticky-buy" class="at-sticky-buy" aria-label="Quick add to cart">
    <div class="min-w-0 flex-1">
        <p class="text-xs at-muted truncate"><?= Security::escape((string) $mainProduct['name']) ?></p>
        <p class="font-extrabold text-lg leading-tight" id="at-sticky-price"><?= Security::escape(catalogue_format_price($prices['display'])) ?></p>
    </div>
    <button type="button" class="at-btn at-btn--primary at-focusable" data-sticky-add>Add to cart</button>
</div>
<?php layout_public_end();
