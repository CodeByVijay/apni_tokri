<?php

declare(strict_types=1);

/**
 * Admin assisted order — saved addresses for a customer.
 * GET ?customer_id=
 */
require_once dirname(__DIR__) . '/_init.php';

AdminAssistedOrderService::denyUnlessCanCreate();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
if ($customerId <= 0) {
    Ajax::error('Customer is required.');
}

$result = AdminAssistedOrderService::addressesForCustomer($customerId);
if (empty($result['ok'])) {
    Ajax::error((string) ($result['message'] ?? 'Could not load addresses.'));
}

Ajax::success(['items' => $result['items'] ?? [], 'customer_id' => $customerId]);
