<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$slug = isset($_POST['product_slug']) && is_string($_POST['product_slug']) ? trim($_POST['product_slug']) : '';
if ($slug === '') {
    Ajax::error('Product is required.');
}

Ajax::success([
    'variants_in_cart' => DemoCart::variantIdsForProduct($slug),
]);
