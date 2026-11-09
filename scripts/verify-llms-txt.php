<?php

declare(strict_types=1);

/**
 * Verify llms.txt structure, canonical domain, and (when DB is ready) indexability of linked URLs.
 *
 * CLI: php scripts/verify-llms-txt.php [--live]
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$liveCheck = in_array('--live', $argv ?? [], true);

$path = LlmsTxt::staticRootPath();
$raw = is_file($path) ? (string) file_get_contents($path) : '';
$fail = 0;
function llms_ok(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . PHP_EOL;
    if (!$c) {
        $fail++;
    }
}

llms_ok(is_file($path), 'llms.txt exists at web root');
$siteName = BrandingSettings::siteName();
llms_ok(str_starts_with(trim($raw), '# ' . $siteName), 'starts with H1 ' . $siteName);
llms_ok(mb_check_encoding($raw, 'UTF-8'), 'valid UTF-8');
llms_ok(str_contains($raw, '## Optional'), 'contains Optional section');
$sitemapUrl = SeoRobots::sitemapAbsoluteUrl();
$defaultSitemap = rtrim(BusinessSettings::DEFAULT_WEBSITE_URL, '/') . '/sitemap.xml';
llms_ok(str_contains($raw, $sitemapUrl) || str_contains($raw, $defaultSitemap), 'contains XML sitemap URL');

preg_match_all('/\]\((https:\/\/[^)]+)\)/', $raw, $m);
$urls = $m[1] ?? [];
llms_ok($urls !== [], 'contains HTTPS markdown links');
llms_ok(count($urls) === count(array_unique($urls)), 'no duplicate link URLs');

$canonicalBase = rtrim(SeoUrl::publicSiteBaseUrl(), '/');
$defaultBase = rtrim(BusinessSettings::DEFAULT_WEBSITE_URL, '/');
$linkHosts = [];
foreach ($urls as $u) {
    $h = parse_url($u, PHP_URL_HOST);
    if (is_string($h) && $h !== '') {
        $linkHosts[$h] = true;
    }
}
llms_ok(count($linkHosts) === 1, 'all links use one canonical host');
$fileHost = $linkHosts !== [] ? array_key_first($linkHosts) : '';
$resolverHost = parse_url($canonicalBase, PHP_URL_HOST);
$defaultHost = parse_url($defaultBase, PHP_URL_HOST);
$hostOk = $fileHost === $resolverHost
    || ($fileHost === $defaultHost && is_string($resolverHost) && $resolverHost === 'localhost');
llms_ok($hostOk, 'link host matches resolver (' . $fileHost . ' vs ' . ($resolverHost ?? '') . ')');
$expectedPrefix = $fileHost === $resolverHost ? $canonicalBase : $defaultBase;

foreach ($urls as $u) {
    $lower = strtolower($u);
    if ($expectedPrefix !== '') {
        llms_ok(str_starts_with($u, $expectedPrefix), 'canonical host: ' . $u);
    } else {
        llms_ok(str_starts_with($u, 'https://'), 'HTTPS link: ' . $u);
    }
    llms_ok(!str_contains($lower, '/admin'), 'not admin: ' . $u);
    llms_ok(!str_contains($lower, 'login.php'), 'not login: ' . $u);
    llms_ok(!str_contains($lower, 'cart.php'), 'not cart: ' . $u);
    llms_ok(!str_contains($lower, 'checkout.php'), 'not checkout: ' . $u);
    llms_ok(!str_contains($lower, 'subscribe.php'), 'not subscribe checkout: ' . $u);
}

if (Install::isSchemaReady()) {
    $indexableProducts = [];
    foreach (LlmsTxt::collectIndexableProducts() as $p) {
        $indexableProducts[(string) $p['url']] = true;
    }
    $indexableCats = [];
    foreach (LlmsTxt::collectIndexableCategories() as $c) {
        $indexableCats[(string) $c['url']] = true;
    }
    foreach ($urls as $u) {
        if (str_contains($u, 'product.php?slug=')) {
            llms_ok(isset($indexableProducts[SeoUrl::normalizeSitemapLoc($u)]), 'product URL is indexable: ' . $u);
        }
        if (str_contains($u, 'category.php?slug=')) {
            llms_ok(isset($indexableCats[SeoUrl::normalizeSitemapLoc($u)]), 'category URL is indexable: ' . $u);
        }
    }
    $sitemapLocs = [];
    foreach (SeoSitemap::collectIndexableUrls() as $entry) {
        $sitemapLocs[SeoUrl::normalizeSitemapLoc((string) ($entry['loc'] ?? ''))] = true;
    }
    foreach ($urls as $u) {
        if (str_contains($u, 'product.php?slug=') || str_contains($u, 'category.php?slug=') || str_contains($u, 'page.php?slug=')) {
            $norm = SeoUrl::normalizeSitemapLoc($u);
            if (isset($sitemapLocs[$norm])) {
                llms_ok(true, 'catalogue URL in sitemap: ' . $u);
            }
        }
    }
} else {
    echo "SKIP: DB not ready — indexability cross-check skipped\n";
}

if ($liveCheck) {
    $liveBase = rtrim(BusinessSettings::DEFAULT_WEBSITE_URL, '/');
    $liveUrls = [$liveBase . '/llms.txt', $liveBase . '/sitemap.xml'];
    foreach ($liveUrls as $live) {
        $ch = curl_init($live);
        if ($ch === false) {
            llms_ok(false, 'curl init: ' . $live);
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        llms_ok($code === 200, 'HTTP ' . $code . ' for ' . $live);
    }
}

exit($fail === 0 ? 0 : 1);
