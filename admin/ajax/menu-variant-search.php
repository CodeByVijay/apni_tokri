<?php

declare(strict_types=1);

/**
 * Menu Planner vegetable search (product name, SKU or variant name).
 * GET ?q=...
 */
require_once dirname(__DIR__) . '/_init.php';

if (!Permission::canModule('subscriptions')) {
    Ajax::error('Forbidden.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 100) : '';
Ajax::success(['items' => SubscriptionMenuPlanService::searchVariants($q, 20)]);
