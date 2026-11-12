<?php

declare(strict_types=1);

/**
 * Admin assisted order — create a saved address for the selected customer.
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

$customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
if ($customerId <= 0) {
    Ajax::error('Customer is required.');
}

$result = AdminAssistedOrderService::createAddressForCustomer($customerId, $_POST);
if (empty($result['ok'])) {
    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
    Ajax::error((string) ($result['message'] ?? 'Could not save address.'), 422, 'validation', $errors !== [] ? $errors : null);
}

Ajax::success([
    'address' => $result['address'] ?? null,
    'reused' => !empty($result['reused']),
    'customer_id' => $customerId,
]);
