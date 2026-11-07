<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function ok(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

$css = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/css/apni-tokri.css');
ok(str_contains($css, '.sr-only'), 'sr-only in main stylesheet');

$head = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/head-public.php');
ok(str_contains($head, 'preloadLcpImage'), 'LCP preload hook in head');
ok(str_contains($head, "as=\"style\"") && str_contains($head, 'onload='), 'non-blocking Google Fonts');
ok(str_contains($head, 'jquery-3.7.1'), 'jQuery remains in head for inline scripts');

$footer = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/footer-public.php');
ok(preg_match('/defer src=.*apni-tokri\.js/', $footer) === 1, 'apni-tokri.js deferred');
ok(preg_match('/defer src=.*cart\.js/', $footer) === 1, 'cart.js deferred');

$header = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/header-public.php');
ok(str_contains($header, 'at-suggest-m'), 'mobile suggest listbox id');
ok(str_contains($header, 'type="submit" class="sr-only">Search</button>'), 'mobile search submit');

$index = (string) file_get_contents(APNI_TOKRI_ROOT . '/index.php');
ok(str_contains($index, 'preloadLcpImage'), 'homepage passes LCP preload');

$ht = (string) file_get_contents(APNI_TOKRI_ROOT . '/.htaccess');
ok(str_contains($ht, 'Cache-Control'), 'static asset cache headers');

echo "\nPASS: storefront head / performance hooks.\n";
