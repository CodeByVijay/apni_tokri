<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/nominatim_reverse.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$latRaw = isset($_POST['latitude']) && is_string($_POST['latitude']) ? $_POST['latitude'] : '';
$lngRaw = isset($_POST['longitude']) && is_string($_POST['longitude']) ? $_POST['longitude'] : '';
$coords = AddressInput::parseCoordinates($latRaw, $lngRaw);
if ($coords['error'] !== null) {
    Ajax::error($coords['error']);
}
if ($coords['lat'] === null || $coords['lng'] === null) {
    Ajax::error('Latitude and longitude are required.');
}

$res = NominatimReverseGeocoder::reverse($coords['lat'], $coords['lng']);
if (empty($res['ok'])) {
    Ajax::error((string) ($res['error'] ?? 'Could not look up address.'));
}

unset($res['ok']);
/** @var array<string, string> $clean */
$clean = array_filter($res, static fn ($v) => is_string($v) && $v !== '');
Ajax::success(['address' => $clean], '');
