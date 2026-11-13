<?php

declare(strict_types=1);

require_once __DIR__ . '/icons.php';

/**
 * Public layout helpers.
 *
 * @param array<string, mixed> $vars pageTitle (required unless seo set), activeNav, cartCount, metaDescription, showBottomNav, seo (SeoMetadata)
 */
function layout_public_start(array $vars): void
{
    if (!isset($vars['seo']) && (empty($vars['pageTitle']) || !is_string($vars['pageTitle']))) {
        throw new InvalidArgumentException('pageTitle is required');
    }
    if (isset($vars['seo']) && $vars['seo'] instanceof SeoMetadata) {
        $vars['pageTitle'] = $vars['seo']->documentTitle;
    }
    extract($vars, EXTR_SKIP);
    $GLOBALS['atHomeMobileChrome'] = !empty($vars['homeMobileChrome']);
    if (!isset($seo) || !$seo instanceof SeoMetadata) {
        $seo = SeoMetadata::fromLegacy(
            (string) ($pageTitle ?? 'Veggie Tokri'),
            isset($metaDescription) && is_string($metaDescription) ? $metaDescription : null
        );
    }
    if (!isset($cartCount)) {
        $cartCount = DemoCart::itemCount();
    }
    if (!isset($loadAddressMap) && DeliveryPolicies::isDistanceHybridEnabled()) {
        $loadAddressMap = true;
    }
    require APNI_TOKRI_ROOT . '/includes/layout/head-public.php';
    require APNI_TOKRI_ROOT . '/includes/layout/header-public.php';
    $mainClass = 'at-main--with-bottom-nav flex-1';
    if (!($showBottomNav ?? true)) {
        $mainClass = 'flex-1';
    }
    echo '<main id="main-content" class="' . Security::escape($mainClass) . '">';
}

function layout_public_end(): void
{
    echo '</main>';
    require APNI_TOKRI_ROOT . '/includes/layout/footer-public.php';
}
