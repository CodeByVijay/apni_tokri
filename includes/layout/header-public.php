<?php

declare(strict_types=1);

/** @var string|null $activeNav */
$activeNav = $activeNav ?? '';
$cartCount = isset($cartCount) ? (int) $cartCount : 0;
$base = url_base();
$deliveryPin = DeliveryContext::sessionPin();
$currentSlug = isset($_GET['slug']) && is_string($_GET['slug']) ? $_GET['slug'] : '';

if (!function_exists('catalogue_product_url')) {
    require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';
}

// Search suggestion index (demo catalogue; replaced by an endpoint when the catalogue backend lands).
$searchIndex = [];
foreach (demo_catalogue()->products() as $sp) {
    $sv = catalogue_default_variant($sp);
    $spr = $sv ? catalogue_variant_prices($sv) : ['display' => 0];
    $scat = demo_catalogue()->category((string) $sp['category_slug']);
    $searchIndex[] = [
        'n' => (string) $sp['name'],
        'u' => catalogue_product_url($sp),
        'i' => (string) ($sp['images'][0] ?? ''),
        'p' => catalogue_format_price((float) $spr['display']),
        'c' => $scat ? (string) $scat['name'] : '',
        'k' => mb_strtolower($sp['name'] . ' ' . ($sp['short_description'] ?? '') . ' ' . ($sp['sku'] ?? '')),
    ];
}

// Navigation comes from the catalogue: only publicly visible top-level categories; children nest under parents.
$navTree = catalogue_nav_tree();
$navIsCategoryPage = $activeNav === 'categories';
?>
<a class="at-skip-link at-focusable" href="#main-content"><?= Security::escape(__('skip_to_content')) ?></a>

<header class="at-header" role="banner">
    <div class="at-header__inner">
        <div class="flex items-center gap-2 md:gap-5">
            <button type="button" id="at-menu-toggle"
                class="at-iconbtn md:!hidden -ml-2 at-focusable"
                aria-expanded="false" aria-controls="at-mobile-nav"
                aria-label="<?= Security::escape(__('menu_open')) ?>">
                <?= at_icon('menu') ?>
            </button>

            <a href="<?= Security::escape($base) ?>/index.php" class="at-logo at-focusable flex-shrink-0" aria-label="<?= Security::escape(BrandingSettings::logoAlt()) ?> — <?= Security::escape(__('nav_home')) ?>">
                <img src="<?= Security::escape(BrandingSettings::logoUrl()) ?>" alt="" width="44" height="44" decoding="async">
                <span>
                    <span class="at-logo__word"><?= Security::escape(__('brand_name')) ?></span>
                    <span class="at-logo__tag hidden sm:block"><?= Security::escape(__('tagline')) ?></span>
                </span>
            </a>

            <!-- Search (desktop) -->
            <form action="<?= Security::escape($base) ?>/search.php" method="get" role="search" class="at-search hidden md:block max-w-2xl mx-auto" data-at-search>
                <label class="sr-only" for="at-search"><?= Security::escape(__('search_placeholder')) ?></label>
                <div class="at-search__field">
                    <?= at_icon('search') ?>
                    <input id="at-search" type="search" name="q" placeholder="<?= Security::escape(__('search_placeholder')) ?>" autocomplete="off" aria-autocomplete="list" aria-controls="at-suggest">
                    <button type="submit">Search</button>
                </div>
                <div id="at-suggest" class="at-suggest" role="listbox"></div>
            </form>

            <div class="flex items-center gap-1 ml-auto flex-shrink-0">
                <button type="button" class="at-location hidden lg:inline-flex at-focusable" data-at-open-modal="#at-delivery-modal" aria-haspopup="dialog">
                    <span class="at-location__pin"><?= at_icon('pin', 'w-4 h-4') ?></span>
                    <span><small><?= Security::escape(__('delivery_to')) ?></small><strong>Indore<span data-at-loc-pin><?= $deliveryPin !== null ? ' · ' . Security::escape($deliveryPin) : '' ?></span></strong></span>
                </button>
                <?php
                $atLoggedIn = CustomerService::isLoggedIn();
                $atAuthLabel = $atLoggedIn ? 'My account' : __('nav_login');
                $atAuthHref = $atLoggedIn ? '/my-account.php' : '/login.php';
                ?>
                <a href="<?= Security::escape($base . $atAuthHref) ?>" class="at-iconbtn at-focusable hidden md:inline-flex" aria-label="<?= Security::escape($atAuthLabel) ?>">
                    <?= at_icon('user') ?><span class="hidden xl:inline"><?= Security::escape($atAuthLabel) ?></span>
                </a>
                <a href="<?= Security::escape($base) ?>/cart.php" class="at-iconbtn at-focusable" aria-label="<?= Security::escape(__('nav_cart')) ?>">
                    <?= at_icon('cart') ?>
                    <span data-at-cart-count class="at-cart-badge <?= $cartCount > 0 ? '' : 'hidden' ?>"><?= (int) $cartCount ?></span>
                </a>
            </div>
        </div>

        <!-- Search (mobile) -->
        <form action="<?= Security::escape($base) ?>/search.php" method="get" role="search" class="at-search md:hidden mt-2" data-at-search>
            <label class="sr-only" for="at-search-m"><?= Security::escape(__('search_placeholder')) ?></label>
            <div class="at-search__field">
                <?= at_icon('search') ?>
                <input id="at-search-m" type="search" name="q" placeholder="<?= Security::escape(__('search_placeholder')) ?>" autocomplete="off" aria-autocomplete="list" aria-controls="at-suggest-m">
                <button type="submit" class="sr-only">Search</button>
            </div>
            <div id="at-suggest-m" class="at-suggest" role="listbox"></div>
        </form>

        <!-- Category nav -->
        <nav class="hidden md:flex items-center justify-between mt-2 -mb-1 border-t border-[var(--at-line)] pt-1" aria-label="Categories">
            <ul class="at-dnav -ml-3" role="list" data-at-dnav>
                <li class="at-dnav__item"><a href="<?= Security::escape($base) ?>/index.php" class="at-dnav__link at-focusable <?= $activeNav === 'home' ? 'is-active' : '' ?>"><span><?= Security::escape(__('nav_home')) ?></span></a></li>
                <?= catalogue_render_desktop_nav($navTree, $currentSlug, $navIsCategoryPage) ?>
            </ul>
            <div class="at-catnav -mr-3">
                <a href="<?= Security::escape($base) ?>/faqs.php" class="at-focusable"><?= Security::escape(__('nav_faqs')) ?></a>
                <a href="<?= Security::escape($base) ?>/contact.php" class="at-focusable"><?= Security::escape(__('nav_contact')) ?></a>
            </div>
        </nav>
    </div>
    <script type="application/json" id="at-search-index"><?= json_encode($searchIndex, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</header>

<!-- Mobile drawer -->
<div id="at-mobile-nav" class="at-mobile-nav" aria-hidden="true">
    <div class="at-mobile-nav__backdrop" data-at-nav-close tabindex="-1"></div>
    <div class="at-mobile-nav__panel" role="dialog" aria-modal="true" aria-label="<?= Security::escape(__('nav_categories')) ?>">
        <div class="flex items-center justify-between p-4 border-b border-[var(--at-line)]">
            <span class="at-logo">
                <img src="<?= Security::escape(BrandingSettings::logoUrl()) ?>" alt="<?= Security::escape(BrandingSettings::logoAlt()) ?>" width="40" height="40">
                <span class="at-logo__word"><?= Security::escape(__('brand_name')) ?></span>
            </span>
            <button type="button" class="at-close at-focusable" data-at-nav-close aria-label="<?= Security::escape(__('menu_close')) ?>"><?= at_icon('close', 'w-5 h-5') ?></button>
        </div>
        <div class="p-3">
            <button type="button" class="at-location w-full !max-w-none mb-3 at-focusable" data-at-open-modal="#at-delivery-modal" data-at-nav-close>
                <span class="at-location__pin"><?= at_icon('pin', 'w-4 h-4') ?></span>
                <span><small><?= Security::escape(__('delivery_to')) ?></small><strong>Indore<span data-at-loc-pin><?= $deliveryPin !== null ? ' · ' . Security::escape($deliveryPin) : ' · ' . Security::escape(__('check_delivery')) ?></span></strong></span>
            </button>
            <p class="at-eyebrow px-3 mt-2 mb-1"><?= Security::escape(__('nav_categories')) ?></p>
            <a href="<?= Security::escape($base) ?>/index.php" class="at-drawer-link at-focusable"><?= at_icon('home') ?><?= Security::escape(__('nav_home')) ?></a>
            <nav class="at-macc-list" aria-label="<?= Security::escape(__('nav_categories')) ?>">
                <?= catalogue_render_mobile_nav($navTree, $currentSlug, $navIsCategoryPage) ?>
            </nav>
            <hr class="at-divider">
            <a href="<?= Security::escape($base) ?>/my-orders.php" class="at-drawer-link at-focusable"><?= at_icon('receipt') ?>My orders</a>
            <a href="<?= Security::escape($base) ?>/my-subscriptions.php" class="at-drawer-link at-focusable"><?= at_icon('repeat') ?>My subscriptions</a>
            <a href="<?= Security::escape($base) ?>/faqs.php" class="at-drawer-link at-focusable"><?= at_icon('help') ?><?= Security::escape(__('nav_faqs')) ?></a>
            <a href="<?= Security::escape($base) ?>/contact.php" class="at-drawer-link at-focusable"><?= at_icon('chat') ?><?= Security::escape(__('nav_contact')) ?></a>
            <a href="<?= Security::escape($base . $atAuthHref) ?>" class="at-btn at-btn--primary at-btn--block at-focusable mt-4"><?= Security::escape($atAuthLabel) ?></a>
        </div>
    </div>
</div>

<!-- Delivery modal -->
<div id="at-delivery-modal" class="at-modal-backdrop" data-current-pin="<?= Security::escape((string) ($deliveryPin ?? '')) ?>" aria-hidden="true" role="presentation">
    <div class="at-modal" role="dialog" aria-modal="true" aria-labelledby="at-delivery-modal-title">
        <div class="at-modal__header">
            <h2 id="at-delivery-modal-title" class="at-modal__title"><?= Security::escape(__('check_delivery')) ?></h2>
            <button type="button" class="at-close at-focusable" data-at-modal-close aria-label="<?= Security::escape(__('menu_close')) ?>"><?= at_icon('close', 'w-5 h-5') ?></button>
        </div>
        <div class="at-modal__body space-y-3">
            <p class="text-sm at-muted">We currently plan to deliver in select Indore areas. Enter your pincode to see if we reach you.</p>
            <div>
                <label class="at-label" for="at-pincode">Pincode / area</label>
                <input id="at-pincode" type="text" class="at-input at-focusable" placeholder="e.g. 452001" inputmode="numeric" maxlength="6" autocomplete="postal-code" value="<?= Security::escape((string) ($deliveryPin ?? '')) ?>">
                <p class="at-field-hint" id="at-pin-result" role="status" aria-live="polite"></p>
            </div>
        </div>
        <div class="at-modal__footer">
            <button type="button" class="at-btn at-btn--secondary at-focusable" data-at-modal-close>Close</button>
            <button type="button" id="at-pin-check-btn" class="at-btn at-btn--primary at-focusable">Check delivery</button>
        </div>
    </div>
</div>
