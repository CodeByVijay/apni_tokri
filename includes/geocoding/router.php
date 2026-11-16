<?php

declare(strict_types=1);

require_once APNI_TOKRI_ROOT . '/includes/geocoding/provider_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/usage_meter.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/nominatim_reverse.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/google_places.php';

/**
 * Reverse-geocoding routing: honors master toggle, per-provider enable switch,
 * configured provider order, quota gates and fallback. Nominatim usage follows
 * its published usage policy (server-side, 1 req/s, identifiable UA, cached
 * results only via the selected-address session snapshot — no bulk lookups).
 */
final class GeocodingRouter
{
    /**
     * @return array{ok: bool, error?: string, provider?: string, street?: string, area?: string, city?: string, state?: string, pincode?: string, country?: string, formatted?: string}
     */
    public static function reverse(float $lat, float $lng): array
    {
        if (!GeoProviderSettings::reverseEnabled()) {
            return ['ok' => false, 'error' => 'Address lookup is disabled.'];
        }

        $lastError = 'Address lookup is temporarily unavailable.';
        foreach (GeoProviderSettings::reverseOrder() as $provider) {
            if (!GeoProviderSettings::providerEnabled($provider)) {
                $lastError = 'Address lookup provider is disabled.';
                continue;
            }
            if (!GeocodingUsageMeter::quotaAllow($provider)) {
                $lastError = 'Address lookup is temporarily unavailable.';
                continue;
            }
            $res = $provider === GeoProviderSettings::PROVIDER_GOOGLE
                ? self::attemptGoogle($lat, $lng)
                : self::attemptNominatim($lat, $lng);
            if (!empty($res['ok'])) {
                $res['provider'] = $provider;

                return $res;
            }
            $lastError = (string) ($res['error'] ?? $lastError);
            // Fall through to the configured fallback provider.
        }

        return ['ok' => false, 'error' => $lastError];
    }

    private static function attemptNominatim(float $lat, float $lng): array
    {
        $t0 = microtime(true);
        $res = NominatimReverseGeocoder::reverse($lat, $lng);
        GeocodingUsageMeter::record('nominatim', 'reverse', !empty($res['ok']), null, (int) ((microtime(true) - $t0) * 1000));

        return $res;
    }

    private static function attemptGoogle(float $lat, float $lng): array
    {
        if (!AddressAutocompleteSettings::googleKeyConfigured()) {
            return ['ok' => false, 'error' => 'Google API key is not configured.'];
        }
        $t0 = microtime(true);
        $res = GooglePlacesGeocoder::reverseGeocode($lat, $lng, AddressAutocompleteSettings::timeoutSeconds(), null);
        $diag = $res['diagnostic'] ?? [];
        $http = is_array($diag) && isset($diag['http_status']) && is_numeric($diag['http_status']) ? (int) $diag['http_status'] : null;
        GeocodingUsageMeter::record('google', 'reverse', !empty($res['ok']), $http, (int) ((microtime(true) - $t0) * 1000));
        unset($res['diagnostic']);

        return $res;
    }
}
