<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: ' . SeoRobots::CONTENT_TYPE);
header('X-Robots-Tag: noindex');
echo SeoRobots::render();
