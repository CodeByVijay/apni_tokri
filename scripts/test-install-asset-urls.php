<?php

declare(strict_types=1);

/**
 * Fresh install (no config.local.php): asset and link URLs must follow the HTTP request, not config.example.php.
 * CLI: php7.3 scripts/test-install-asset-urls.php
 */
if (!defined('APNI_TOKRI_ROOT')) {
    define('APNI_TOKRI_ROOT', dirname(__DIR__));
}
require_once dirname(__DIR__) . '/includes/constants.php';
require_once dirname(__DIR__) . '/includes/compat/php73.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/install.php';
require_once dirname(__DIR__) . '/includes/install/wizard_service.php';

$fail = 0;

function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$example = require dirname(__DIR__) . '/includes/config.example.php';
ok(str_contains((string) ($example['app']['url_path'] ?? ''), 'Apni'), 'config.example still documents subfolder default (unchanged)');

$_SERVER['SCRIPT_NAME'] = '/install.php';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'apnitokri.in';
$_SERVER['SERVER_PORT'] = '443';

ok(InstallWizardService::requestDerivedUrlPath() === '', 'root install: empty url_path from SCRIPT_NAME');
ok(
    InstallWizardService::requestDerivedBaseUrl('') === 'https://apnitokri.in',
    'root install: HTTPS base_url from request'
);

if (!Install::hasLocalConfig()) {
    $merged = InstallWizardService::withRequestDerivedAppUrls($example);
    ok(($merged['app']['url_path'] ?? 'x') === '', 'withRequestDerivedAppUrls: empty url_path without local config');
    ok(($merged['app']['base_url'] ?? '') === 'https://apnitokri.in', 'withRequestDerivedAppUrls: base_url from request');

    App::setConfig($merged);
    $css = asset_url('assets/css/apni-tokri.css');
    ok(str_starts_with($css, '/assets/css/apni-tokri.css'), 'asset_url has no bogus /Apni prefix: ' . $css);
} else {
    echo "SKIP: merge/asset_url integration (config.local.php present on this machine)\n";
}
ok(!str_contains(InstallWizardService::requestDerivedBaseUrl(''), 'localhost'), 'derived base_url does not use localhost');
ok(!str_contains(InstallWizardService::requestDerivedBaseUrl(''), 'manconnect'), 'derived base_url has no old domain');

$_SERVER['SCRIPT_NAME'] = '/shop/install.php';
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SERVER_PORT'] = '80';
ok(InstallWizardService::requestDerivedUrlPath() === '/shop', 'subdir install: url_path from SCRIPT_NAME');
ok(
    InstallWizardService::requestDerivedBaseUrl('/shop') === 'http://example.test/shop',
    'subdir install: base_url'
);

if (!Install::hasLocalConfig()) {
    App::setConfig(InstallWizardService::withRequestDerivedAppUrls($example));
    ok(str_starts_with(asset_url('assets/images/logo.svg'), '/shop/assets/'), 'subdir asset_url prefixed once');
}

if (Install::hasLocalConfig()) {
    $withLocal = InstallWizardService::withRequestDerivedAppUrls($example);
    ok(
        ($withLocal['app']['url_path'] ?? '') === ($example['app']['url_path'] ?? ''),
        'withRequestDerivedAppUrls is no-op when config.local.php exists'
    );
}

echo $fail === 0 ? "All install asset URL checks passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
