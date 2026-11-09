<?php

declare(strict_types=1);

/**
 * Admin cache headers, refresh UI, and upload asset versioning (static + light runtime).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okHc(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$init = file_get_contents(APNI_TOKRI_ROOT . '/admin/_init.php') ?: '';
okHc(strpos($init, 'sendSecurityHeaders(Security::isHttps(), true)') !== false, 'admin _init sends no-store security headers');
okHc(strpos($init, 'AdminHttpCache::sendNoStoreHeaders()') !== false, 'admin _init sends explicit no-store headers');

$layout = file_get_contents(APNI_TOKRI_ROOT . '/includes/layout/admin.php') ?: '';
okHc(strpos($layout, 'at-admin-refresh') !== false, 'admin header refresh button');
okHc(strpos($layout, 'refresh-cw') === false && strpos($layout, "'refresh'") !== false, 'refresh uses refresh icon');

$adminJs = file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js') ?: '';
okHc(strpos($adminJs, 'initAdminRefresh') !== false, 'admin.js refresh handler');
okHc(strpos($adminJs, 'location.replace') !== false, 'refresh uses replace (no POST resubmit)');

$ajax = file_get_contents(APNI_TOKRI_ROOT . '/includes/ajax.php') ?: '';
okHc(strpos($ajax, 'must-revalidate') !== false, 'Ajax JSON no-cache headers');

$indexPhp = file_get_contents(APNI_TOKRI_ROOT . '/index.php') ?: '';
okHc(
    strpos($indexPhp, 'sendSecurityHeaders(Security::isHttps(), true)') === false,
    'storefront index does not force no-store'
);

$helpers = file_get_contents(APNI_TOKRI_ROOT . '/includes/helpers.php') ?: '';
okHc(strpos($helpers, 'uploads/') !== false, 'asset_url versions uploads');

$testUpload = 'uploads/products/_cache_test_' . bin2hex(random_bytes(2)) . '.png';
$abs = APNI_TOKRI_ROOT . '/' . $testUpload;
@mkdir(dirname($abs), 0755, true);
file_put_contents($abs, 'x');
touch($abs, 1_700_000_000);
clearstatcache(true, $abs);
$u1 = asset_url($testUpload);
touch($abs, 1_700_000_100);
clearstatcache(true, $abs);
$u2 = asset_url($testUpload);
okHc($u1 !== $u2 && str_contains($u1, '?v=1700000000'), 'upload asset_url changes when file mtime changes');
@unlink($abs);

$css = asset_url('assets/css/apni-tokri.css');
okHc(str_contains($css, '?v=') || str_contains($css, '&v='), 'CSS asset_url has version param');

okHc(is_readable(APNI_TOKRI_ROOT . '/admin/settings/cache.php'), 'settings cache page exists');
$cachePage = file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/cache.php') ?: '';
okHc(strpos($cachePage, 'does not clear browsers') !== false, 'cache page clarifies browser vs server');

$url = AdminHttpCache::buildGetUrlFromQuery(['q' => 'tomato', 'page' => '2', '_at_r' => '123']);
okHc(strpos($url, 'q=tomato') !== false && strpos($url, 'page=2') !== false, 'refresh URL keeps filters');
okHc(strpos($url, '_at_r=') === false, 'refresh URL strips _at_r');

$sw = glob(APNI_TOKRI_ROOT . '/**/service-worker*.js');
okHc($sw === [], 'no service worker in project');

echo "\nTotal failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
