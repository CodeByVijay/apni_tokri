<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}

$name = isset($_GET['name']) ? (string) $_GET['name'] : '';
Ajax::success(['slug' => CatalogueSlug::fromName($name)]);
