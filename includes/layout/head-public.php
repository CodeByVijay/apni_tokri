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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= Security::escape(Csrf::token()) ?>">
    <link rel="icon" href="<?= Security::escape(BrandingSettings::faviconUrl()) ?>" sizes="any">
    <title><?= Security::escape($seo->documentTitle) ?></title>
    <?= $seo->renderHeadTags() ?>
    <?php if (isset($jsonLd) && $jsonLd instanceof SeoJsonLd && !$jsonLd->isEmpty()): ?>
    <?= $jsonLd->renderScriptTag() ?>
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700;1,9..144,500;1,9..144,600&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
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
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
</head>
<body class="at-body bg-brand-cream text-brand-charcoal min-h-screen flex flex-col" data-locale="<?= Security::escape($locale) ?>">
