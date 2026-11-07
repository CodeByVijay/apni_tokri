<?php

declare(strict_types=1);

/**
 * CLI smoke test: Places API (New) autocomplete + place details (server-side).
 * Usage: php scripts/test-google-places-v1.php [optional-api-key]
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/google_places.php';

function okG(bool $cond, string $label): void
{
    fwrite(STDOUT, ($cond ? 'OK  ' : 'FAIL') . ' — ' . $label . PHP_EOL);
    if (!$cond) {
        exit(1);
    }
}

$key = $argv[1] ?? null;
if (!is_string($key) || $key === '') {
    $key = AddressAutocompleteSettings::googleApiKey();
}
if ($key === null || $key === '') {
    fwrite(STDERR, "No API key — pass as argv or configure google_places.api_key in admin.\n");
    exit(2);
}

$session = bin2hex(random_bytes(16));
$bias = AddressAutocompleteSettings::biasPoint();

$ac = GooglePlacesGeocoder::autocomplete(
    'Sapna Sangeeta Road Indore',
    $session,
    ['IN'],
    $bias['lat'],
    $bias['lng'],
    5,
    12,
    $key
);

if (empty($ac['ok'])) {
    fwrite(STDERR, 'Autocomplete error: ' . ($ac['error'] ?? 'unknown') . PHP_EOL);
    if (!empty($ac['diagnostic'])) {
        fwrite(STDERR, json_encode($ac['diagnostic'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
    exit(1);
}
okG(!empty($ac['ok']), 'autocomplete HTTP success');
$preds = $ac['predictions'] ?? [];
okG($preds !== [], 'autocomplete returned predictions');
$first = $preds[0];
okG(isset($first['place_id'], $first['description']) && $first['place_id'] !== '', 'prediction has place_id and description');
fwrite(STDOUT, 'Sample: ' . $first['description'] . ' [' . $first['place_id'] . ']' . PHP_EOL);

$det = GooglePlacesGeocoder::placeDetails((string) $first['place_id'], $session, 12, $key);
okG(!empty($det['ok']), 'place details HTTP success');
$norm = GooglePlacesGeocoder::normalizePlaceDetails($det['place'] ?? []);
okG(($norm['latitude'] ?? '') !== '' && ($norm['longitude'] ?? '') !== '', 'normalized coordinates present');
okG(($norm['postcode'] ?? '') !== null || ($norm['formatted_address'] ?? '') !== '', 'normalized address fields present');
fwrite(STDOUT, 'Coords: ' . ($norm['latitude'] ?? '') . ', ' . ($norm['longitude'] ?? '') . PHP_EOL);
fwrite(STDOUT, 'Pincode: ' . (string) ($norm['postcode'] ?? 'n/a') . PHP_EOL);

fwrite(STDOUT, "All Google Places (New) checks passed.\n");
