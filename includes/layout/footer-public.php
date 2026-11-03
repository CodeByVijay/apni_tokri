<?php

declare(strict_types=1);

$base = url_base();
$showBottomNav = $showBottomNav ?? true;
$activeNav = $activeNav ?? '';
?>
<footer class="at-footer mt-auto" role="contentinfo">
    <div class="at-container py-10 md:py-14 grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1.2fr]">
        <div>
            <a href="<?= Security::escape($base) ?>/index.php" class="at-logo !text-white">
                <img src="<?= Security::escape(BrandingSettings::footerLogoUrl()) ?>" alt="<?= Security::escape(BrandingSettings::logoAlt()) ?>" width="44" height="44">
                <span class="at-logo__word !text-white"><?= Security::escape(__('brand_name')) ?></span>
            </a>
            <p class="mt-4 max-w-xs leading-relaxed"><?= Security::escape(__('tagline')) ?> — fresh vegetables, fruits, ready-cut produce and pantry favourites for Indore homes.</p>
            <ul class="mt-5 !grid-flow-col !auto-cols-max gap-4 text-xs text-white/70">
                <li class="flex items-center gap-1.5"><?= at_icon('shield', 'w-4 h-4') ?>Secure payments</li>
                <li class="flex items-center gap-1.5"><?= at_icon('refresh', 'w-4 h-4') ?>Clear refund policy</li>
            </ul>
        </div>
        <div>
            <h3><?= Security::escape(__('footer_about')) ?></h3>
            <ul>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/page.php?slug=about-us">About Us</a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/faqs.php"><?= Security::escape(__('nav_faqs')) ?></a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/contact.php"><?= Security::escape(__('nav_contact')) ?></a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/my-subscriptions.php">Subscriptions</a></li>
            </ul>
        </div>
        <div>
            <h3><?= Security::escape(__('footer_policies')) ?></h3>
            <ul>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/page.php?slug=refund-policy">Refund &amp; cancellation</a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/page.php?slug=shipping-policy">Shipping &amp; delivery</a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/page.php?slug=privacy-policy">Privacy</a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/page.php?slug=terms">Terms</a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/page.php?slug=subscription-terms">Subscription terms</a></li>
            </ul>
        </div>
        <div>
            <h3><?= Security::escape(__('footer_support')) ?></h3>
            <p><?= Security::escape(__('need_help')) ?></p>
            <ul class="mt-3">
                <?php
                $footerPhone = BusinessSettings::supportPhoneDisplay();
                $footerTel = BusinessSettings::supportPhoneTelUrl();
                $footerEmail = BusinessSettings::supportEmail();
                $footerWa = BusinessSettings::whatsappUrl();
                ?>
                <?php if ($footerPhone !== null && $footerTel !== null): ?>
                <li class="flex items-center gap-2"><?= at_icon('phone', 'w-4 h-4 text-[var(--at-yellow)]') ?>
                    <a class="text-white/90 underline at-focusable" href="<?= Security::escape($footerTel) ?>"><?= Security::escape($footerPhone) ?></a>
                </li>
                <?php endif; ?>
                <?php if ($footerEmail !== null): ?>
                <li class="flex items-center gap-2"><?= at_icon('mail', 'w-4 h-4 text-[var(--at-yellow)]') ?>
                    <a class="text-white/90 underline at-focusable" href="mailto:<?= Security::escape($footerEmail) ?>"><?= Security::escape($footerEmail) ?></a>
                </li>
                <?php endif; ?>
                <?php if ($footerWa !== null): ?>
                <li class="flex items-center gap-2"><?= at_icon('chat', 'w-4 h-4 text-[var(--at-yellow)]') ?>
                    <a class="text-white/90 underline at-focusable" href="<?= Security::escape($footerWa) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                </li>
                <?php endif; ?>
                <?php if ($footerPhone === null && $footerEmail === null && $footerWa === null): ?>
                <li class="text-white/70 text-sm">Configure phone, email and WhatsApp in Admin → Settings → Business.</li>
                <?php endif; ?>
            </ul>
            <a href="<?= Security::escape($base) ?>/support.php" class="at-btn at-btn--light at-btn--sm at-focusable mt-4">Raise a ticket</a>
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="at-container py-4 flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between text-xs text-white/60">
            <span>&copy; <?= date('Y') ?> <?= Security::escape(BusinessSettings::legalName()) ?>. All rights reserved.
                <?php $fssai = BusinessSettings::fssaiLicence(); if ($fssai !== null): ?>
                    <span class="block sm:inline sm:ml-2">FSSAI Lic. No. <?= Security::escape($fssai) ?></span>
                <?php endif; ?>
            </span>
            <span>
                <a href="<?= Security::escape($base) ?>/styleguide.php" class="at-focusable">Design system</a>
                <span class="mx-2">·</span>
                <a href="<?= Security::escape($base) ?>/health-check.php" class="at-focusable">Health check</a>
            </span>
        </div>
    </div>
</footer>

<?php if ($showBottomNav): ?>
<nav class="at-bottom-nav md:hidden" aria-label="Mobile shortcuts">
    <div class="at-bottom-nav__inner">
        <a href="<?= Security::escape($base) ?>/index.php" class="at-focusable <?= $activeNav === 'home' ? 'is-active' : '' ?>">
            <?= at_icon('home') ?><?= Security::escape(__('nav_home')) ?>
        </a>
        <a href="<?= Security::escape($base) ?>/category.php" class="at-focusable <?= $activeNav === 'categories' ? 'is-active' : '' ?>">
            <?= at_icon('grid') ?><?= Security::escape(__('nav_categories')) ?>
        </a>
        <a href="<?= Security::escape($base) ?>/cart.php" class="at-focusable <?= $activeNav === 'cart' ? 'is-active' : '' ?>">
            <?= at_icon('cart') ?><?= Security::escape(__('nav_cart')) ?>
        </a>
        <a href="<?= Security::escape($base) ?><?= (class_exists('DemoCustomer') && DemoCustomer::isLoggedIn()) ? '/my-account.php' : '/login.php' ?>" class="at-focusable <?= $activeNav === 'account' ? 'is-active' : '' ?>">
            <?= at_icon('user') ?><?= Security::escape(__('nav_account')) ?>
        </a>
    </div>
</nav>
<?php endif; ?>

<script src="<?= Security::escape(asset_url('assets/js/apni-tokri.js')) ?>"></script>
<script src="<?= Security::escape(asset_url('assets/js/cart.js')) ?>"></script>
<?php
global $loadCatalogueJs;
$loadCatalogueJs = !empty($loadCatalogueJs);
?>
<?php if (!empty($GLOBALS['loadAddressMap'])): ?>
<script src="<?= Security::escape(asset_url('assets/vendor/leaflet/leaflet.js')) ?>"></script>
<script>window.AT_MAP_CONFIG = <?= json_encode(MapConfig::clientConfig(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= Security::escape(asset_url('assets/js/address-map.js')) ?>"></script>
<?php endif; ?>
<?php if ($loadCatalogueJs): ?>
<script src="<?= Security::escape(asset_url('assets/js/catalogue.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
