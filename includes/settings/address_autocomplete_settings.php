<?php

declare(strict_types=1);

/**
 * Address autocomplete — Admin-configurable (settings table). Keys use prefix `address_autocomplete.`.
 * Google API key stored encrypted via AppSettings::set(..., encrypt: true).
 */
final class AddressAutocompleteSettings
{
    public const KEY_ENABLED = 'address_autocomplete.enabled';
    public const KEY_PROVIDER = 'address_autocomplete.provider'; // google | photon
    public const KEY_DEFAULT_COUNTRY = 'address_autocomplete.default_country';
    public const KEY_COUNTRY_RESTRICTION = 'address_autocomplete.country_restriction'; // ISO-3166-1 alpha-2, comma-separated
    public const KEY_BIAS_LAT = 'address_autocomplete.bias_lat';
    public const KEY_BIAS_LNG = 'address_autocomplete.bias_lng';
    public const KEY_MAX_RESULTS = 'address_autocomplete.max_results';
    public const KEY_TIMEOUT = 'address_autocomplete.timeout_seconds';
    public const KEY_GOOGLE_KEY = 'google_places.api_key';
    public const KEY_RATE_PER_MIN_IP = 'address_autocomplete.rate_per_minute_ip';

    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_PHOTON = 'photon';

    public static function isEnabled(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }

        return (AppSettings::get(self::KEY_ENABLED, '0') ?? '0') === '1';
    }

    public static function provider(): string
    {
        $p = strtolower(trim((string) (AppSettings::get(self::KEY_PROVIDER, self::PROVIDER_PHOTON) ?? self::PROVIDER_PHOTON)));

        return in_array($p, [self::PROVIDER_GOOGLE, self::PROVIDER_PHOTON], true) ? $p : self::PROVIDER_PHOTON;
    }

    public static function maxResults(): int
    {
        $n = (int) (AppSettings::get(self::KEY_MAX_RESULTS, '7') ?? '7');

        return max(3, min(12, $n));
    }

    public static function timeoutSeconds(): int
    {
        $t = (int) (AppSettings::get(self::KEY_TIMEOUT, '8') ?? '8');

        return max(3, min(25, $t));
    }

    public static function ratePerMinuteIp(): int
    {
        $n = (int) (AppSettings::get(self::KEY_RATE_PER_MIN_IP, '45') ?? '45');

        return max(10, min(200, $n));
    }

    /** @return list<string> ISO country codes uppercased */
    public static function countryRestrictions(): array
    {
        $raw = trim((string) (AppSettings::get(self::KEY_COUNTRY_RESTRICTION, 'IN') ?? 'IN'));
        if ($raw === '') {
            return ['IN'];
        }
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $p) {
            $p = strtoupper(trim((string) $p));
            if (preg_match('/^[A-Z]{2}$/', $p)) {
                $out[] = $p;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : ['IN'];
    }

    public static function defaultCountryName(): string
    {
        $c = trim((string) (AppSettings::get(self::KEY_DEFAULT_COUNTRY, 'India') ?? 'India'));

        return $c !== '' ? $c : 'India';
    }

    /** @return array{lat: float, lng: float} */
    public static function biasPoint(): array
    {
        $lat = AppSettings::get(self::KEY_BIAS_LAT, '');
        $lng = AppSettings::get(self::KEY_BIAS_LNG, '');
        $map = MapConfig::settings();
        $la = ($lat !== null && is_numeric($lat)) ? (float) $lat : (float) $map['default_lat'];
        $lo = ($lng !== null && is_numeric($lng)) ? (float) $lng : (float) $map['default_lng'];

        return ['lat' => $la, 'lng' => $lo];
    }

    public static function googleApiKey(): ?string
    {
        $k = AppSettings::getSecret(self::KEY_GOOGLE_KEY, null);

        return ($k !== null && trim($k) !== '') ? trim($k) : null;
    }

    public static function googleKeyConfigured(): bool
    {
        return self::googleApiKey() !== null;
    }

    /**
     * Safe snippet for storefront (no secrets).
     *
     * @return array<string, mixed>
     */
    public static function publicClientConfig(): array
    {
        $base = url_base();

        return [
            'enabled' => self::isEnabled(),
            /** Separate from master toggle: map-free checkout always loads the script; search UI uses this. */
            'searchEnabled' => self::isEnabled(),
            'provider' => self::provider(),
            'minChars' => 3,
            'debounceMs' => 400,
            'maxResults' => self::maxResults(),
            'autocompleteSearchUrl' => $base . '/ajax/address/autocomplete-search.php',
            'googlePlaceDetailsUrl' => $base . '/ajax/address/google-place-details.php',
        ];
    }
}
