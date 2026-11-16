<?php

declare(strict_types=1);

/** @var string|null $activeNav */
$activeNav = $activeNav ?? '';
$cartCount = isset($cartCount) ? (int) $cartCount : 0;
$base = url_base();
$deliveryPin = DeliveryContext::sessionPin();
$selAddr = DeliverySessionAddress::current();
$selTitle = $selAddr !== null ? DeliverySessionAddress::title($selAddr) : 'Indore';
if ($selAddr !== null) {
    $selSub = DeliverySessionAddress::subtitle($selAddr);
} elseif ($deliveryPin !== null) {
    $selSub = $deliveryPin;
} else {
    $selSub = (string) __('check_delivery');
}
$selSavedAddresses = CustomerService::isLoggedIn() ? CustomerService::addresses() : [];
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
                    <span><small><?= Security::escape(__('delivery_to')) ?></small><strong><span data-at-loc-title><?= Security::escape($selTitle) ?></span><span data-at-loc-pin><?= $selSub !== '' ? ' · ' . Security::escape($selSub) : '' ?></span></strong></span>
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

        <?php if (!empty($homeMobileChrome)): ?>
        <button type="button" class="at-location at-home-location md:hidden at-focusable" data-at-open-modal="#at-delivery-modal" aria-haspopup="dialog">
            <span class="at-location__pin"><?= at_icon('pin', 'w-4 h-4') ?></span>
            <span class="at-home-location__text min-w-0 flex-1"><small><?= Security::escape(__('delivery_to')) ?></small><strong><span data-at-loc-title><?= Security::escape($selTitle) ?></span><span data-at-loc-pin><?= ' · ' . Security::escape($selSub) ?></span></strong></span>
            <?= at_icon('chevron-right', 'w-4 h-4 at-home-location__chevron') ?>
        </button>
        <?php endif; ?>

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
                <span><small><?= Security::escape(__('delivery_to')) ?></small><strong><span data-at-loc-title><?= Security::escape($selTitle) ?></span><span data-at-loc-pin><?= ' · ' . Security::escape($selSub) ?></span></strong></span>
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

<!-- Delivery location selector -->
<?php
$atDlConfig = [
    'saveUrl' => $base . '/ajax/delivery/select-address.php',
    'autocompleteUrl' => $base . '/ajax/address/autocomplete-search.php',
    'reverseGeocodeUrl' => $base . '/ajax/address/reverse-geocode.php',
    'searchEnabled' => AddressAutocompleteSettings::isEnabled(),
    'minChars' => 3,
    'debounceMs' => 400,
    'maxResults' => AddressAutocompleteSettings::maxResults(),
    'map' => MapConfig::clientConfig(),
    'leaflet' => [
        'css' => asset_url('assets/vendor/leaflet/leaflet.css'),
        'js' => asset_url('assets/vendor/leaflet/leaflet.js'),
    ],
];
?>
<div id="at-delivery-modal" class="at-modal-backdrop" data-current-pin="<?= Security::escape((string) ($deliveryPin ?? '')) ?>" aria-hidden="true" role="presentation">
    <div class="at-modal at-dl" role="dialog" aria-modal="true" aria-labelledby="at-delivery-modal-title">
        <div class="at-modal__header">
            <h2 id="at-delivery-modal-title" class="at-modal__title">Choose your delivery location</h2>
            <button type="button" class="at-close at-focusable" data-at-modal-close aria-label="<?= Security::escape(__('menu_close')) ?>"><?= at_icon('close', 'w-5 h-5') ?></button>
        </div>
        <div class="at-modal__body at-dl__body">
            <p class="at-dl__msg" data-at-dl-msg role="status" aria-live="polite" hidden></p>

            <section class="at-dl__step" data-at-dl-step="choose">
                <?php if ($selAddr !== null): ?>
                <div class="at-dl__current" data-at-dl-current>
                    <span class="at-dl__tag"><?= $selAddr['kind'] === 'saved' ? 'Saved' : 'Current' ?></span>
                    <div class="at-dl__current-text">
                        <strong data-at-dl-current-title><?= Security::escape(DeliverySessionAddress::title($selAddr)) ?></strong>
                        <span data-at-dl-current-line><?= Security::escape(DeliverySessionAddress::readableLine($selAddr)) ?></span>
                        <?php
                        $selSvc = $selAddr['serviceable'] ?? null;
                        $selSvcClass = $selSvc === true ? 'at-dl__status at-dl__status--ok' : ($selSvc === false ? 'at-dl__status at-dl__status--warn' : 'at-dl__status');
                        $selSvcText = '';
                        if ($selSvc === true) {
                            $selSvcText = 'Delivery available' . (!empty($selAddr['service_zone']) ? ' · ' . (string) $selAddr['service_zone'] : '');
                        } elseif ($selSvc === false) {
                            $selSvcText = (string) ($selAddr['service_message'] ?? 'We do not deliver here yet.');
                        }
                        ?>
                        <span class="<?= Security::escape($selSvcClass) ?>" data-at-dl-current-status<?= $selSvc === null ? ' hidden' : '' ?>><?= Security::escape($selSvcText) ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="at-dl__searchwrap<?= $atDlConfig['searchEnabled'] ? '' : ' hidden' ?>" data-at-dl-searchwrap>
                    <?= at_icon('search') ?>
                    <input type="search" class="at-dl__search" data-at-dl-search placeholder="Search for area, street or landmark" autocomplete="off" aria-label="Search for your delivery area" aria-expanded="false" aria-controls="at-dl-results">
                </div>
                <ul id="at-dl-results" class="at-dl__results" data-at-dl-results role="listbox" hidden></ul>
                <p class="at-dl__hint" data-at-dl-searchhint<?= $atDlConfig['searchEnabled'] ? ' hidden' : '' ?>>Address search is not enabled yet — you can pick your location on the map or enter it below.</p>

                <ul class="at-dl__actions">
                    <li>
                        <button type="button" class="at-dl__action at-focusable" data-at-dl-gps>
                            <span class="at-dl__action-ic"><?= at_icon('pin', 'w-5 h-5') ?></span>
                            <span class="at-dl__action-text"><strong>Use my current location</strong><small data-at-dl-gps-sub>GPS — fastest way</small></span>
                            <?= at_icon('chevron-right', 'w-4 h-4 at-dl__action-caret') ?>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="at-dl__action at-focusable" data-at-dl-map>
                            <span class="at-dl__action-ic"><?= at_icon('pin', 'w-5 h-5') ?></span>
                            <span class="at-dl__action-text"><strong>Select location on map</strong><small>Tap to drop a pin</small></span>
                            <?= at_icon('chevron-right', 'w-4 h-4 at-dl__action-caret') ?>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="at-dl__action at-focusable" data-at-dl-manual>
                            <span class="at-dl__action-ic"><?= at_icon('home', 'w-5 h-5') ?></span>
                            <span class="at-dl__action-text"><strong>Enter address manually</strong><small>Fill in the details</small></span>
                            <?= at_icon('chevron-right', 'w-4 h-4 at-dl__action-caret') ?>
                        </button>
                    </li>
                </ul>

                <?php if (!empty($selSavedAddresses)): ?>
                <div class="at-dl__saved">
                    <p class="at-eyebrow at-dl__saved-title">Your saved addresses</p>
                    <ul class="at-dl__saved-list">
                        <?php foreach ($selSavedAddresses as $sa): ?>
                        <li>
                            <button type="button" class="at-dl__saved-item at-focusable" data-at-dl-saved="<?= Security::escape((string) $sa['id']) ?>">
                                <span class="at-dl__saved-top">
                                    <strong><?= Security::escape((string) $sa['label']) ?></strong>
                                    <?= !empty($sa['is_default']) ? '<span class="at-dl__tag">Default</span>' : '' ?>
                                </span>
                                <span class="at-dl__saved-line"><?= Security::escape(trim((string) $sa['line1'] . ', ' . (string) $sa['city'] . ' ' . (string) $sa['pincode'], ', ')) ?></span>
                            </button>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </section>

            <section class="at-dl__step" data-at-dl-step="map" hidden>
                <button type="button" class="at-dl__back at-focusable" data-at-dl-back>&larr; Back</button>
                <p class="text-sm at-muted">Tap the map or drag the pin to your exact delivery spot.</p>
                <div class="at-dl__mapwrap">
                    <div class="at-dl__map" data-at-dl-map-el role="application" aria-label="Map for choosing your delivery location. Tap to place the pin or drag it."></div>
                    <div class="at-dl__maploading" data-at-dl-maploading><span class="at-addr-spinner is-visible" aria-hidden="true"></span> Loading map&hellip;</div>
                </div>
                <div class="at-dl__mapbar">
                    <button type="button" class="at-btn at-btn--secondary at-btn--sm at-focusable" data-at-dl-mapgps><?= at_icon('pin', 'w-4 h-4') ?> <span data-at-dl-mapgps-label>Use my current location</span></button>
                    <p class="at-dl__mappoint" data-at-dl-mappoint>No location selected yet.</p>
                </div>
                <button type="button" class="at-btn at-btn--primary at-btn--block at-focusable" data-at-dl-mapconfirm disabled>Confirm location</button>
            </section>

            <section class="at-dl__step" data-at-dl-step="form" hidden>
                <button type="button" class="at-dl__back at-focusable" data-at-dl-back>&larr; Back</button>
                <p class="at-dl__pinnote" data-at-dl-pinnote hidden><?= at_icon('pin', 'w-4 h-4') ?> Location confirmed on map — complete the address details.</p>
                <form data-at-dl-form novalidate>
                    <input type="hidden" name="latitude" value="">
                    <input type="hidden" name="longitude" value="">
                    <input type="hidden" name="location_confirmed" value="0">

                    <fieldset class="at-dl__types">
                        <legend class="at-label">Address type</legend>
                        <div class="at-dl__type-row" role="radiogroup" aria-label="Address type">
                            <label class="at-dl__type"><input type="radio" name="address_type" value="Home" checked> <span>Home</span></label>
                            <label class="at-dl__type"><input type="radio" name="address_type" value="Work"> <span>Work</span></label>
                            <label class="at-dl__type"><input type="radio" name="address_type" value="Other"> <span>Other</span></label>
                        </div>
                    </fieldset>

                    <div class="at-dl__grid">
                        <div class="at-dl__field">
                            <label class="at-label" for="at-dl-house">Flat / house no.</label>
                            <input id="at-dl-house" class="at-input at-focusable" type="text" name="house_number" maxlength="100" autocomplete="off">
                        </div>
                        <div class="at-dl__field">
                            <label class="at-label" for="at-dl-building">Building / apartment <span class="at-muted">(optional)</span></label>
                            <input id="at-dl-building" class="at-input at-focusable" type="text" name="building_name" maxlength="150" autocomplete="off">
                        </div>
                        <div class="at-dl__field at-dl__field--full">
                            <label class="at-label" for="at-dl-street">Street / road</label>
                            <input id="at-dl-street" class="at-input at-focusable" type="text" name="street" maxlength="150" autocomplete="street-address">
                        </div>
                        <div class="at-dl__field at-dl__field--full">
                            <label class="at-label" for="at-dl-area">Area / locality</label>
                            <input id="at-dl-area" class="at-input at-focusable" type="text" name="area" maxlength="150" required>
                        </div>
                        <div class="at-dl__field at-dl__field--full">
                            <label class="at-label" for="at-dl-landmark">Landmark <span class="at-muted">(optional)</span></label>
                            <input id="at-dl-landmark" class="at-input at-focusable" type="text" name="landmark" maxlength="191">
                        </div>
                        <div class="at-dl__field">
                            <label class="at-label" for="at-dl-city">City</label>
                            <input id="at-dl-city" class="at-input at-focusable" type="text" name="city" maxlength="128" value="Indore" required>
                        </div>
                        <div class="at-dl__field">
                            <label class="at-label" for="at-dl-state">State</label>
                            <input id="at-dl-state" class="at-input at-focusable" type="text" name="state" maxlength="128" value="Madhya Pradesh" required>
                        </div>
                        <div class="at-dl__field">
                            <label class="at-label" for="at-dl-pincode">Pincode</label>
                            <input id="at-dl-pincode" class="at-input at-focusable" type="text" name="pincode" inputmode="numeric" maxlength="6" autocomplete="postal-code" required>
                        </div>
                        <div class="at-dl__field">
                            <label class="at-label" for="at-dl-phone">Mobile <span class="at-muted">(optional)</span></label>
                            <input id="at-dl-phone" class="at-input at-focusable" type="tel" name="receiver_phone" inputmode="tel" maxlength="16" autocomplete="tel">
                        </div>
                        <div class="at-dl__field at-dl__field--full">
                            <label class="at-label" for="at-dl-name">Receiver name <span class="at-muted">(optional)</span></label>
                            <input id="at-dl-name" class="at-input at-focusable" type="text" name="receiver_name" maxlength="100" autocomplete="name">
                        </div>
                    </div>

                    <p class="at-dl__err" data-at-dl-formerr role="alert" hidden></p>
                    <button type="submit" class="at-btn at-btn--primary at-btn--block at-focusable" data-at-dl-save>Confirm &amp; save address</button>
                </form>
            </section>
        </div>
    </div>
    <script type="application/json" id="at-dl-config"><?= json_encode($atDlConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
