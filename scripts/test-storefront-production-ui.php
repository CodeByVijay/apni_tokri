<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$orig = App::config();
$restore = static function () use ($orig): void {
    App::setConfig($orig);
    CatalogueGateway::reset();
};

$withProduction = function (callable $fn) use ($orig, $restore): void {
    App::setConfig(array_replace_recursive($orig, ['app' => ['env' => 'production', 'debug' => false]]));
    CatalogueGateway::reset();
    try {
        $fn();
    } finally {
        $restore();
    }
};

$withProduction(function (): void {
    ok(!AppEnvironment::allowsDemoCatalogueFallback(), 'Production disables demo catalogue fallback');
    ok(!StorefrontDesignPreview::allowsLoadingPreview(), 'Production blocks demo=loading preview');
    $catSrc = (string) file_get_contents(dirname(__DIR__) . '/category.php');
    ok(strpos($catSrc, 'sample catalogue') === false || strpos($catSrc, 'storefront_uses_demo_catalogue') !== false, 'Category sample label gated to demo catalogue');
    ok(strpos($catSrc, 'StorefrontDesignPreview::allowsLoadingPreview') !== false, 'Preview link gated');
    $banner = (string) file_get_contents(dirname(__DIR__) . '/includes/partials/demo-banner.php');
    ok(strpos($banner, 'Store not live') === false, 'Production banner uses coming-soon copy');
    ok(strpos($banner, 'Products coming soon') !== false, 'Production banner mentions coming soon');
});

App::setConfig(array_replace_recursive($orig, ['app' => ['env' => 'local', 'debug' => true]]));
CatalogueGateway::reset();
ok(AppEnvironment::allowsDemoCatalogueFallback() || Install::isSchemaReady(), 'Local dev demo fallback policy unchanged');
ok(StorefrontDesignPreview::allowsLoadingPreview(), 'Local dev allows loading preview');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    $published = ProductRepository::publishedPublicProductCount();
    ok($published === count(ProductRepository::publishedProducts()), 'publishedPublicProductCount matches publishedProducts list');
}

$restore();

echo $fail === 0 ? "All storefront production UI tests passed.\n" : "Failures: {$fail}\n";
exit($fail === 0 ? 0 : 1);
