<?php

declare(strict_types=1);

/**
 * Google Places API (server-side only). Autocomplete + Place Details.
 * Key from AppSettings encrypted secret — never sent to the browser.
 *
 * @see https://developers.google.com/maps/documentation/places/web-service/autocomplete
 */
final class GooglePlacesGeocoder
{
    private const AUTOCOMPLETE_URL = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';
    private const DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

    /**
     * @param list<string> $countries ISO 3166-1 alpha-2 uppercase
     * @return array{ok: bool, error?: string, predictions?: list<array<string, mixed>>}
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

        $params = [
            'input' => $input,
            'key' => $key,
        ];
        if ($sessionToken !== null && $sessionToken !== '' && strlen($sessionToken) <= 128) {
            $params['sessiontoken'] = $sessionToken;
        }
        if ($countries !== []) {
            $parts = [];
            foreach ($countries as $c) {
                $parts[] = 'country:' . strtolower($c);
            }
            $params['components'] = implode('|', $parts);
        }
        // Circle bias ~25 km
        $params['location'] = sprintf('%.6f,%.6f', $biasLat, $biasLng);
        $params['radius'] = '25000';
        $params['strictbounds'] = 'false';

        $url = self::AUTOCOMPLETE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return self::httpJson($url, $timeoutSeconds, 'predictions', 'place_autocomplete');
    }

    /**
     * @return array{ok: bool, error?: string, place?: array<string, mixed>}
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

        $params = [
            'place_id' => $placeId,
            'key' => $key,
            'fields' => 'address_components,formatted_address,geometry/location,place_id,name',
        ];
        if ($sessionToken !== null && $sessionToken !== '' && strlen($sessionToken) <= 128) {
            $params['sessiontoken'] = $sessionToken;
        }
        $url = self::DETAILS_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return self::httpJson($url, $timeoutSeconds, 'result', 'place_details');
    }

    /**
     * Legacy Places Web Service (not Places API New). Query-string `key=` authentication.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   predictions?: list<array<string, mixed>>,
     *   place?: array<string, mixed>,
     *   diagnostic?: array<string, mixed>
     * }
     */
    private static function httpJson(string $url, int $timeoutSeconds, string $dataKey, string $operation): array
    {
        $base = App::baseUrl();
        $ua = 'VeggieTokriGooglePlaces/1.0 (+' . ($base !== '' ? $base : 'https://veggietokri.com') . ')';

        $endpointSafe = self::safeEndpointForLog($url);

        $ch = curl_init($url);
        if ($ch === false) {
            $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, 0, '', '', 'Could not initialise HTTP client.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Could not reach Google Places.', 'diagnostic' => $diag];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ' . $ua],
            CURLOPT_TIMEOUT => max(3, min(25, $timeoutSeconds)),
            CURLOPT_CONNECTTIMEOUT => min(8, max(3, $timeoutSeconds)),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, '', '', 'Empty response body.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Empty response from Google Places.', 'diagnostic' => $diag];
        }
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, '', '', 'JSON decode failed.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Invalid response from Google Places.', 'diagnostic' => $diag];
        }
        if (!is_array($json)) {
            $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, '', '', 'Response is not a JSON object.');
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => 'Invalid response from Google Places.', 'diagnostic' => $diag];
        }
        if ($status < 200 || $status >= 300) {
            $fallback = isset($json['status']) && is_string($json['status']) ? (string) $json['status'] : 'HTTP_' . $status;
            $gErrRaw = isset($json['error_message']) && is_string($json['error_message']) ? trim($json['error_message']) : '';
            $gErrSan = self::sanitizeGoogleErrorMessage($gErrRaw);
            $token = self::classifyGoogleError($fallback, $gErrSan);
            $msg = self::formatAdminFacingError($fallback, $gErrSan, $token !== '' ? $token : 'UNKNOWN');
            $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, $fallback, $gErrSan, $token !== '' ? $token : null);
            self::logPlacesDiagnostic($diag, false);

            return ['ok' => false, 'error' => $msg, 'diagnostic' => $diag];
        }
        $gStatus = (string) ($json['status'] ?? '');
        $gErrRaw = isset($json['error_message']) && is_string($json['error_message']) ? trim($json['error_message']) : '';
        $gErrSan = self::sanitizeGoogleErrorMessage($gErrRaw);
        if ($gStatus === 'OK' || $gStatus === 'ZERO_RESULTS') {
            if ($dataKey === 'predictions') {
                $pred = isset($json['predictions']) && is_array($json['predictions']) ? $json['predictions'] : [];
                $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, $gStatus, $gErrSan, null);
                self::logPlacesDiagnostic($diag, true);

                return ['ok' => true, 'predictions' => $pred, 'diagnostic' => $diag];
            }
            $res = $json['result'] ?? null;
            $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, $gStatus, $gErrSan, null);
            self::logPlacesDiagnostic($diag, true);

            return ['ok' => true, 'place' => is_array($res) ? $res : [], 'diagnostic' => $diag];
        }
        $token = self::classifyGoogleError($gStatus, $gErrSan);
        $msg = self::formatAdminFacingError($gStatus, $gErrSan, $token);
        $diag = self::baseDiagnostic('google_places_legacy', $operation, $endpointSafe, $status, $gStatus, $gErrSan, $token);
        self::logPlacesDiagnostic($diag, false);

        return ['ok' => false, 'error' => $msg, 'diagnostic' => $diag];
    }

    private static function safeEndpointForLog(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p)) {
            return 'places_legacy';
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
        // Strip query strings from URLs so key= never appears in logs/UI.
        $msg = preg_replace('/\bkey=[^&\s]+/iu', 'key=[redacted]', $msg) ?? $msg;

        return mb_substr($msg, 0, 500);
    }

    /**
     * Conservative labels only when Google's error_message matches known phrases (not guessing from our generic copy).
     */
    private static function classifyGoogleError(string $status, string $errorMessage): string
    {
        $m = mb_strtolower($errorMessage);
        if ($status === 'OVER_QUERY_LIMIT' || str_contains($m, 'quota')) {
            return 'QUOTA_EXCEEDED';
        }
        if ($status === 'REQUEST_DENIED') {
            if (str_contains($m, 'permission denied') || str_contains($m, 'permission_denied')) {
                return 'PERMISSION_DENIED';
            }
            if (str_contains($m, 'api key is invalid') || str_contains($m, 'invalid api key')) {
                return 'API_KEY_INVALID';
            }
            if (str_contains($m, 'this api project is not authorized to use this api')
                || str_contains($m, 'has not been used') || str_contains($m, 'is disabled')) {
                return 'API_NOT_ENABLED';
            }
            if (str_contains($m, 'billing') || str_contains($m, 'billingenabled')) {
                return 'BILLING_NOT_ENABLED';
            }
            if (str_contains($m, 'ip') && str_contains($m, 'restriction')) {
                return 'REQUEST_DENIED';
            }
            if (str_contains($m, 'referer') || str_contains($m, 'referrer')) {
                return 'REQUEST_DENIED';
            }

            return 'REQUEST_DENIED';
        }
        if ($status === 'INVALID_REQUEST') {
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
     * @param array<string, mixed> $result Place Details result
     * @return array<string, string|null>
     */
    public static function normalizePlaceDetails(array $result): array
    {
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
            foreach ($c['types'] as $t) {
                if (!is_string($t)) {
                    continue;
                }
                $byType[$t] = $byType[$t] ?? [];
                if ($name !== '') {
                    $byType[$t][] = $name;
                }
            }
        }

        $first = static function (array $by, string $type): ?string {
            return isset($by[$type][0]) ? (string) $by[$type][0] : null;
        };

        $streetNumber = $first($byType, 'street_number');
        $route = $first($byType, 'route');
        $street = trim(implode(', ', array_filter([$streetNumber, $route], static fn ($x) => $x !== null && $x !== '')));
        if ($street === '') {
            $street = null;
        }

        $subLoc = $first($byType, 'sublocality_level_1') ?? $first($byType, 'sublocality');
        $neigh = $first($byType, 'neighborhood');
        $locality = $first($byType, 'locality');
        $area = $subLoc ?? $neigh ?? $locality;

        $city = $locality ?? $first($byType, 'administrative_area_level_2');
        $state = $first($byType, 'administrative_area_level_1');
        $postcode = $first($byType, 'postal_code');
        $country = $first($byType, 'country');

        $formatted = isset($result['formatted_address']) && is_string($result['formatted_address'])
            ? trim($result['formatted_address']) : null;

        $placeId = isset($result['place_id']) && is_string($result['place_id']) ? $result['place_id'] : '';

        return [
            'place_id' => $placeId,
            'description' => $formatted,
            'house_number' => $streetNumber,
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
}
