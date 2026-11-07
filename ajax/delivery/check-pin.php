<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$pin = isset($_POST['pincode']) && is_string($_POST['pincode']) ? $_POST['pincode'] : '';
$latRaw = isset($_POST['latitude']) && is_string($_POST['latitude']) ? $_POST['latitude'] : '';
$lngRaw = isset($_POST['longitude']) && is_string($_POST['longitude']) ? $_POST['longitude'] : '';
$coords = AddressInput::parseCoordinates($latRaw, $lngRaw);
if ($coords['error'] !== null) {
    Ajax::error($coords['error']);
}
$result = DeliveryContext::forPin($pin, $coords['lat'], $coords['lng']);
if ($result['serviceable']) {
    DeliveryContext::setPin($result['pin']);
}

$requiresMap = !empty($result['requires_map_pin']);
$customerMessage = $result['serviceable']
    ? (string) $result['message']
    : DeliveryCustomerMessages::pinCheckUnavailable();
$data = [
    'serviceable' => $result['serviceable'],
    'requires_map_pin' => $requiresMap,
    'message' => $customerMessage,
    'message_detail' => null,
    'zone' => $result['zone']['name'] ?? null,
    'totals' => CartService::itemCount() > 0 ? CartService::totals() : null,
];

Ajax::success($data, $customerMessage);
