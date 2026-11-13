<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/address_autocomplete_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$q = isset($_POST['q']) && is_string($_POST['q']) ? $_POST['q'] : '';
$sessionToken = isset($_POST['session_token']) && is_string($_POST['session_token']) ? $_POST['session_token'] : null;
if ($sessionToken !== null && strlen($sessionToken) > 128) {
    $sessionToken = null;
}
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

// Optional per-request bias (e.g. GPS-detected point): invalid or absent falls back to the admin bias.
$biasLat = null;
$biasLng = null;
$biasRadiusM = null;
$latRaw = isset($_POST['latitude']) && is_string($_POST['latitude']) ? $_POST['latitude'] : '';
$lngRaw = isset($_POST['longitude']) && is_string($_POST['longitude']) ? $_POST['longitude'] : '';
$coords = AddressInput::parseCoordinates($latRaw, $lngRaw);
if ($coords['error'] === null && $coords['lat'] !== null && $coords['lng'] !== null) {
    $biasLat = $coords['lat'];
    $biasLng = $coords['lng'];
    $r = isset($_POST['radius_m']) && is_numeric($_POST['radius_m']) ? (int) $_POST['radius_m'] : 0;
    $biasRadiusM = ($r >= 500 && $r <= 25000) ? $r : null;
}

$res = AddressAutocompleteService::search($q, $ip, $sessionToken, $biasLat, $biasLng, $biasRadiusM);
if (empty($res['ok'])) {
    $msg = (string) ($res['error'] ?? 'Search failed.');
    $code = (int) ($res['code'] ?? 0);
    if ($code === 429) {
        Ajax::error($msg, 429);
    }
    Ajax::error($msg, 400);
}

Ajax::success([
    'provider' => $res['provider'] ?? AddressAutocompleteSettings::provider(),
    'suggestions' => $res['suggestions'] ?? [],
], '');
