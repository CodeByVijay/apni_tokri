<?php

declare(strict_types=1);

/**
 * Admin assisted order — validate variant/qty for line item (no order persistence).
 * POST variant_id, quantity, customer_id (optional)
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

$variantId = isset($_POST['variant_id']) ? (int) $_POST['variant_id'] : 0;
$quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
$customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
$customerId = $customerId > 0 ? $customerId : null;

$result = AdminAssistedOrderService::validateLineItem($variantId, $quantity, $customerId);
if (empty($result['ok'])) {
    Ajax::error((string) ($result['message'] ?? 'Invalid line item.'));
}

Ajax::success(['line' => $result['line'] ?? []]);
