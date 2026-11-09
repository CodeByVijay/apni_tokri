<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$part = isset($_GET['part']) ? (int) $_GET['part'] : null;
if ($part !== null && $part < 1) {
    $part = null;
}

header('Content-Type: ' . SeoSitemap::CONTENT_TYPE);
header('Cache-Control: public, max-age=' . SeoSitemap::CACHE_TTL_SECONDS);
header('X-Robots-Tag: noindex');
echo SeoSitemap::renderForRequest($part);
