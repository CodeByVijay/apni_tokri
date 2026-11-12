<?php

declare(strict_types=1);

/**
 * Admin assisted order — customer search (name, mobile, email) with pagination.
 * GET ?q=&page=1&per_page=20
 */
require_once dirname(__DIR__) . '/_init.php';

AdminAssistedOrderService::denyUnlessCanCreate();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : AdminAssistedOrderService::CUSTOMER_PER_PAGE;

if (mb_strlen($q) < AdminAssistedOrderService::MIN_SEARCH_LEN) {
    Ajax::success([
        'items' => [],
        'page' => 1,
        'per_page' => max(1, min(50, $perPage)),
        'total' => 0,
        'total_pages' => 1,
        'min_query_length' => AdminAssistedOrderService::MIN_SEARCH_LEN,
    ]);
}

$result = AdminAssistedOrderService::searchCustomers($q, $page, $perPage);
Ajax::success(array_merge($result, ['min_query_length' => AdminAssistedOrderService::MIN_SEARCH_LEN]));
