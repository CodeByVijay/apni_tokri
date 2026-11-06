<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/address_autocomplete_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$placeId = isset($_POST['place_id']) && is_string($_POST['place_id']) ? trim($_POST['place_id']) : '';
$sessionToken = isset($_POST['session_token']) && is_string($_POST['session_token']) ? $_POST['session_token'] : null;
if ($sessionToken !== null && strlen($sessionToken) > 128) {
    $sessionToken = null;
}

$res = AddressAutocompleteService::googlePlaceDetails($placeId, $sessionToken);
if (empty($res['ok'])) {
    Ajax::error((string) ($res['error'] ?? 'Could not load place.'), 400);
}

Ajax::success(['address' => $res['address'] ?? []], '');
