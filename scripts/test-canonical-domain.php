<?php

declare(strict_types=1);

/**
 * Canonical domain resolution (Website URL vs app.base_url).
 * CLI: php scripts/test-canonical-domain.php
 * Disposable: php scripts/run-in-disposable-db.php scripts/test-canonical-domain.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/test_script_safety.php';

$fail = 0;
function okDom(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$origConfig = App::config();
$restore = static function () use ($origConfig): void {
    App::setConfig($origConfig);
    CatalogueGateway::reset();
};

App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => 'https://veggietokri.in',
        'url_path' => '',
        'env' => 'production',
    ],
]));
CatalogueGateway::reset();

$home = SeoUrl::homepageCanonicalUrl();
$product = SeoUrl::absolute('/product.php?slug=demo');
$sitemap = SeoRobots::sitemapAbsoluteUrl();
$rewritten = SeoUrl::validatePublicUrl('https://veggietokri.in/page.php?slug=privacy-policy');

okDom(str_contains($home, 'veggietokri.com'), 'homepage uses .com when app.base_url is .in');
okDom(!str_contains($home, 'veggietokri.in'), 'homepage does not use .in');
okDom(str_contains($product, 'veggietokri.com'), 'product canonical uses .com');
okDom(str_contains($sitemap, 'veggietokri.com/sitemap.xml'), 'robots sitemap directive uses .com');
okDom($rewritten !== null && str_contains($rewritten, 'veggietokri.com'), 'legacy .in stored canonical rewrites to .com');

$xml = SeoSitemap::renderForRequest(null, true);
okDom(!str_contains(strtolower($xml), 'veggietokri.in'), 'dynamic sitemap XML has no .in');
okDom(str_contains($xml, 'veggietokri.com'), 'dynamic sitemap XML uses .com');

App::setConfig(array_replace_recursive($origConfig, [
    'app' => [
        'base_url' => 'http://localhost/Apni_Tokri',
        'url_path' => '/Apni_Tokri',
        'env' => 'local',
    ],
]));
CatalogueGateway::reset();
$localHome = SeoUrl::homepageCanonicalUrl();
okDom(str_contains($localHome, 'localhost'), 'local dev keeps localhost base');

if (getenv('APNI_TOKRI_DISPOSABLE_DB') === '1') {
    TestScriptSafety::assertDisposableDatabaseOrAbort();
    App::setConfig(array_replace_recursive(App::config(), [
        'app' => [
            'base_url' => 'https://veggietokri.in',
            'url_path' => '',
            'env' => 'production',
        ],
    ]));
    AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, 'https://veggietokri.com');
    SeoSitemap::invalidateResponseCache();
    $phys = SeoSitemap::writeStaticXmlFiles();
    okDom(!empty($phys['ok']), 'physical sitemap generation');
    $body = is_file(SeoSitemap::staticRootPath()) ? (string) file_get_contents(SeoSitemap::staticRootPath()) : '';
    okDom($body !== '' && !str_contains($body, 'veggietokri.in'), 'physical sitemap.xml has no .in');
    okDom(@simplexml_load_string($body) !== false, 'physical sitemap.xml valid XML');
    AppSettings::set(BusinessSettings::KEY_WEBSITE_URL, null);
}

$restore();

echo $fail === 0 ? "All canonical domain checks passed.\n" : "{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
