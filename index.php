<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

Security::sendSecurityHeaders(Security::isHttps());

$catalogue = demo_catalogue();
$loadCatalogueJs = true;
$base = url_base();

$seoHome = SeoResolver::forHomepage();
layout_public_start([
    'seo' => $seoHome,
    'jsonLd' => SeoStructuredData::forHomepage($seoHome),
    'activeNav' => 'home',
]);
require APNI_TOKRI_ROOT . '/includes/partials/demo-banner.php';

$primaryCategoryUrl = catalogue_primary_category_url();

$categoryHints = [
    'vegetables' => 'Daily staples',
    'fruits' => 'Seasonal picks',
    'cut' => 'Washed & ready',
    'pickles' => 'Pantry favourites',
];
?>

<!-- Hero -->
<section class="at-hero">
    <div class="at-container at-hero__grid">
        <div class="at-reveal">
            <p class="at-eyebrow">Indore · Fresh, packed with care</p>
            <h1 class="at-display mt-3"><?= Security::escape(__('tagline')) ?></h1>
            <p class="at-lead mt-4 max-w-lg">Fresh vegetables, fruits, ready-cut produce and pantry favourites — with clear prices and the delivery fee shown before you pay.</p>

            <form class="at-checker mt-6" id="at-hero-pin-form" action="#" novalidate aria-label="<?= Security::escape(__('check_delivery')) ?>">
                <span class="hidden sm:flex items-center pl-3 text-[var(--at-green)]"><?= at_icon('pin', 'w-5 h-5') ?></span>
                <label class="sr-only" for="hero-pincode">Pincode</label>
                <input id="hero-pincode" type="text" inputmode="numeric" maxlength="6" autocomplete="postal-code" placeholder="Enter pincode, e.g. 452001" value="<?= Security::escape((string) (DeliveryContext::sessionPin() ?? '')) ?>">
                <button type="submit" class="at-btn at-btn--primary at-focusable" id="hero-pin-check">Check</button>
            </form>
            <div id="hero-pin-result" class="at-pin-result mt-3 hidden" role="status" aria-live="polite">
                <p class="at-pin-result__status"></p>
                <p class="at-pin-result__msg text-sm"></p>
                <div class="at-pin-result__actions mt-2 flex flex-wrap gap-2">
                    <a href="<?= Security::escape($primaryCategoryUrl) ?>" class="at-btn at-btn--primary at-btn--sm at-focusable" data-pin-continue>Continue shopping</a>
                    <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-pin-change>Change pincode</button>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <a href="<?= Security::escape($primaryCategoryUrl) ?>" class="at-btn at-btn--accent at-btn--lg at-focusable">Shop fresh vegetables <?= at_icon('arrow-right', 'w-5 h-5') ?></a>
                <a href="<?= Security::escape($base) ?>/my-subscriptions.php" class="at-btn at-btn--secondary at-btn--lg at-focusable">See subscriptions</a>
            </div>
        </div>
        <div class="at-hero__media at-reveal" style="animation-delay:.08s">
            <img src="<?= Security::escape(asset_url('assets/images/demo/hero-basket.jpg')) ?>" alt="A cane tokri basket filled with tomatoes, capsicum, bananas, apples and coriander" width="1024" height="576" fetchpriority="high">
            <div class="at-hero__chip">
                <?= at_icon('shield') ?>
                <span>Checked &amp; packed<br><span class="at-muted font-normal">before it leaves us</span></span>
            </div>
        </div>
    </div>
</section>

<?php HomepageTrustRenderer::renderSections($base, $catalogue, 'after_hero'); ?>

<!-- Categories -->
<section class="at-section" aria-labelledby="home-cats">
    <div class="at-container">
        <div class="at-section-head">
            <div>
                <h2 id="home-cats" class="at-h2">Shop by category</h2>
                <p>Everything for the daily tokri, in four aisles.</p>
            </div>
        </div>
        <?php if (storefront_catalogue_coming_soon()): ?>
            <?php
            $emptyTitle = 'Products coming soon';
            $emptyMessage = 'We\'re preparing our catalogue.';
            require APNI_TOKRI_ROOT . '/includes/partials/empty-state.php';
            ?>
        <?php else: ?>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-5">
            <?php foreach (catalogue_homepage_categories() as $cat):
                $name = Lang::locale() === 'hi' ? ($cat['name_hi'] ?? $cat['name']) : $cat['name'];
                $count = count($catalogue->productsInCategory((string) $cat['slug']));
                ?>
                <a href="<?= Security::escape(catalogue_category_url($cat)) ?>" class="at-category-tile at-focusable">
                    <img src="<?= Security::escape(catalogue_category_tile_image($cat)) ?>" alt="<?= Security::escape(catalogue_category_tile_image_alt($cat)) ?>" loading="lazy">
                    <span class="at-category-tile__label">
                        <strong><?= Security::escape($name) ?></strong>
                        <span><?= Security::escape($categoryHints[$cat['slug']] ?? '') ?> · <?= (int) $count ?> items</span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if (!storefront_catalogue_coming_soon()): ?>
<?php
$sectionTitle = 'Best sellers';
$sectionSubtitle = storefront_uses_demo_catalogue()
    ? 'Our most-picked items (demo flags until real sales data exists)'
    : 'Customer favourites';
$sectionHref = $base . '/search.php?q=';
$sectionLinkLabel = 'Browse all';
$products = catalogue_home_eligible_products($catalogue->productsWithFlag('bestseller', PHP_INT_MAX), 5);
if ($products !== []) {
    require APNI_TOKRI_ROOT . '/includes/partials/home-section.php';
}
?>

<?php if (!AppEnvironment::isProduction()): ?>
<!-- Offer -->
<section class="at-container pb-2" aria-label="Current offer">
    <div class="at-offer">
        <div class="relative">
            <span class="at-badge at-badge--yellow">Offer (demo)</span>
            <h2>Free delivery on orders above ₹499</h2>
            <p>Your cart shows exactly how much more you need. Eligibility and the final fee are always confirmed at checkout.</p>
        </div>
        <a href="<?= Security::escape($base) ?>/cart.php" class="at-btn at-btn--accent at-btn--lg at-focusable relative">View cart</a>
    </div>
</section>
<?php endif; ?>

<?php
// Rails are grouped so each shelf is visually full with the small demo catalogue.
$rails = [
    ['Fresh vegetables & fruits', 'Daily staples and seasonal picks', ['vegetables', 'fruits']],
    ['Ready-cut & pantry', 'Save prep time; stock up on favourites', ['cut', 'pickles']],
];
foreach ($rails as $rail) {
    $sectionTitle = $rail[0];
    $sectionSubtitle = $rail[1];
    $railSlugs = $rail[2];
    $linkSlug = catalogue_first_public_category_slug($railSlugs);
    $sectionHref = $linkSlug !== null
        ? $base . '/category.php?slug=' . rawurlencode($linkSlug)
        : $primaryCategoryUrl;
    $sectionLinkLabel = 'View all';
    $products = catalogue_home_eligible_products(catalogue_products_in_public_categories($railSlugs), 5);
    if ($products === []) {
        continue;
    }
    require APNI_TOKRI_ROOT . '/includes/partials/home-section.php';
}
?>
<?php endif; ?>

<!-- Subscriptions -->
<section class="at-section at-section--white" id="subscriptions">
    <div class="at-container">
        <div class="at-section-head">
            <div>
                <p class="at-eyebrow">Prepaid plans</p>
                <h2 class="at-h2 mt-1">Never run out of the basics</h2>
                <p>Pay once for a fixed number of deliveries. Skip rules and carry-forward are explained before you pay.</p>
            </div>
        </div>
        <?php $homePlans = SubscriptionCheckoutService::activeStorefrontPlanCards(); ?>
        <?php if ($homePlans === []): ?>
            <div class="at-alert at-alert--info">Subscription plans will be available soon.</div>
        <?php else: ?>
        <div class="at-plans">
            <?php foreach ($homePlans as $i => $plan):
                $featured = $i === 1 && count($homePlans) > 1;
                ?>
                <article class="at-plan <?= $featured ? 'at-plan--featured' : '' ?>">
                    <?php if ($featured): ?><span class="at-badge at-badge--green at-plan__ribbon">Popular</span><?php endif; ?>
                    <h3 class="at-h3"><?= Security::escape($plan['name']) ?></h3>
                    <p class="at-plan__price mt-3"><?= Security::escape(catalogue_format_price((float) $plan['price'])) ?></p>
                    <p class="text-sm at-muted mt-1"><?= (int) $plan['delivery_count'] ?> deliveries · <?= (int) $plan['calendar_duration_days'] ?>-day window · <?= Security::escape($plan['frequency_label'] ?? 'Daily') ?></p>
                    <ul class="flex-1 mt-3 text-sm">
                        <li><?= at_icon('check') ?><span>Basket allowance ≈ <?= Security::escape(catalogue_format_price((float) ($plan['daily_allowance_inr'] ?? 0))) ?>/day</span></li>
                        <li><?= at_icon('check') ?><span>Skip rules explained before you pay</span></li>
                    </ul>
                    <a href="<?= Security::escape($base) ?>/subscribe.php?plan=<?= (int) $plan['id'] ?>" class="at-btn <?= $featured ? 'at-btn--primary' : 'at-btn--secondary' ?> at-btn--block mt-4">Subscribe</a>
                </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
        $cmsSubTermsSuffix = 'before subscribing.';
        require APNI_TOKRI_ROOT . '/includes/partials/cms-subscription-terms-link.php';
        ?>
    </div>
</section>

<?php HomepageTrustRenderer::renderSections($base, $catalogue, 'after_subscriptions'); ?>

<?php
layout_public_end();
