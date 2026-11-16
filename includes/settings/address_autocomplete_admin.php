<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/provider_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/usage_meter.php';
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

        // Phase 4: provider routing, per-provider switches and quotas.
        $fallback = isset($post['geocoding_search_fallback']) && is_string($post['geocoding_search_fallback'])
            ? strtolower(trim($post['geocoding_search_fallback'])) : 'none';
        if (!in_array($fallback, ['none', GeoProviderSettings::PROVIDER_GOOGLE, GeoProviderSettings::PROVIDER_PHOTON], true)) {
            $fallback = 'none';
        }
        if ($fallback === $provider) {
            $fallback = 'none';
        }
        AppSettings::set(GeoProviderSettings::KEY_FALLBACK, $fallback);

        foreach (GeoProviderSettings::providers() as $p) {
            GeoProviderSettings::setProviderEnabled($p, !empty($post['geocoding_provider_' . $p . '_enabled']));
            $daily = isset($post['geocoding_quota_' . $p . '_daily']) && is_numeric($post['geocoding_quota_' . $p . '_daily'])
                ? (int) $post['geocoding_quota_' . $p . '_daily'] : 0;
            $monthly = isset($post['geocoding_quota_' . $p . '_monthly']) && is_numeric($post['geocoding_quota_' . $p . '_monthly'])
                ? (int) $post['geocoding_quota_' . $p . '_monthly'] : 0;
            AppSettings::set(sprintf(GeoProviderSettings::KEY_DAILY_LIMIT, $p), (string) max(0, min(10000000, $daily)));
            AppSettings::set(sprintf(GeoProviderSettings::KEY_MONTHLY_LIMIT, $p), (string) max(0, min(100000000, $monthly)));
        }

        $reverseEnabled = !empty($post['geocoding_reverse_enabled']) ? '1' : '0';
        $reverseProvider = isset($post['geocoding_reverse_provider']) && is_string($post['geocoding_reverse_provider'])
            ? strtolower(trim($post['geocoding_reverse_provider'])) : GeoProviderSettings::PROVIDER_NOMINATIM;
        if (!in_array($reverseProvider, [GeoProviderSettings::PROVIDER_NOMINATIM, GeoProviderSettings::PROVIDER_GOOGLE], true)) {
            $reverseProvider = GeoProviderSettings::PROVIDER_NOMINATIM;
        }
        $reverseFallback = isset($post['geocoding_reverse_fallback']) && is_string($post['geocoding_reverse_fallback'])
            ? strtolower(trim($post['geocoding_reverse_fallback'])) : 'none';
        if (!in_array($reverseFallback, ['none', GeoProviderSettings::PROVIDER_NOMINATIM, GeoProviderSettings::PROVIDER_GOOGLE], true)
            || $reverseFallback === $reverseProvider) {
            $reverseFallback = 'none';
        }
        AppSettings::set(GeoProviderSettings::KEY_REVERSE_ENABLED, $reverseEnabled);
        AppSettings::set(GeoProviderSettings::KEY_REVERSE_PROVIDER, $reverseProvider);
        AppSettings::set('geocoding.reverse_fallback', $reverseFallback);

        $keyWarnings = [];
        // The two Google credentials must stay separate: the browser key carries
        // HTTP-referrer restrictions which Google rejects for server-side calls.
        $newKey = isset($post['google_places_api_key']) && is_string($post['google_places_api_key'])
            ? trim($post['google_places_api_key']) : '';
        if ($newKey !== '') {
            if ($newKey === AddressAutocompleteSettings::googleMapsBrowserKey()) {
                $keyWarnings[] = 'Server key not saved: it matches the browser Maps key. Use a separate server-side credential without HTTP-referrer restrictions.';
            } else {
                AppSettings::set(AddressAutocompleteSettings::KEY_GOOGLE_KEY, $newKey, true);
            }
        }
        if (!empty($post['google_places_api_key_remove'])) {
            AppSettings::set(AddressAutocompleteSettings::KEY_GOOGLE_KEY, '', true);
        }

        $newMapsKey = isset($post['google_maps_browser_api_key']) && is_string($post['google_maps_browser_api_key'])
            ? trim($post['google_maps_browser_api_key']) : '';
        if ($newMapsKey !== '') {
            if ($newMapsKey === AddressAutocompleteSettings::googleApiKey()) {
                $keyWarnings[] = 'Browser Maps key not saved: it matches the server key. Create a dedicated key restricted to the Maps JavaScript API and your site referrers.';
            } else {
                AppSettings::set(AddressAutocompleteSettings::KEY_GOOGLE_MAPS_BROWSER_KEY, $newMapsKey, true);
            }
        }
        if (!empty($post['google_maps_browser_api_key_remove'])) {
            AppSettings::set(AddressAutocompleteSettings::KEY_GOOGLE_MAPS_BROWSER_KEY, '', true);
        }

        $msg = 'Map & geocoding settings saved.';
        if ($keyWarnings !== []) {
            $msg .= ' ' . implode(' ', $keyWarnings);
        }

        return ['ok' => true, 'message' => $msg];
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

    public static function googleMapsBrowserKeySaved(): bool
    {
        return trim(AppSettings::getSecret(AddressAutocompleteSettings::KEY_GOOGLE_MAPS_BROWSER_KEY, '') ?? '') !== '';
    }
}
