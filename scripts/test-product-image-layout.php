<?php

declare(strict_types=1);

/**
 * Storefront product image layout (gallery, thumbnails, cards).
 * CLI: php scripts/test-product-image-layout.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
$gallery = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/product-gallery.php');
$card = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/product-card.php');

$ok(str_contains($css, '.at-img-fit'), 'static: reusable at-img-fit class');
$ok((bool) preg_match('/\.at-gallery__stage\s*\{[^}]*aspect-ratio:\s*4\s*\/\s*3/s', $css), 'static: gallery stage 4:3 aspect ratio');
$ok((bool) preg_match('/\.at-gallery__stage\s*\{[^}]*display:\s*flex/s', $css), 'static: gallery stage flex centering');
$ok(str_contains($css, 'object-fit: contain') && str_contains($css, '.at-gallery-thumb img'), 'static: thumb images use contain');
$ok((bool) preg_match('/\.at-product-card__media\s+img\s*\{[^}]*object-fit:\s*contain/s', $css), 'static: product card images use contain');
$ok((bool) preg_match('/\.at-product-card__media\s*\{[^}]*aspect-ratio:\s*1\s*\/\s*1/s', $css), 'static: product card 1:1 frame');
$ok(str_contains($gallery, 'at-img-fit'), 'static: gallery partial uses at-img-fit');
$ok(str_contains($gallery, 'width="640" height="480"'), 'static: main gallery CLS hint 4:3');
$ok(!str_contains($gallery, 'width="640" height="640"'), 'static: gallery no longer square CLS hint');

exit($fail > 0 ? 1 : 0);
