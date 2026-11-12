<?php

declare(strict_types=1);

/**
 * Admin assisted order — create pending-payment order.
 */
require_once dirname(__DIR__) . '/_init.php';

AdminAssistedOrderService::denyUnlessCanCreate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

Csrf::requireValid();

$input = AdminAssistedOrderService::collectPostInput($_POST);
$result = AdminAssistedOrderService::placeOrder($input);
if (empty($result['ok'])) {
    Ajax::error((string) ($result['message'] ?? 'Could not create order.'));
}

Ajax::success($result);
