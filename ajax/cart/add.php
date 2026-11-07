<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/catalogue/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$slug = isset($_POST['product_slug']) && is_string($_POST['product_slug']) ? trim($_POST['product_slug']) : '';
$variantId = isset($_POST['variant_id']) && is_string($_POST['variant_id']) ? trim($_POST['variant_id']) : '';
$qty = isset($_POST['qty']) ? (int) $_POST['qty'] : 1;

$result = DemoCart::add($slug, $variantId, $qty);
if (!$result['ok']) {
    Ajax::error($result['message']);
}

Ajax::success([
    'count' => DemoCart::itemCount(),
    'totals' => DemoCart::totals(),
    'variants_in_cart' => DemoCart::variantIdsForProduct($slug),
], $result['message']);
