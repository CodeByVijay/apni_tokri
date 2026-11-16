<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/provider_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/usage_meter.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/photon_forward.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/google_places.php';

/**
 * Storefront address autocomplete: provider from admin settings, server-side only.
 */
final class AddressAutocompleteService
{
    private const THROTTLE_PREFIX = 'addr-ac-throttle-';

    /**
     * @return array{ok: bool, error?: string, suggestions?: list<array<string, mixed>>, provider?: string}
     */
    public static function search(string $query, string $clientIp, ?string $sessionToken, ?float $biasLatOverride = null, ?float $biasLngOverride = null, ?int $biasRadiusM = null): array
    {
        if (!AddressAutocompleteSettings::isEnabled()) {
            return ['ok' => false, 'error' => 'Address search is disabled.'];
        }

        if (!self::throttleAllow($clientIp, AddressAutocompleteSettings::ratePerMinuteIp())) {
            return ['ok' => false, 'error' => 'Too many searches. Please wait a moment.', 'code' => 429];
        }

        $lastError = 'Address search is temporarily unavailable.';
        $code = 400;
        foreach (GeoProviderSettings::searchOrder() as $provider) {
            if (!GeoProviderSettings::providerEnabled($provider)) {
                $lastError = 'Address search provider is disabled.';
                continue;
            }
            if (!GeocodingUsageMeter::quotaAllow($provider)) {
                $lastError = 'Address search is temporarily unavailable. You can still enter your address manually.';
                continue;
            }
            $res = $provider === GeoProviderSettings::PROVIDER_GOOGLE
                ? self::attemptGoogle($query, $sessionToken, $biasLatOverride, $biasLngOverride, $biasRadiusM)
                : self::attemptPhoton($query, $clientIp);
            if (!empty($res['ok'])) {
                return $res;
            }
            $lastError = (string) ($res['error'] ?? 'Search failed.');
            $code = (int) ($res['code'] ?? 400);
            // Fall through to the configured fallback provider.
        }

        return ['ok' => false, 'error' => $lastError, 'code' => $code];
    }

    private static function attemptGoogle(string $query, ?string $sessionToken, ?float $biasLatOverride, ?float $biasLngOverride, ?int $biasRadiusM): array
    {
        if (!AddressAutocompleteSettings::googleKeyConfigured()) {
            return ['ok' => false, 'error' => 'Google Places is not configured.'];
        }
        $bias = AddressAutocompleteSettings::biasPoint();
        if ($biasLatOverride !== null && $biasLngOverride !== null) {
            $bias = ['lat' => $biasLatOverride, 'lng' => $biasLngOverride];
        }
        $lim = AddressAutocompleteSettings::maxResults();
        $t = AddressAutocompleteSettings::timeoutSeconds();
        $t0 = microtime(true);
        $res = GooglePlacesGeocoder::autocomplete(
            $query,
            $sessionToken,
            AddressAutocompleteSettings::countryRestrictions(),
            $bias['lat'],
            $bias['lng'],
            $lim,
            $t,
            null,
            $biasRadiusM
        );
        $diag = $res['diagnostic'] ?? [];
        $http = is_array($diag) && isset($diag['http_status']) && is_numeric($diag['http_status']) ? (int) $diag['http_status'] : null;
        GeocodingUsageMeter::record('google', 'search', !empty($res['ok']), $http, (int) ((microtime(true) - $t0) * 1000));
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Search failed.')];
        }
        $preds = $res['predictions'] ?? [];
        {
            $out = [];
            $n = 0;
            foreach ($preds as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $pid = isset($p['place_id']) && is_string($p['place_id']) ? $p['place_id'] : '';
                $desc = isset($p['description']) && is_string($p['description']) ? $p['description'] : '';
                if ($pid === '' || $desc === '') {
                    continue;
                }
                $out[] = [
                    'provider' => 'google',
                    'place_id' => $pid,
                    'description' => $desc,
                    'formatted_address' => $desc,
                    'house_number' => null,
                    'street' => null,
                    'area' => null,
                    'city' => null,
                    'state' => null,
                    'postcode' => null,
                    'country' => AddressAutocompleteSettings::defaultCountryName(),
                    'latitude' => null,
                    'longitude' => null,
                    'needs_details' => true,
                    'category' => 'Google Places',
                    'relevance_note' => '',
                ];
                $n++;
                if ($n >= $lim) {
                    break;
                }
            }

            return ['ok' => true, 'provider' => 'google', 'suggestions' => $out];
        }
    }

    private static function attemptPhoton(string $query, string $clientIp): array
    {
        // Photon — reuse PhotonForwardGeocoder (config file + ranking), normalize shape.
        $t0 = microtime(true);
        $photonRes = PhotonForwardGeocoder::search($query, $clientIp, 'checkout');
        GeocodingUsageMeter::record('photon', 'search', !empty($photonRes['ok']), null, (int) ((microtime(true) - $t0) * 1000));
        if (empty($photonRes['ok'])) {
            $msg = (string) ($photonRes['error'] ?? 'Search failed.');
            $code = str_contains($msg, 'Too many searches') ? 429 : 400;

            return ['ok' => false, 'error' => $msg, 'code' => $code];
        }
        $rows = $photonRes['suggestions'] ?? [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['disabled'])) {
                // Notice rows: still useful
                $out[] = [
                    'provider' => 'photon',
                    'place_id' => 'notice:' . hash('sha256', (string) ($row['label'] ?? '') . '|' . (string) ($row['detail'] ?? '')),
                    'description' => (string) ($row['label'] ?? ''),
                    'formatted_address' => trim((string) ($row['label'] ?? '') . (isset($row['detail']) && $row['detail'] !== '' ? ', ' . $row['detail'] : '')),
                    'house_number' => null,
                    'street' => null,
                    'area' => null,
                    'city' => null,
                    'state' => null,
                    'postcode' => null,
                    'country' => 'India',
                    'latitude' => null,
                    'longitude' => null,
                    'needs_details' => false,
                    'disabled' => true,
                    'category' => (string) ($row['category'] ?? 'Notice'),
                    'relevance_note' => '',
                ];

                continue;
            }
            $lat = isset($row['lat']) ? (float) $row['lat'] : null;
            $lng = isset($row['lng']) ? (float) $row['lng'] : null;
            $label = (string) ($row['label'] ?? '');
            $detail = (string) ($row['detail'] ?? '');
            $out[] = [
                'provider' => 'photon',
                'place_id' => (string) ($row['feature_id'] ?? ('photon:' . $lat . ':' . $lng)),
                'description' => $label,
                'formatted_address' => trim($label . ($detail !== '' ? ', ' . $detail : '')),
                'house_number' => isset($row['housenumber']) && is_string($row['housenumber']) && $row['housenumber'] !== '' ? $row['housenumber'] : null,
                'street' => self::inferPhotonStreet($row),
                'area' => self::inferPhotonArea($row),
                'city' => self::extractCityFromDetail($detail) ?? 'Indore',
                'state' => self::extractStateFromDetail($detail) ?? 'Madhya Pradesh',
                'postcode' => self::extractPostcodeFromDetail($detail),
                'country' => 'India',
                'latitude' => $lat !== null ? (string) round($lat, 7) : null,
                'longitude' => $lng !== null ? (string) round($lng, 7) : null,
                'needs_details' => false,
                'category' => (string) ($row['category'] ?? ''),
                'relevance_note' => (string) ($row['relevance_note'] ?? ''),
                'meta' => [
                    'type' => (string) ($row['type'] ?? ''),
                    'osm_key' => (string) ($row['osm_key'] ?? ''),
                    'osm_value' => (string) ($row['osm_value'] ?? ''),
                ],
            ];
        }

        return ['ok' => true, 'provider' => 'photon', 'suggestions' => $out];
    }

    /**
     * Google Place Details — normalized fields for form + coords.
     *
     * @return array{ok: bool, error?: string, address?: array<string, mixed>}
     */
    public static function googlePlaceDetails(string $placeId, ?string $sessionToken): array
    {
        if (!AddressAutocompleteSettings::isEnabled()
            || !GeoProviderSettings::providerUsable(GeoProviderSettings::PROVIDER_GOOGLE)) {
            return ['ok' => false, 'error' => 'Google Places is not available.'];
        }
        $t0 = microtime(true);
        $res = GooglePlacesGeocoder::placeDetails(
            $placeId,
            $sessionToken,
            AddressAutocompleteSettings::timeoutSeconds(),
            null
        );
        $diag = $res['diagnostic'] ?? [];
        $http = is_array($diag) && isset($diag['http_status']) && is_numeric($diag['http_status']) ? (int) $diag['http_status'] : null;
        GeocodingUsageMeter::record('google', 'place_details', !empty($res['ok']), $http, (int) ((microtime(true) - $t0) * 1000));
        if (empty($res['ok']) || !isset($res['place']) || !is_array($res['place'])) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not load place details.')];
        }
        $norm = GooglePlacesGeocoder::normalizePlaceDetails($res['place']);
        if (($norm['latitude'] ?? null) === null || ($norm['longitude'] ?? null) === null) {
            return ['ok' => false, 'error' => 'This place has no coordinates. Try another result.'];
        }

        return ['ok' => true, 'address' => $norm];
    }

    /**
     * Connection test (admin): lightweight autocomplete.
     *
     * @return array{ok: bool, error?: string, diagnostic?: array<string, mixed>}
     */
    public static function testGoogleConnection(?string $apiKeyOverride): array
    {
        $bias = AddressAutocompleteSettings::biasPoint();

        return GooglePlacesGeocoder::autocomplete(
            'Indore',
            null,
            AddressAutocompleteSettings::countryRestrictions(),
            $bias['lat'],
            $bias['lng'],
            1,
            AddressAutocompleteSettings::timeoutSeconds(),
            $apiKeyOverride
        );
    }

    private static function inferPhotonStreet(array $row): ?string
    {
        $d = (string) ($row['detail'] ?? '');
        if (preg_match('/^([^·]+)/u', $d, $m)) {
            $first = trim($m[1]);
            if ($first !== '' && !preg_match('/^\d{6}$/', $first)) {
                return $first;
            }
        }

        return null;
    }

    private static function inferPhotonArea(array $row): ?string
    {
        $label = (string) ($row['label'] ?? '');
        $t = (string) ($row['type'] ?? '');
        if (in_array($t, ['locality', 'district', 'street'], true) && $label !== '') {
            return $label;
        }

        return null;
    }

    private static function extractPostcodeFromDetail(string $detail): ?string
    {
        if (preg_match('/\b(\d{6})\b/', $detail, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function extractCityFromDetail(string $detail): ?string
    {
        $parts = array_map('trim', explode('·', $detail));
        foreach ($parts as $p) {
            if (strcasecmp($p, 'Indore') === 0 || strcasecmp($p, 'Indore City') === 0) {
                return 'Indore';
            }
        }

        return null;
    }

    private static function extractStateFromDetail(string $detail): ?string
    {
        if (stripos($detail, 'Madhya Pradesh') !== false) {
            return 'Madhya Pradesh';
        }

        return null;
    }

    private static function throttleAllow(string $ip, int $rpm): bool
    {
        if ($ip === '') {
            $ip = '0.0.0.0';
        }
        $safeIp = preg_replace('/[^0-9a-f.:]/i', '_', $ip) ?? 'unknown';
        $dir = APNI_TOKRI_ROOT . '/storage/temporary';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . self::THROTTLE_PREFIX . hash('sha256', $safeIp) . '.json';
        $now = time();
        $window = 60;
        $data = ['window_start' => $now, 'count' => 0];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
                        $data = ['window_start' => (int) $decoded['window_start'], 'count' => (int) $decoded['count']];
                    }
                } catch (Throwable $e) {
                    $data = ['window_start' => $now, 'count' => 0];
                }
            }
        }
        if ($now - $data['window_start'] >= $window) {
            $data = ['window_start' => $now, 'count' => 0];
        }
        if ($data['count'] >= $rpm) {
            return false;
        }
        $data['count']++;
        @file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);

        return true;
    }
}
