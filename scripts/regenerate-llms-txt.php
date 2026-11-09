<?php

declare(strict_types=1);

/**
 * Regenerate llms.txt in the web document root from the live catalogue.
 *
 * CLI: php scripts/regenerate-llms-txt.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$result = LlmsTxt::writeStaticFile();
if (empty($result['ok'])) {
    fwrite(STDERR, 'FAIL: ' . ($result['message'] ?? 'Unknown error') . "\n");
    exit(1);
}

$path = (string) ($result['path'] ?? LlmsTxt::staticRootPath());
$bytes = (int) ($result['bytes'] ?? 0);
echo "OK: Wrote llms.txt ({$bytes} bytes)\n";
echo "Path: {$path}\n";

$counts = [
    'categories' => count(LlmsTxt::collectIndexableCategories()),
    'products' => count(LlmsTxt::collectIndexableProducts()),
    'cms' => count(LlmsTxt::collectIndexableCmsPages()),
];
echo 'Indexable entries: '
    . $counts['products'] . ' product(s), '
    . $counts['categories'] . ' categor(ies), '
    . $counts['cms'] . ' CMS page(s)' . "\n";

exit(0);
