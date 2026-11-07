<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var SeoMetadata $seo */
$locale = Lang::locale();
?>
<!DOCTYPE html>
<html lang="<?= Security::escape($locale === 'hi' ? 'hi' : 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= Security::escape(Csrf::token()) ?>">
    <link rel="icon" href="<?= Security::escape(BrandingSettings::faviconUrl()) ?>" sizes="any">
    <title><?= Security::escape($seo->documentTitle) ?></title>
    <?php
    $gscMeta = SeoAdminCenter::gscVerificationMeta();
    if ($gscMeta !== null): ?>
    <meta name="google-site-verification" content="<?= Security::escape($gscMeta) ?>">
    <?php endif; ?>
    <?= $seo->renderHeadTags() ?>
    <?php
    $gtmId = SeoAdminCenter::gtmEnabled() ? SeoAdminCenter::gtmContainerId() : null;
    $ga4Id = $gtmId === null ? SeoAdminCenter::ga4MeasurementId() : null;
    if ($gtmId !== null): ?>
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',<?= json_encode($gtmId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
    <?php elseif ($ga4Id !== null): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= Security::escape($ga4Id) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', <?= json_encode($ga4Id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    </script>
    <?php endif; ?>
    <?php if (isset($jsonLd) && $jsonLd instanceof SeoJsonLd && !$jsonLd->isEmpty()): ?>
    <?= $jsonLd->renderScriptTag() ?>
    <?php endif; ?>
    <?php
    $preloadLcpImage = $preloadLcpImage ?? null;
    if (is_string($preloadLcpImage) && $preloadLcpImage !== ''): ?>
    <link rel="preload" as="image" href="<?= Security::escape($preloadLcpImage) ?>" fetchpriority="high">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <?php
    $googleFontsHref = 'https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500;1,9..144,600&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap';
    ?>
    <link rel="preload" as="style" href="<?= Security::escape($googleFontsHref) ?>" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="<?= Security::escape($googleFontsHref) ?>"></noscript>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            green: { DEFAULT: '#245C3A', 50: '#F0F6F2', 100: '#DFECE4', 600: '#2D7048', 700: '#245C3A', 800: '#1B4A2E', 900: '#133421' },
                            yellow: { DEFAULT: '#F5A623', 100: '#FDEBC8', 600: '#D98A0B', 800: '#7A4A00' },
                            cream: { DEFAULT: '#FFF8E9', 2: '#F9EFD7' },
                            charcoal: '#292D29',
                        }
                    },
                    fontFamily: {
                        display: ['Fraunces', 'Georgia', 'serif'],
                    },
                    maxWidth: { '8xl': '80rem' }
                }
            }
        };
    </script>
    <link rel="stylesheet" href="<?= Security::escape(asset_url('assets/css/apni-tokri.css')) ?>">
    <?php $GLOBALS['loadAddressMap'] = !empty($loadAddressMap); ?>
    <?php $GLOBALS['loadAddressAutocompleteJs'] = !empty($loadAddressAutocompleteJs); ?>
    <?php if (!empty($loadAddressMap)): ?>
    <link rel="stylesheet" href="<?= Security::escape(asset_url('assets/vendor/leaflet/leaflet.css')) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
</head>
<body class="at-body bg-brand-cream text-brand-charcoal min-h-screen flex flex-col" data-locale="<?= Security::escape($locale) ?>">
