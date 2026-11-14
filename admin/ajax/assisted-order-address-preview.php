<?php

declare(strict_types=1);

/**
 * Admin assisted order — delivery serviceability/zone-fee preview for an address.
 * POST pincode, optional latitude, longitude. Read-only; no persistence.
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

$pin = isset($_POST['pincode']) && is_string($_POST['pincode']) ? $_POST['pincode'] : '';
$latRaw = isset($_POST['latitude']) && is_string($_POST['latitude']) ? $_POST['latitude'] : '';
$lngRaw = isset($_POST['longitude']) && is_string($_POST['longitude']) ? $_POST['longitude'] : '';
$coords = AddressInput::parseCoordinates($latRaw, $lngRaw);
if ($coords['error'] !== null) {
    Ajax::error((string) $coords['error']);
}

Ajax::success(
    AdminAssistedOrderService::previewAddressServiceability($pin, $coords['lat'], $coords['lng'])
);
