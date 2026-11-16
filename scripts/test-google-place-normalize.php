<?php

declare(strict_types=1);

/**
 * Fixture tests for GooglePlacesGeocoder::normalizePlaceDetails().
 * Verifies component → form-field mapping for representative Indore places:
 *  - street_number → house_number (no extraction from formatted_address)
 *  - premise/subpremise/POI → building_name
 *  - route → street; sublocality/neighborhood → area; locality → city
 *  - administrative_area_level_1 → state; postal_code → postcode
 *  - location → latitude/longitude
 * Run: php scripts/test-google-place-normalize.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okPlace(bool $cond, string $msg): void
{
    global $fail;
    if (!$cond) {
        $fail++;
        echo "FAIL  $msg\n";
    } else {
        echo " OK   $msg\n";
    }
}

/** @param list<array{0:string,1:list<string>,2?:string}> $comps */
function place(array $comps, string $name = '', array $types = [], string $formatted = '', ?float $lat = 22.7, ?float $lng = 75.8): array
{
    $c = [];
    foreach ($comps as $comp) {
        [$text, $ct] = $comp;
        $short = $comp[2] ?? $text;
        $c[] = ['longText' => $text, 'shortText' => $short, 'types' => $ct];
    }

    return [
        'id' => 'ChIJtest' . substr(md5($formatted . $name), 0, 6),
        'displayName' => ['text' => $name],
        'types' => $types,
        'formattedAddress' => $formatted,
        'location' => ['latitude' => $lat, 'longitude' => $lng],
        'addressComponents' => $c,
    ];
}

$indore = [
    ['Indore', ['locality'], 'Indore'],
    ['Indore', ['administrative_area_level_2'], 'Indore'],
    ['Madhya Pradesh', ['administrative_area_level_1'], 'MP'],
    ['India', ['country'], 'IN'],
];

// 1. DAVV Takshila Parisar — university POI, no street_number
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['DAVV Takshila Parisar', ['premise']],
    ['Khandwa Road', ['route']],
    ['Takshila Parisar', ['sublocality_level_1', 'sublocality']],
], $indore, [['452001', ['postal_code']]]),
    'DAVV Takshila Parisar', ['university', 'point_of_interest', 'establishment'],
    'DAVV Takshila Parisar, Khandwa Rd, Indore, Madhya Pradesh 452001'));
okPlace($r['building_name'] === 'DAVV Takshila Parisar', 'DAVV: premise → building_name');
okPlace($r['street'] === 'Khandwa Road', 'DAVV: route → street');
okPlace($r['area'] === 'Takshila Parisar', 'DAVV: sublocality_level_1 → area');
okPlace($r['city'] === 'Indore' && $r['state'] === 'Madhya Pradesh', 'DAVV: city/state');
okPlace($r['postcode'] === '452001', 'DAVV: postcode');
okPlace($r['house_number'] === null, 'DAVV: no street_number → house_number stays empty (not invented)');

// 2. Bhawarkua Square — square/neighborhood POI, no route, no postal code
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['Bhawarkua', ['sublocality_level_1', 'sublocality']],
    ['Bhawarkua Square', ['neighborhood']],
], $indore),
    'Bhawarkua Square', ['point_of_interest', 'establishment'],
    'Bhawarkua Square, Indore, Madhya Pradesh'));
okPlace($r['area'] === 'Bhawarkua', 'Bhawarkua: sublocality_level_1 preferred for area');
okPlace($r['street'] === null, 'Bhawarkua: no route → street empty');
okPlace($r['house_number'] === null, 'Bhawarkua: no street_number → house empty');
okPlace($r['postcode'] === null, 'Bhawarkua: no postal_code → pincode empty');
okPlace($r['city'] === 'Indore' && $r['state'] === 'Madhya Pradesh', 'Bhawarkua: city/state');
okPlace($r['latitude'] !== null && $r['longitude'] !== null, 'Bhawarkua: coordinates present');

// 3. "77, Bajrang Nagar Road" — street_number IS returned as a component
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['77', ['street_number']],
    ['Bajrang Nagar Road', ['route']],
    ['Bajrang Nagar', ['sublocality_level_1', 'sublocality']],
], $indore, [['452011', ['postal_code']]]),
    '77, Bajrang Nagar Road', ['street_address'],
    '77, Bajrang Nagar Rd, Bajrang Nagar, Indore, Madhya Pradesh 452011'));
okPlace($r['house_number'] === '77', 'Bajrang Nagar: street_number "77" → house_number');
okPlace($r['street'] === 'Bajrang Nagar Road', 'Bajrang Nagar: route → street');
okPlace($r['area'] === 'Bajrang Nagar', 'Bajrang Nagar: sublocality → area');
okPlace($r['postcode'] === '452011', 'Bajrang Nagar: postcode');

// 3b. Same formatted address but NO street_number component — must NOT extract "77"
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['Bajrang Nagar Road', ['route']],
    ['Bajrang Nagar', ['sublocality_level_1', 'sublocality']],
], $indore, [['452011', ['postal_code']]]),
    '77, Bajrang Nagar Road', ['street_address'],
    '77, Bajrang Nagar Rd, Bajrang Nagar, Indore, Madhya Pradesh 452011'));
okPlace($r['house_number'] === null, 'Bajrang Nagar w/o component: house_number NOT extracted from formatted_address');

// 4. Residential society — premise + subpremise (flat)
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['Sunview Apartments', ['premise']],
    ['Flat 302', ['subpremise']],
    ['MR-10 Road', ['route']],
    ['Sukhlia', ['sublocality_level_1', 'sublocality']],
], $indore, [['452010', ['postal_code']]]),
    'Sunview Apartments', ['premise'],
    'Flat 302, Sunview Apartments, MR-10 Rd, Sukhlia, Indore, Madhya Pradesh 452010'));
okPlace($r['building_name'] === 'Sunview Apartments', 'Society: premise → building_name');
okPlace($r['house_number'] === null, 'Society: subpremise is not a street_number → house stays empty for customer to fill');

// 4b. subpremise used for building when no premise exists
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['Tower B, Flat 1204', ['subpremise']],
    ['Vijay Nagar Main Road', ['route']],
    ['Vijay Nagar', ['sublocality_level_1', 'sublocality']],
], $indore, [['452010', ['postal_code']]]),
    'Tower B', ['subpremise'],
    'Tower B, Vijay Nagar, Indore, Madhya Pradesh 452010'));
okPlace($r['building_name'] === 'Tower B, Flat 1204', 'Subpremise: used as building when no premise');

// 5. Independent house — street_number + route
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['12-B', ['street_number']],
    ['Palhar Nagar Road', ['route']],
    ['Palhar Nagar', ['sublocality_level_1', 'sublocality']],
], $indore, [['452014', ['postal_code']]]),
    '12-B, Palhar Nagar Road', ['street_address'],
    '12-B, Palhar Nagar Rd, Indore, Madhya Pradesh 452014'));
okPlace($r['house_number'] === '12-B', 'House: street_number → house_number');
okPlace($r['building_name'] === null, 'House: no premise/POI → building stays empty');

// 6. Street only — no street_number (route-level result)
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['MG Road', ['route']],
    ['South Tukoganj', ['sublocality_level_1', 'sublocality']],
], $indore, [['452001', ['postal_code']]]),
    'MG Road', ['route'],
    'MG Rd, South Tukoganj, Indore, Madhya Pradesh 452001'));
okPlace($r['street'] === 'MG Road' && $r['house_number'] === null, 'Street-only: street filled, house empty (customer must fill)');

// 7. No postal code in result
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['Rau Circle', ['point_of_interest']],
    ['Agra Bombay Road', ['route']],
    ['Rau', ['sublocality_level_1', 'sublocality']],
], $indore),
    'Rau Circle', ['point_of_interest'],
    'Rau Cir, AB Rd, Rau, Indore, Madhya Pradesh'));
okPlace($r['postcode'] === null, 'No postcode: pincode left empty');
okPlace($r['city'] === 'Indore', 'No postcode: city still resolved');

// 8. No route — landmark inside a locality
$r = GooglePlacesGeocoder::normalizePlaceDetails(place(array_merge([
    ['Rajwada Palace', ['premise', 'point_of_interest']],
    ['Rajwada', ['sublocality_level_1', 'sublocality']],
], $indore, [['452002', ['postal_code']]]),
    'Rajwada Palace', ['premise', 'point_of_interest', 'establishment'],
    'Rajwada Palace, Rajwada, Indore, Madhya Pradesh 452002'));
okPlace($r['street'] === null, 'No route: street left empty');
okPlace($r['building_name'] === 'Rajwada Palace', 'No route: premise → building_name');
okPlace($r['area'] === 'Rajwada', 'No route: sublocality → area');

// 9. Coordinates round-trip
$r = GooglePlacesGeocoder::normalizePlaceDetails(place([['Indore', ['locality']]], 'Indore', ['locality'], 'Indore, MP', 22.7195687, 75.8577258));
okPlace($r['latitude'] === '22.7195687' && $r['longitude'] === '75.8577258', 'Coordinates: lat/lng preserved');

echo "\n" . ($fail === 0 ? "ALL OK" : "$fail FAILURES") . "\n";
exit($fail === 0 ? 0 : 1);
