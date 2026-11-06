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

        return self::httpJson($url, $timeoutSeconds, 'predictions');
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

        return self::httpJson($url, $timeoutSeconds, 'result');
    }

    /**
     * @return array{ok: bool, error?: string, predictions?: list<array<string, mixed>>}|array{ok: bool, error?: string, place?: array<string, mixed>}
     */
    private static function httpJson(string $url, int $timeoutSeconds, string $dataKey): array
    {
        $base = App::baseUrl();
        $ua = 'VeggieTokriGooglePlaces/1.0 (+' . ($base !== '' ? $base : 'https://veggietokri.com') . ')';

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not reach Google Places.'];
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
            return ['ok' => false, 'error' => 'Empty response from Google Places.'];
        }
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Invalid response from Google Places.'];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Invalid response from Google Places.'];
        }
        $gStatus = (string) ($json['status'] ?? '');
        if ($gStatus === 'OK' || $gStatus === 'ZERO_RESULTS') {
            if ($dataKey === 'predictions') {
                $pred = isset($json['predictions']) && is_array($json['predictions']) ? $json['predictions'] : [];

                return ['ok' => true, 'predictions' => $pred];
            }
            $res = $json['result'] ?? null;

            return ['ok' => true, 'place' => is_array($res) ? $res : []];
        }
        $msg = self::mapGoogleError($gStatus, $json);

        return ['ok' => false, 'error' => $msg];
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function mapGoogleError(string $status, array $json): string
    {
        return match ($status) {
            'REQUEST_DENIED' => 'Google Places denied the request. Check API key and enabled APIs.',
            'OVER_QUERY_LIMIT' => 'Google Places quota exceeded. Try again later.',
            'INVALID_REQUEST' => 'Invalid request to Google Places.',
            default => 'Google Places error: ' . $status,
        };
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
