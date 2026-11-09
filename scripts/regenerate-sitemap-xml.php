<?php

declare(strict_types=1);

/**
 * Regenerate physical sitemap.xml (and sitemap-N.xml parts) in the web document root.
 *
 * CLI: php scripts/regenerate-sitemap-xml.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
$result = SeoSitemap::writeStaticXmlFiles();
if (empty($result['ok'])) {
    fwrite(STDERR, 'FAIL: ' . ($result['message'] ?? 'Unknown error') . "\n");
    exit(1);
}

$path = (string) ($result['path'] ?? SeoSitemap::staticRootPath());
$urlCount = (int) ($result['url_count'] ?? 0);
$parts = (int) ($result['parts'] ?? 1);
$files = $result['files'] ?? [$path];

echo "OK: Wrote sitemap ({$urlCount} URL(s), {$parts} part(s))\n";
echo "Main file: {$path}\n";
foreach ($files as $f) {
    echo "  - {$f} (" . (is_file($f) ? (string) filesize($f) : '0') . " bytes)\n";
}

$xml = is_file($path) ? (string) file_get_contents($path) : '';
$parsed = $xml !== '' ? @simplexml_load_string($xml) : false;
if ($parsed === false) {
    fwrite(STDERR, "FAIL: {$path} is not valid XML\n");
    exit(1);
}

$name = $parsed->getName();
$validRoot = in_array($name, ['urlset', 'sitemapindex'], true);
if (!$validRoot) {
    fwrite(STDERR, "FAIL: unexpected XML root <{$name}>\n");
    exit(1);
}

echo "OK: XML validation passed (root <{$name}>)\n";
exit(0);
