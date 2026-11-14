<?php

declare(strict_types=1);

$base = url_base();
$showBottomNav = $showBottomNav ?? ($GLOBALS['atShowBottomNav'] ?? true);
$activeNav = $activeNav ?? ($GLOBALS['atBottomNavActive'] ?? '');
$homeMobileChrome = !empty($GLOBALS['atHomeMobileChrome']);
if ($activeNav === '' && $homeMobileChrome) {
    $activeNav = 'home';
}
$cartCount = isset($cartCount) ? (int) $cartCount : DemoCart::itemCount();
$cmsFooterLinks = CmsPageService::footerLinksForPublic();
$cmsAboutFooterLink = null;
$cmsPolicyFooterLinks = [];
foreach ($cmsFooterLinks as $cmsFooterLink) {
    if (($cmsFooterLink['slug'] ?? '') === 'about-us') {
        $cmsAboutFooterLink = $cmsFooterLink;
        continue;
    }
    $cmsPolicyFooterLinks[] = $cmsFooterLink;
}
?>
<footer class="at-footer mt-auto<?= $showBottomNav ? ' at-footer--with-bottom-nav' : '' ?>" role="contentinfo">
    <div class="at-container at-footer__grid">
        <div class="at-footer__brand">
            <a href="<?= Security::escape($base) ?>/index.php" class="at-logo !text-white">
                <img src="<?= Security::escape(BrandingSettings::footerLogoUrl()) ?>" alt="<?= Security::escape(BrandingSettings::logoAlt()) ?>" width="44" height="44">
                <span class="at-logo__word !text-white"><?= Security::escape(__('brand_name')) ?></span>
            </a>
            <?php $footerBrandDesc = BrandingSettings::footerBrandDescription(); ?>
            <?php if ($footerBrandDesc !== null): ?>
            <p class="at-footer__desc max-w-xs leading-relaxed"><?= Security::escape($footerBrandDesc) ?></p>
            <?php endif; ?>
            <?php $footerSocialLinks = BrandingSocialLinks::visibleFooterLinks(); ?>
            <?php if ($footerSocialLinks !== []): ?>
            <nav class="at-footer-social" aria-label="Social media">
                <?php foreach ($footerSocialLinks as $socialLink): ?>
                <a href="<?= Security::escape($socialLink['url']) ?>" class="at-footer-social__link at-focusable"
                   target="_blank" rel="noopener noreferrer" aria-label="<?= Security::escape($socialLink['label']) ?>">
                    <?= at_social_icon($socialLink['id'], 'at-footer-social__icon') ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <ul class="at-footer__trust !grid-flow-col !auto-cols-max gap-4 text-xs text-white/70">
                <li class="flex items-center gap-1.5"><?= at_icon('shield', 'w-4 h-4') ?>Secure payments</li>
                <li class="flex items-center gap-1.5"><?= at_icon('refresh', 'w-4 h-4') ?>Clear refund policy</li>
            </ul>
        </div>
        <div class="at-footer__about">
            <h3><?= Security::escape(__('footer_about')) ?></h3>
            <ul>
                <?php if ($cmsAboutFooterLink !== null): ?>
                <li><a class="at-focusable" href="<?= Security::escape((string) $cmsAboutFooterLink['href']) ?>"><?= Security::escape((string) $cmsAboutFooterLink['title']) ?></a></li>
                <?php endif; ?>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/faqs.php"><?= Security::escape(__('nav_faqs')) ?></a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/contact.php"><?= Security::escape(__('nav_contact')) ?></a></li>
                <li><a class="at-focusable" href="<?= Security::escape($base) ?>/my-subscriptions.php">Subscriptions</a></li>
            </ul>
        </div>
        <div class="at-footer__policies">
            <h3><?= Security::escape(__('footer_policies')) ?></h3>
            <ul>
                <?php foreach ($cmsPolicyFooterLinks as $cmsPolicyLink): ?>
                <li><a class="at-focusable" href="<?= Security::escape((string) $cmsPolicyLink['href']) ?>"><?= Security::escape((string) $cmsPolicyLink['title']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="at-footer__support">
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
            <a href="<?= Security::escape($base) ?>/support.php" class="at-btn at-btn--light at-btn--sm at-focusable at-footer__ticket">Raise a ticket</a>
            <?php $footerAppBadges = BrandingAppStores::visibleFooterBadges(); ?>
            <?php if ($footerAppBadges !== []): ?>
            <section class="at-footer-apps" aria-labelledby="at-footer-apps-heading">
                <h3 id="at-footer-apps-heading">Download Our App</h3>
                <p class="at-footer-apps__desc">Get fresh vegetables and daily essentials delivered to your doorstep.</p>
                <div class="at-footer-apps__badges">
                    <?php foreach ($footerAppBadges as $storeBadge): ?>
                    <a href="<?= Security::escape($storeBadge['url']) ?>"
                       class="at-footer-apps__badge at-focusable"
                       target="_blank" rel="noopener noreferrer"
                       aria-label="<?= Security::escape($storeBadge['badge_alt']) ?>">
                        <img src="<?= Security::escape($storeBadge['badge_src']) ?>"
                             alt="<?= Security::escape($storeBadge['badge_alt']) ?>"
                             width="155" height="60" loading="lazy" decoding="async"
                             class="at-footer-apps__badge-img">
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="at-container at-footer__legal-inner flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between text-xs text-white/60">
            <span>&copy; <?= date('Y') ?> <?= Security::escape(BusinessSettings::legalName()) ?>. All rights reserved.
                <?php
                $fssai = BusinessSettings::fssaiLicence();
                $udyam = BusinessSettings::udyamPublicDisplay();
                $registrationLine = [];
                if ($fssai !== null) {
                    $registrationLine[] = 'FSSAI Lic. No.: ' . Security::escape($fssai);
                }
                if ($udyam !== null) {
                    $registrationLine[] = 'Udyam Registration No.: ' . Security::escape($udyam['number']);
                }
                if ($registrationLine !== []): ?>
                    <span class="block sm:inline sm:ml-2"><?= implode(' | ', $registrationLine) ?></span>
                <?php endif; ?>
            </span>
            <?php if (AppEnvironment::allowsStorefrontDevelopmentLinks()): ?>
            <span>
                <a href="<?= Security::escape($base) ?>/styleguide.php" class="at-focusable">Design system</a>
                <span class="mx-2">·</span>
                <a href="<?= Security::escape($base) ?>/health-check.php" class="at-focusable">Health check</a>
            </span>
            <?php endif; ?>
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
            <span data-at-cart-count class="at-cart-badge <?= $cartCount > 0 ? '' : 'hidden' ?>"><?= (int) $cartCount ?></span>
        </a>
        <a href="<?= Security::escape($base) ?><?= CustomerService::isLoggedIn() ? '/my-account.php' : '/login.php' ?>" class="at-focusable <?= $activeNav === 'account' ? 'is-active' : '' ?>">
            <?= at_icon('user') ?><?= Security::escape(__('nav_account')) ?>
        </a>
    </div>
</nav>
<?php endif; ?>

<script defer src="<?= Security::escape(asset_url('assets/js/apni-tokri.js')) ?>"></script>
<script defer src="<?= Security::escape(asset_url('assets/js/offer-countdown.js')) ?>"></script>
<script defer src="<?= Security::escape(asset_url('assets/js/cart.js')) ?>"></script>
<?php
global $loadCatalogueJs;
$loadCatalogueJs = !empty($loadCatalogueJs);
?>
<?php if (!empty($GLOBALS['loadAddressMap'])): ?>
<script defer src="<?= Security::escape(asset_url('assets/vendor/leaflet/leaflet.js')) ?>"></script>
<script defer <?= Security::cspNonceAttr() ?>>window.AT_MAP_CONFIG = <?= json_encode(MapConfig::clientConfig(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="<?= Security::escape(asset_url('assets/js/address-map.js')) ?>"></script>
<?php endif; ?>
<?php if (!empty($GLOBALS['loadAddressAutocompleteJs'])): ?>
<script defer <?= Security::cspNonceAttr() ?>>window.AT_ADDRESS_AUTOCOMPLETE = <?= json_encode(AddressAutocompleteSettings::publicClientConfig(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="<?= Security::escape(asset_url('assets/js/address-autocomplete.js')) ?>"></script>
<?php endif; ?>
<?php if ($loadCatalogueJs): ?>
<script defer src="<?= Security::escape(asset_url('assets/js/catalogue.js')) ?>"></script>
<?php endif; ?>
<?php
$loadPushJs = !empty($loadPushJs) && FirebaseProjectManager::publicWebConfig() !== null;
if ($loadPushJs):
    ?><script <?= Security::cspNonceAttr() ?>>window.AT_BASE = <?= json_encode(url_base(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script type="module" src="<?= Security::escape(asset_url('assets/js/push-notifications.mjs')) ?>"></script>
<?php endif; ?>
</body>
</html>
