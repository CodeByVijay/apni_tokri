<?php

declare(strict_types=1);

/**
 * Static HTML preview of delivery test result panels (for visual QA / screenshots).
 * Run: php7.3 scripts/qa-delivery-test-panel-preview.php
 * Open: storage/qa/delivery-test-panel-preview.html
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Install::ensureConnection();
$cssPath = parse_url(App::baseUrl(), PHP_URL_PATH);
$css = rtrim(is_string($cssPath) ? $cssPath : '/Apni_Tokri', '/') . '/assets/css/apni-tokri.css';

$longName = 'Indore Central — Premium Same-Day Corridor (4520xx extended service area with long descriptive title)';
$tests = [
    'available_with_amount' => array_merge(
        DeliveryAdminUi::testCalculation('452010', '', '', '350'),
        ['zone_name' => $longName, 'method' => 'Pincode match', 'eta' => 'Within 2 hours', 'raw' => ['preview' => true]]
    ),
    'available_free' => array_merge(
        DeliveryAdminUi::testCalculation('452010', '', '', '650'),
        ['zone_name' => $longName, 'method' => 'Nearby-area backup (address is close enough to your store)', 'eta' => 'Same day', 'raw' => ['preview' => true]]
    ),
    'unavailable' => DeliveryAdminUi::testCalculation('110001', '', '', ''),
];

$sections = '';
foreach ($tests as $key => $test) {
    ob_start();
    require dirname(__DIR__) . '/includes/partials/admin/delivery-test-result.php';
    $sections .= '<section class="preview-block" id="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '"><h2>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</h2>' . ob_get_clean() . '</section>';
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delivery test result preview</title>
<link rel="stylesheet" href="{$css}">
<style>
body { font-family: system-ui, sans-serif; margin: 0; padding: 1rem; background: #f5f5f0; }
.preview-wrap { max-width: 420px; margin: 0 auto; }
.preview-block { margin-bottom: 2rem; }
.preview-block h2 { font-size: 0.75rem; text-transform: uppercase; color: #666; margin: 0 0 0.5rem; }
@media (min-width: 900px) { .preview-wrap { max-width: 520px; } }
</style>
</head>
<body>
<div class="preview-wrap">
<p class="text-sm at-muted">Simulates the right column width of Delivery areas (test calculator).</p>
{$sections}
</div>
</body>
</html>
HTML;

$dir = dirname(__DIR__) . '/storage/qa';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$out = $dir . '/delivery-test-panel-preview.html';
file_put_contents($out, $html);
echo "Wrote {$out}\n";
