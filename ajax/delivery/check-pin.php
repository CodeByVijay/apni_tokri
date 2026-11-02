<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$pin = isset($_POST['pincode']) && is_string($_POST['pincode']) ? $_POST['pincode'] : '';
$result = DeliveryContext::forPin($pin);
if ($result['serviceable']) {
    DeliveryContext::setPin($result['pin']);
}

Ajax::success([
    'serviceable' => $result['serviceable'],
    'message' => $result['message'],
    'zone' => $result['zone']['name'] ?? null,
    'totals' => CartService::itemCount() > 0 ? CartService::totals() : null,
], $result['message']);
