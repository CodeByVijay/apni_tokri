<?php

declare(strict_types=1);

/**
 * Phase 4 provider routing configuration (AppSettings, prefix `geocoding.`).
 * Works alongside AddressAutocompleteSettings, which keeps the master search
 * toggle, primary provider key and Google credential.
 */
final class GeoProviderSettings
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_PHOTON = 'photon';
    public const PROVIDER_NOMINATIM = 'nominatim';

    public const KEY_FALLBACK = 'geocoding.search_fallback'; // none | google | photon
    public const KEY_REVERSE_ENABLED = 'geocoding.reverse_enabled';
    public const KEY_REVERSE_PROVIDER = 'geocoding.reverse_provider'; // nominatim | google
    public const KEY_PROVIDER_ENABLED = 'geocoding.provider.%s.enabled'; // emergency disable per provider
    public const KEY_DAILY_LIMIT = 'geocoding.quota.%s.daily'; // 0 = unlimited
    public const KEY_MONTHLY_LIMIT = 'geocoding.quota.%s.monthly';

    /** @return list<string> */
    public static function providers(): array
    {
        return [self::PROVIDER_GOOGLE, self::PROVIDER_PHOTON, self::PROVIDER_NOMINATIM];
    }

    private static function enabledKey(string $provider): string
    {
        return sprintf(self::KEY_PROVIDER_ENABLED, $provider);
    }

    /** Emergency per-provider kill switch. Defaults on. */
    public static function providerEnabled(string $provider): bool
    {
        if (!in_array($provider, self::providers(), true)) {
            return false;
        }
        if (!Install::isSchemaReady()) {
            return false;
        }

        return (AppSettings::get(self::enabledKey($provider), '1') ?? '1') === '1';
    }

    public static function setProviderEnabled(string $provider, bool $enabled): void
    {
        if (in_array($provider, self::providers(), true)) {
            AppSettings::set(self::enabledKey($provider), $enabled ? '1' : '0');
        }
    }

    /** Secondary search provider tried when the primary is unavailable/quota'd. */
    public static function searchFallback(): ?string
    {
        $f = strtolower(trim((string) (AppSettings::get(self::KEY_FALLBACK, 'none') ?? 'none')));

        return in_array($f, [self::PROVIDER_GOOGLE, self::PROVIDER_PHOTON], true) ? $f : null;
    }

    /** Ordered candidate list for forward search: primary then fallback. */
    public static function searchOrder(): array
    {
        $out = [AddressAutocompleteSettings::provider()];
        $fb = self::searchFallback();
        if ($fb !== null && $fb !== $out[0]) {
            $out[] = $fb;
        }

        return $out;
    }

    /** Master switch for reverse geocoding ("use my location" / pin lookups). */
    public static function reverseEnabled(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }

        return (AppSettings::get(self::KEY_REVERSE_ENABLED, '1') ?? '1') === '1';
    }

    public static function reverseProvider(): string
    {
        $p = strtolower(trim((string) (AppSettings::get(self::KEY_REVERSE_PROVIDER, self::PROVIDER_NOMINATIM) ?? self::PROVIDER_NOMINATIM)));

        return in_array($p, [self::PROVIDER_NOMINATIM, self::PROVIDER_GOOGLE], true) ? $p : self::PROVIDER_NOMINATIM;
    }

    /** Reverse providers in attempt order; google requires the Geocoding API. */
    public static function reverseOrder(): array
    {
        $out = [self::reverseProvider()];
        $fb = strtolower(trim((string) (AppSettings::get('geocoding.reverse_fallback', 'none') ?? 'none')));
        if (in_array($fb, [self::PROVIDER_NOMINATIM, self::PROVIDER_GOOGLE], true) && $fb !== $out[0]) {
            $out[] = $fb;
        }

        return $out;
    }

    /** @return array{daily: int, monthly: int} 0 = unlimited */
    public static function quota(string $provider): array
    {
        $d = (int) (AppSettings::get(sprintf(self::KEY_DAILY_LIMIT, $provider), '0') ?? '0');
        $m = (int) (AppSettings::get(sprintf(self::KEY_MONTHLY_LIMIT, $provider), '0') ?? '0');

        return ['daily' => max(0, $d), 'monthly' => max(0, $m)];
    }

    /**
     * Whether a provider can currently serve a request at all
     * (enabled + configured credentials where required).
     */
    public static function providerUsable(string $provider): bool
    {
        if (!self::providerEnabled($provider)) {
            return false;
        }

        return $provider !== self::PROVIDER_GOOGLE || AddressAutocompleteSettings::googleKeyConfigured();
    }
}
