<?php

declare(strict_types=1);

/**
 * Google Places API (New) — server-side only. Autocomplete + Place Details.
 * Key from AppSettings encrypted secret — never sent to the browser.
 *
 * @see https://developers.google.com/maps/documentation/places/web-service/place-autocomplete
 * @see https://developers.google.com/maps/documentation/places/web-service/place-details
 */
final class GooglePlacesGeocoder
{
    private const AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    private const PLACE_BASE_URL = 'https://places.googleapis.com/v1/places/';
    private const AUTOCOMPLETE_FIELD_MASK = 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text';
    private const PLACE_DETAILS_FIELD_MASK = 'id,displayName,types,formattedAddress,addressComponents,location';

    /**
     * @param list<string> $countries ISO 3166-1 alpha-2 uppercase
     * @return array{ok: bool, error?: string, predictions?: list<array<string, mixed>>, diagnostic?: array<string, mixed>}
     */
    public static function autocomplete(
        string $input,
        ?string $sessionToken,
        array $countries,
        float $biasLat,
        float $biasLng,
        int $limit,
        int $timeoutSeconds,
        ?string $apiKey
    ): array {
        $key = $apiKey !== null && $apiKey !== '' ? $apiKey : AddressAutocompleteSettings::googleApiKey();
        if ($key === null || $key === '') {
            return ['ok' => false, 'error' => 'Google Places API key is not configured.'];
        }
        $input = trim($input);
        if (mb_strlen($input) < 3) {
            return ['ok' => false, 'error' => 'Type at least 3 characters to search.'];
        }
        if (mb_strlen($input) > 200) {
            $input = mb_substr($input, 0, 200);
        }

        $body = [
            'input' => $input,
            'languageCode' => 'en-IN',
            'locationBias' => [
                'circle' => [
                    'center' => [
                        'latitude' => $biasLat,
                        'longitude' => $biasLng,
                    ],
                    'radius' => 25000.0,
                ],
            ],
        ];
        if ($countries !== []) {
            $regions = [];
            foreach ($countries as $c) {
                $c = strtoupper(trim($c));
                if (preg_match('/^[A-Z]{2}$/', $c)) {
                    $regions[] = strtolower($c);
                }
            }
            if ($regions !== []) {
                $body['includedRegionCodes'] = array_slice(array_values(array_unique($regions)), 0, 15);
            }
        }
        if ($sessionToken !== null && $sessionToken !== '' && strlen($sessionToken) <= 128) {
            $body['sessionToken'] = $sessionToken;
        }

        $res = self::requestJson(
            'POST',
            self::AUTOCOMPLETE_URL,
            $key,
            $body,
            self::AUTOCOMPLETE_FIELD_MASK,
            $timeoutSeconds,
            'place_autocomplete'
        );
        if (empty($res['ok'])) {
            return $res;
        }
        $json = $res['json'] ?? [];
        $predictions = self::predictionsFromAutocompleteNew(is_array($json) ? $json : []);

        return [
            'ok' => true,
            'predictions' => array_slice($predictions, 0, max(1, $limit)),
            'diagnostic' => $res['diagnostic'] ?? null,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, place?: array<string, mixed>, diagnostic?: array<string, mixed>}
     */
    public static function placeDetails(string $placeId, ?string $sessionToken, int $timeoutSeconds, ?string $apiKey): array
    {
        $key = $apiKey !== null && $apiKey !== '' ? $apiKey : AddressAutocompleteSettings::googleApiKey();
        if ($key === null || $key === '') {
            return ['ok' => false, 'error' => 'Google Places API key is not configured.'];
        }
        $placeId = trim($placeId);
        if ($placeId === '') {
            return ['ok' => false, 'error' => 'Place id is required.'];
        }
        if (str_starts_with($placeId, 'places/')) {
            $placeId = substr($placeId, strlen('places/'));
        }

        $url = self::PLACE_BASE_URL . rawurlencode($placeId);
        if ($sessionToken !== null && $sessionToken !== '' && strlen($sessionToken) <= 128) {
            $url .= '?' . http_build_query(['sessionToken' => $sessionToken], '', '&', PHP_QUERY_RFC3986);
        }

        $res = self::requestJson(
            'GET',
            $url,
            $key,
            null,
            self::PLACE_DETAILS_FIELD_MASK,
            $timeoutSeconds,
            'place_details'
        );
        if (empty($res['ok'])) {
            return $res;
        }
        $json = $res['json'] ?? [];

        return [
            'ok' => true,
            'place' => is_array($json) ? $json : [],
            'diagnostic' => $res['diagnostic'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $json Autocomplete (New) response
     * @return list<array{place_id: string, description: string}>
     */
    private static function predictionsFromAutocompleteNew(array $json): array
    {
        $suggestions = isset($json['suggestions']) && is_array($json['suggestions']) ? $json['suggestions'] : [];
        $out = [];
        foreach ($suggestions as $row) {
            if (!is_array($row) || !isset($row['placePrediction']) || !is_array($row['placePrediction'])) {
                continue;
            }
            $pred = $row['placePrediction'];
            $pid = '';
            if (isset($pred['placeId']) && is_string($pred['placeId']) && $pred['placeId'] !== '') {
                $pid = $pred['placeId'];
            } elseif (isset($pred['place']) && is_string($pred['place']) && $pred['place'] !== '') {
                $pid = str_starts_with($pred['place'], 'places/') ? substr($pred['place'], 7) : $pred['place'];
            }
            $desc = '';
            if (isset($pred['text']) && is_array($pred['text']) && isset($pred['text']['text']) && is_string($pred['text']['text'])) {
                $desc = trim($pred['text']['text']);
            }
            if ($pid === '' || $desc === '') {
                continue;
            }
            $out[] = [
                'place_id' => $pid,
                'description' => $desc,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   json?: array<string, mixed>,
     *   diagnostic?: array<string, mixed>
     * }
     */
    private static function requestJson(
        string $method,
        string $url,
        string $apiKey,
        ?array $body,
        string $fieldMask,
        int $timeoutSeconds,
        string $operation
    ): array {
        $base = BusinessSettings::websiteUrl();
        $ua = 'VeggieTokriGooglePlaces/2.0 (+' . $base . ')';
        $endpointSafe = self::safeEndpointForLog($url);
        $provider = 'google_places_v1';

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $apiKey,
            'User-Agent: ' . $ua,
        ];
        if ($fieldMask !== '') {
            $headers[] = 'X-Goog-FieldMask: ' . $fieldMask;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, 0, '', '', 'Could not initialise HTTP client.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Could not reach Google Places.', 'diagnostic' => $diag];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(3, min(25, $timeoutSeconds)),
            CURLOPT_CONNECTTIMEOUT => min(8, max(3, $timeoutSeconds)),
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($method === 'POST' && $body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $rawBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($rawBody) || $rawBody === '') {
            $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, $status, '', '', 'Empty response body.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Empty response from Google Places.', 'diagnostic' => $diag];
        }
        try {
            $json = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, $status, '', '', 'JSON decode failed.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Invalid response from Google Places.', 'diagnostic' => $diag];
        }
        if (!is_array($json)) {
            $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, $status, '', '', 'Response is not a JSON object.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Invalid response from Google Places.', 'diagnostic' => $diag];
        }

        if ($status < 200 || $status >= 300) {
            [$gStatus, $gErrRaw] = self::extractGoogleError($json);
            $gErrSan = self::sanitizeGoogleErrorMessage($gErrRaw);
            $token = self::classifyGoogleError($gStatus, $gErrSan);
            $msg = self::formatAdminFacingError($gStatus, $gErrSan, $token !== '' ? $token : 'UNKNOWN');
            $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, $status, $gStatus, $gErrSan, $token !== '' ? $token : null);
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => $msg, 'diagnostic' => $diag];
        }

        if (isset($json['error']) && is_array($json['error'])) {
            [$gStatus, $gErrRaw] = self::extractGoogleError($json);
            $gErrSan = self::sanitizeGoogleErrorMessage($gErrRaw);
            $token = self::classifyGoogleError($gStatus, $gErrSan);
            $msg = self::formatAdminFacingError($gStatus, $gErrSan, $token !== '' ? $token : 'UNKNOWN');
            $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, $status, $gStatus, $gErrSan, $token);
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => $msg, 'diagnostic' => $diag];
        }

        $diag = self::baseDiagnostic($provider, $operation, $endpointSafe, $status, 'OK', '', null);
        self::logPlacesDiagnostic($diag, true);

        return ['ok' => true, 'json' => $json, 'diagnostic' => $diag];
    }

    /**
     * @param array<string, mixed> $json
     * @return array{0: string, 1: string}
     */
    private static function extractGoogleError(array $json): array
    {
        if (isset($json['error']) && is_array($json['error'])) {
            $err = $json['error'];
            $status = isset($err['status']) && is_string($err['status']) ? $err['status'] : '';
            $message = isset($err['message']) && is_string($err['message']) ? trim($err['message']) : '';
            if ($status === '' && isset($err['code'])) {
                $status = 'HTTP_' . (string) $err['code'];
            }

            return [$status, $message];
        }
        $status = isset($json['status']) && is_string($json['status']) ? (string) $json['status'] : '';
        $message = isset($json['error_message']) && is_string($json['error_message']) ? trim($json['error_message']) : '';

        return [$status, $message];
    }

    private static function safeEndpointForLog(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p)) {
            return 'places_v1';
        }
        $scheme = isset($p['scheme']) && is_string($p['scheme']) ? $p['scheme'] : 'https';
        $host = isset($p['host']) && is_string($p['host']) ? $p['host'] : '';
        $path = isset($p['path']) && is_string($p['path']) ? $p['path'] : '';

        return ($host !== '' ? $scheme . '://' . $host : '') . $path;
    }

    /**
     * @return array<string, string|int>
     */
    private static function baseDiagnostic(
        string $provider,
        string $operation,
        string $endpoint,
        int $httpStatus,
        string $googleStatus,
        string $googleErrorMessage,
        ?string $classToken
    ): array {
        $out = [
            'provider' => $provider,
            'operation' => $operation,
            'endpoint' => $endpoint,
            'http_status' => $httpStatus,
            'google_status' => $googleStatus,
            'google_error_message' => $googleErrorMessage,
        ];
        if ($classToken !== null && $classToken !== '') {
            $out['class'] = $classToken;
        }

        return $out;
    }

    /**
     * @param array<string, string|int> $diag
     */
    private static function logPlacesDiagnostic(array $diag, bool $success): void
    {
        $payload = $diag + ['outcome' => $success ? 'ok' : 'error'];
        $line = 'GooglePlacesGeocoder ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log($line);
    }

    private static function sanitizeGoogleErrorMessage(string $msg): string
    {
        if ($msg === '') {
            return '';
        }
        $msg = preg_replace('/\bkey=[^&\s]+/iu', 'key=[redacted]', $msg) ?? $msg;

        return mb_substr($msg, 0, 500);
    }

    private static function classifyGoogleError(string $status, string $errorMessage): string
    {
        $m = mb_strtolower($errorMessage);
        if ($status === 'OVER_QUERY_LIMIT' || str_contains($m, 'quota')) {
            return 'QUOTA_EXCEEDED';
        }
        if ($status === 'REQUEST_DENIED' || $status === 'PERMISSION_DENIED') {
            if (str_contains($m, 'legacyapinotactivated') || str_contains($m, 'legacy api')) {
                return 'API_NOT_ENABLED';
            }
            if (str_contains($m, 'permission denied') || str_contains($m, 'permission_denied')) {
                return 'PERMISSION_DENIED';
            }
            if (str_contains($m, 'api key is invalid') || str_contains($m, 'invalid api key')) {
                return 'API_KEY_INVALID';
            }
            if (str_contains($m, 'this api project is not authorized to use this api')
                || str_contains($m, 'has not been used') || str_contains($m, 'is disabled')
                || str_contains($m, 'service is disabled')) {
                return 'API_NOT_ENABLED';
            }
            if (str_contains($m, 'billing') || str_contains($m, 'billingenabled')) {
                return 'BILLING_NOT_ENABLED';
            }

            return 'REQUEST_DENIED';
        }
        if ($status === 'INVALID_REQUEST' || $status === 'INVALID_ARGUMENT') {
            return 'INVALID_REQUEST';
        }

        return $status !== '' ? $status : 'UNKNOWN';
    }

    private static function formatAdminFacingError(string $status, string $errorMessage, string $classToken): string
    {
        $parts = [];
        if ($classToken !== '') {
            $parts[] = $classToken;
        } elseif ($status !== '') {
            $parts[] = $status;
        }
        if ($errorMessage !== '') {
            $parts[] = $errorMessage;
        }

        return implode(' — ', array_filter($parts, static fn ($x) => $x !== '')) ?: 'Google Places request failed.';
    }

    /**
     * @param array<string, mixed> $result Place Details result (New or legacy shape)
     * @return array<string, string|null>
     */
    public static function normalizePlaceDetails(array $result): array
    {
        if (isset($result['addressComponents']) && is_array($result['addressComponents'])) {
            $result = self::legacyShapeFromNewPlace($result);
        }

        $lat = null;
        $lng = null;
        $geom = $result['geometry'] ?? null;
        if (is_array($geom) && isset($geom['location']) && is_array($geom['location'])) {
            $lat = isset($geom['location']['lat']) ? (float) $geom['location']['lat'] : null;
            $lng = isset($geom['location']['lng']) ? (float) $geom['location']['lng'] : null;
        }

        $components = isset($result['address_components']) && is_array($result['address_components'])
            ? $result['address_components'] : [];
        /** @var array<string, list<string>> $byType */
        $byType = [];
        foreach ($components as $c) {
            if (!is_array($c) || !isset($c['types']) || !is_array($c['types'])) {
                continue;
            }
            $name = isset($c['long_name']) && is_string($c['long_name']) ? trim($c['long_name']) : '';
            if ($name === '' && isset($c['short_name']) && is_string($c['short_name'])) {
                $name = trim($c['short_name']);
            }
            foreach ($c['types'] as $t) {
                if (!is_string($t)) {
                    continue;
                }
                $t = strtolower($t);
                $byType[$t] = $byType[$t] ?? [];
                if ($name !== '') {
                    $byType[$t][] = $name;
                }
            }
        }

        $first = static function (array $by, string $type): ?string {
            return isset($by[$type][0]) ? (string) $by[$type][0] : null;
        };

        $pick = static function (array $by, array $types) use ($first): ?string {
            foreach ($types as $type) {
                $v = $first($by, $type);
                if ($v !== null && trim($v) !== '') {
                    return trim($v);
                }
            }

            return null;
        };

        $route = $pick($byType, ['route']);
        $locality = $pick($byType, ['locality']);
        $area = $pick($byType, [
            'sublocality_level_1',
            'sublocality_level_2',
            'sublocality',
            'neighborhood',
            'administrative_area_level_3',
        ]);
        if ($area !== null && $locality !== null && mb_strtolower($area) === mb_strtolower($locality)) {
            $areaOnly = $pick($byType, ['sublocality_level_1', 'sublocality_level_2', 'sublocality', 'neighborhood']);
            $area = $areaOnly ?? $area;
        }
        $city = $locality ?? $pick($byType, ['administrative_area_level_2']);
        if ($city !== null && $area !== null && mb_strtolower($city) === mb_strtolower($area)) {
            $city = $locality ?? $pick($byType, ['administrative_area_level_2', 'administrative_area_level_3']);
        }

        $building = $pick($byType, [
            'premise',
            'subpremise',
            'establishment',
            'point_of_interest',
            'shopping_mall',
            'store',
            'lodging',
            'restaurant',
            'food',
            'cafe',
            'hospital',
            'doctor',
            'finance',
            'real_estate_agency',
            'corporate_office',
            'lawyer',
        ]);

        $placeTypes = isset($result['place_types']) && is_array($result['place_types'])
            ? array_values(array_filter(array_map(
                static fn ($x) => is_string($x) ? strtolower($x) : '',
                $result['place_types']
            )))
            : [];
        $displayName = isset($result['display_name']) && is_string($result['display_name'])
            ? trim($result['display_name']) : '';
        if (($building === null || $building === '') && $displayName !== ''
            && self::shouldUseDisplayNameAsBuilding($displayName, $placeTypes, $byType, $route, $locality, $city, $area)) {
            $building = $displayName;
        }
        $houseNumber = $pick($byType, ['street_number']);
        $state = $pick($byType, ['administrative_area_level_1']);
        $postcode = $pick($byType, ['postal_code']);
        $country = $pick($byType, ['country']);

        $formatted = isset($result['formatted_address']) && is_string($result['formatted_address'])
            ? trim($result['formatted_address']) : null;

        $placeId = isset($result['place_id']) && is_string($result['place_id']) ? $result['place_id'] : '';

        return [
            'place_id' => $placeId,
            'description' => $formatted,
            'house_number' => ($houseNumber !== null && $houseNumber !== '') ? $houseNumber : null,
            'building_name' => ($building !== null && $building !== '') ? $building : null,
            'street' => ($route !== null && $route !== '') ? $route : null,
            'area' => ($area !== null && $area !== '') ? $area : null,
            'city' => ($city !== null && $city !== '') ? $city : null,
            'state' => ($state !== null && $state !== '') ? $state : null,
            'postcode' => ($postcode !== null && $postcode !== '') ? $postcode : null,
            'country' => ($country !== null && $country !== '') ? $country : null,
            'latitude' => $lat !== null ? (string) round($lat, 7) : null,
            'longitude' => $lng !== null ? (string) round($lng, 7) : null,
            'formatted_address' => $formatted,
        ];
    }

    /**
     * @param array<string, mixed> $place Place Details (New) resource
     * @return array<string, mixed>
     */
    /**
     * @param array<string, list<string>> $byType
     * @param list<string> $placeTypes
     */
    private static function shouldUseDisplayNameAsBuilding(
        string $displayName,
        array $placeTypes,
        array $byType,
        ?string $route,
        ?string $locality,
        ?string $city,
        ?string $area
    ): bool {
        $dn = mb_strtolower(trim($displayName));
        if ($dn === '') {
            return false;
        }
        foreach ([$route, $locality, $city, $area] as $part) {
            if ($part !== null && $part !== '' && mb_strtolower(trim($part)) === $dn) {
                return false;
            }
        }
        $generic = ['indore', 'madhya pradesh', 'india'];
        if (in_array($dn, $generic, true)) {
            return false;
        }

        $poiTypes = [
            'point_of_interest', 'establishment', 'premise', 'store', 'shopping_mall', 'lodging',
            'restaurant', 'cafe', 'hospital', 'doctor', 'finance', 'real_estate_agency', 'corporate_office',
            'lawyer', 'health', 'food',
        ];
        foreach ($placeTypes as $t) {
            if ($t !== '' && in_array($t, $poiTypes, true)) {
                return true;
            }
        }
        foreach ($poiTypes as $t) {
            if (!empty($byType[$t])) {
                return true;
            }
        }

        return false;
    }

    private static function legacyShapeFromNewPlace(array $place): array
    {
        $components = [];
        foreach ($place['addressComponents'] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $types = isset($c['types']) && is_array($c['types']) ? $c['types'] : [];
            $long = isset($c['longText']) && is_string($c['longText']) ? trim($c['longText']) : '';
            $short = isset($c['shortText']) && is_string($c['shortText']) ? trim($c['shortText']) : '';
            $components[] = [
                'long_name' => $long !== '' ? $long : $short,
                'short_name' => $short !== '' ? $short : $long,
                'types' => $types,
            ];
        }
        $lat = null;
        $lng = null;
        if (isset($place['location']) && is_array($place['location'])) {
            if (isset($place['location']['latitude'])) {
                $lat = (float) $place['location']['latitude'];
            }
            if (isset($place['location']['longitude'])) {
                $lng = (float) $place['location']['longitude'];
            }
        }

        $displayName = '';
        if (isset($place['displayName']) && is_array($place['displayName'])
            && isset($place['displayName']['text']) && is_string($place['displayName']['text'])) {
            $displayName = trim($place['displayName']['text']);
        }

        return [
            'place_id' => isset($place['id']) && is_string($place['id']) ? $place['id'] : '',
            'formatted_address' => isset($place['formattedAddress']) && is_string($place['formattedAddress'])
                ? $place['formattedAddress'] : '',
            'display_name' => $displayName,
            'place_types' => isset($place['types']) && is_array($place['types']) ? $place['types'] : [],
            'address_components' => $components,
            'geometry' => [
                'location' => [
                    'lat' => $lat,
                    'lng' => $lng,
                ],
            ],
        ];
    }
}
