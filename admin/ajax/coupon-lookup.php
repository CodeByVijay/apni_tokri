<?php

declare(strict_types=1);

/**
 * Admin coupon form lookups: product / customer search, unique code generation.
 * GET ?type=products|customers|code&q=...
 */
require_once dirname(__DIR__) . '/_init.php';

if (!Permission::canModule('coupons')) {
    Ajax::error('Forbidden.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '';

if ($type === 'products') {
    Ajax::success(['items' => CouponAdminService::searchProducts($q, 20)]);
}
if ($type === 'customers') {
    Ajax::success(['items' => CouponAdminService::searchCustomers($q, 20)]);
}
if ($type === 'code') {
    $prefix = isset($_GET['prefix']) && is_string($_GET['prefix']) ? $_GET['prefix'] : CouponAdminService::GENERATED_CODE_PREFIX;
    try {
        Ajax::success(['code' => CouponAdminService::generateUniqueCode($prefix)]);
    } catch (Throwable $e) {
        Ajax::error('Could not generate a code. Try again.', 500);
    }
}

Ajax::error('Invalid request.');
