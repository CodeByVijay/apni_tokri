<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/address_autocomplete_service.php';

/**
 * Admin persistence for Map & Geocoding / address autocomplete (AppSettings).
 */
final class AddressAutocompleteAdminSettings
{
    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string}
     */
    public static function saveFromPost(array $post): array
    {
        $enabled = !empty($post['address_autocomplete_enabled']) ? '1' : '0';
        $provider = isset($post['address_autocomplete_provider']) && is_string($post['address_autocomplete_provider'])
            ? strtolower(trim($post['address_autocomplete_provider'])) : AddressAutocompleteSettings::PROVIDER_PHOTON;
        if (!in_array($provider, [AddressAutocompleteSettings::PROVIDER_GOOGLE, AddressAutocompleteSettings::PROVIDER_PHOTON], true)) {
            $provider = AddressAutocompleteSettings::PROVIDER_PHOTON;
        }

        $defaultCountry = trim((string) ($post['address_autocomplete_default_country'] ?? 'India'));
        if ($defaultCountry === '') {
            $defaultCountry = 'India';
        }
        if (mb_strlen($defaultCountry) > 120) {
            return ['ok' => false, 'message' => 'Default country name is too long.'];
        }

        $countryRestriction = trim((string) ($post['address_autocomplete_country_restriction'] ?? 'IN'));
        if ($countryRestriction === '') {
            $countryRestriction = 'IN';
        }
        if (mb_strlen($countryRestriction) > 80) {
            return ['ok' => false, 'message' => 'Country restriction is too long.'];
        }

        $biasLat = isset($post['address_autocomplete_bias_lat']) && is_string($post['address_autocomplete_bias_lat'])
            ? trim($post['address_autocomplete_bias_lat']) : '';
        $biasLng = isset($post['address_autocomplete_bias_lng']) && is_string($post['address_autocomplete_bias_lng'])
            ? trim($post['address_autocomplete_bias_lng']) : '';
        if ($biasLat !== '' && !is_numeric($biasLat)) {
            return ['ok' => false, 'message' => 'Search bias latitude must be a number.'];
        }
        if ($biasLng !== '' && !is_numeric($biasLng)) {
            return ['ok' => false, 'message' => 'Search bias longitude must be a number.'];
        }
        if ($biasLat !== '' && $biasLng === '' || $biasLat === '' && $biasLng !== '') {
            return ['ok' => false, 'message' => 'Set both bias latitude and longitude, or leave both empty to use the store default.'];
        }

        $maxResults = isset($post['address_autocomplete_max_results']) && is_string($post['address_autocomplete_max_results'])
            ? (int) $post['address_autocomplete_max_results'] : 7;
        $maxResults = max(3, min(12, $maxResults));

        $timeout = isset($post['address_autocomplete_timeout']) && is_string($post['address_autocomplete_timeout'])
            ? (int) $post['address_autocomplete_timeout'] : 8;
        $timeout = max(3, min(25, $timeout));

        $rpm = isset($post['address_autocomplete_rpm']) && is_string($post['address_autocomplete_rpm'])
            ? (int) $post['address_autocomplete_rpm'] : 45;
        $rpm = max(10, min(200, $rpm));

        $gpsEnabled = !empty($post['address_autocomplete_gps_enabled']) ? '1' : '0';

        AppSettings::set(AddressAutocompleteSettings::KEY_ENABLED, $enabled);
        AppSettings::set(AddressAutocompleteSettings::KEY_GPS_ENABLED, $gpsEnabled);
        AppSettings::set(AddressAutocompleteSettings::KEY_PROVIDER, $provider);
        AppSettings::set(AddressAutocompleteSettings::KEY_DEFAULT_COUNTRY, $defaultCountry);
        AppSettings::set(AddressAutocompleteSettings::KEY_COUNTRY_RESTRICTION, $countryRestriction);
        if ($biasLat !== '' && $biasLng !== '') {
            AppSettings::set(AddressAutocompleteSettings::KEY_BIAS_LAT, (string) (float) $biasLat);
            AppSettings::set(AddressAutocompleteSettings::KEY_BIAS_LNG, (string) (float) $biasLng);
        } else {
            AppSettings::set(AddressAutocompleteSettings::KEY_BIAS_LAT, '');
            AppSettings::set(AddressAutocompleteSettings::KEY_BIAS_LNG, '');
        }
        AppSettings::set(AddressAutocompleteSettings::KEY_MAX_RESULTS, (string) $maxResults);
        AppSettings::set(AddressAutocompleteSettings::KEY_TIMEOUT, (string) $timeout);
        AppSettings::set(AddressAutocompleteSettings::KEY_RATE_PER_MIN_IP, (string) $rpm);

        $newKey = isset($post['google_places_api_key']) && is_string($post['google_places_api_key'])
            ? trim($post['google_places_api_key']) : '';
        if ($newKey !== '') {
            AppSettings::set(AddressAutocompleteSettings::KEY_GOOGLE_KEY, $newKey, true);
        }

        return ['ok' => true, 'message' => 'Map & geocoding settings saved.'];
    }

    /**
     * Test Google Autocomplete with optional one-off key from the form.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    public static function testGoogleFromPost(array $post): array
    {
        $override = isset($post['google_places_api_key']) && is_string($post['google_places_api_key'])
            ? trim($post['google_places_api_key']) : '';
        $res = AddressAutocompleteService::testGoogleConnection($override !== '' ? $override : null);
        if (!empty($res['ok'])) {
            return ['ok' => true, 'message' => 'Google Places responded successfully (autocomplete probe).'];
        }

        $err = (string) ($res['error'] ?? 'Request failed.');
        // Never echo API key material
        $err = preg_replace('/AIza[0-9A-Za-z_-]{10,}/', '[redacted]', $err) ?? $err;
        if (isset($res['diagnostic']) && is_array($res['diagnostic'])) {
            $d = self::formatDiagnosticFlashLine($res['diagnostic']);
            if ($d !== '') {
                $err .= ' | ' . $d;
            }
        }

        return ['ok' => false, 'message' => $err];
    }

    /**
     * @param array<string, mixed> $diag
     */
    private static function formatDiagnosticFlashLine(array $diag): string
    {
        $http = isset($diag['http_status']) && is_numeric($diag['http_status']) ? (int) $diag['http_status'] : null;
        $gs = isset($diag['google_status']) && is_string($diag['google_status']) ? trim($diag['google_status']) : '';
        $cls = isset($diag['class']) && is_string($diag['class']) ? trim($diag['class']) : '';
        $ep = isset($diag['endpoint']) && is_string($diag['endpoint']) ? trim($diag['endpoint']) : '';
        $parts = [];
        if ($http !== null) {
            $parts[] = 'HTTP ' . $http;
        }
        if ($ep !== '') {
            $parts[] = $ep;
        }
        if ($gs !== '') {
            $parts[] = 'status=' . $gs;
        }
        if ($cls !== '') {
            $parts[] = $cls;
        }

        return implode(' · ', array_filter($parts, static fn ($x) => $x !== ''));
    }

    public static function googleKeySaved(): bool
    {
        return trim(AppSettings::getSecret(AddressAutocompleteSettings::KEY_GOOGLE_KEY, '') ?? '') !== '';
    }
}
