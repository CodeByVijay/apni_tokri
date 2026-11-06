<?php

declare(strict_types=1);

/**
 * Reverse geocoding via OpenStreetMap Nominatim (no API key).
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/ — use a valid User-Agent, reasonable request volume.
 */
final class NominatimReverseGeocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    /**
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   street?: string,
     *   area?: string,
     *   city?: string,
     *   state?: string,
     *   pincode?: string,
     *   country?: string
     * }
     */
    public static function reverse(float $lat, float $lng): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return ['ok' => false, 'error' => 'Invalid coordinates.'];
        }

        $base = App::baseUrl();
        $ua = 'VeggieTokriCheckout/1.0 (+' . ($base !== '' ? $base : 'https://veggietokri.com') . ')';

        $url = self::ENDPOINT . '?format=jsonv2&lat=' . rawurlencode((string) $lat)
            . '&lon=' . rawurlencode((string) $lng) . '&addressdetails=1';

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not reach address lookup.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'error' => $cerr !== '' ? 'Address lookup failed.' : 'Empty response from address lookup.'];
        }
        if ($status === 429) {
            return ['ok' => false, 'error' => 'Address lookup is busy. Try again in a moment.'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Could not resolve this location.'];
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Invalid response from address lookup.'];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Could not resolve this location.'];
        }

        $addr = $json['address'] ?? null;
        if (!is_array($addr)) {
            return ['ok' => false, 'error' => 'No address details for this location.'];
        }

        if (App::isDebug()) {
            $addrKeys = array_keys($addr);
            sort($addrKeys);
            error_log(
                'nominatim_reverse: address keys=' . implode(',', $addrKeys)
                . ' category=' . (string) ($json['category'] ?? '')
                . ' type=' . (string) ($json['type'] ?? '')
            );
        }

        return self::mapAddress($json);
    }

    /**
     * Map Nominatim `addressdetails` (see format=jsonv2) to checkout fields.
     * Street = thoroughfare (+ house number when present). Area / locality only = neighbourhood-scale keys — never city+state.
     *
     * @param array<string, mixed> $json Top-level reverse JSON (must include `address`)
     * @return array<string, string|bool>
     */
    private static function mapAddress(array $json): array
    {
        $addr = $json['address'] ?? null;
        if (!is_array($addr)) {
            return ['ok' => false, 'error' => 'No address details for this location.'];
        }

        $thoroughfareKeys = [
            'road', 'residential', 'pedestrian', 'street', 'path', 'footway',
            'highway', 'unclassified', 'primary', 'secondary', 'tertiary', 'living_street',
            'service', 'track', 'cycleway', 'junction', 'square',
        ];
        $road = self::firstStr($addr, $thoroughfareKeys);
        $houseNum = self::firstStr($addr, ['house_number']);
        $street = trim($houseNum !== '' && $road !== '' ? $houseNum . ', ' . $road : ($road !== '' ? $road : $houseNum));

        $localityKeys = [
            'neighbourhood', 'suburb', 'quarter', 'locality', 'village', 'hamlet', 'city_block',
        ];
        $locality = self::firstStr($addr, $localityKeys);

        if ($locality === '') {
            $adminFallbackKeys = ['county', 'state_district', 'district', 'municipality'];
            foreach ($adminFallbackKeys as $k) {
                $v = self::scalarStr($addr[$k] ?? null);
                if ($v === '') {
                    continue;
                }
                $citySoon = self::cityFromAddress($addr);
                if ($citySoon !== '' && strcasecmp($v, $citySoon) === 0) {
                    continue;
                }
                $locality = $v;
                break;
            }
        }

        $city = self::cityFromAddress($addr);
        $state = self::firstStr($addr, ['state']);
        $country = self::firstStr($addr, ['country']);
        $postcodeRaw = self::firstStr($addr, ['postcode']);
        $postcode = preg_replace('/\D+/', '', $postcodeRaw) ?? '';

        $area = $locality;

        $out = [
            'ok' => true,
            'street' => $street,
            'area' => $area,
            'city' => $city,
            'state' => $state,
            'country' => $country,
        ];
        if ($postcode !== '' && preg_match('/^[1-9][0-9]{5}$/', $postcode) === 1) {
            $out['pincode'] = $postcode;
        }

        $hasAny = $street !== '' || $area !== '' || $city !== '' || $state !== '' || isset($out['pincode']) || $country !== '';
        if (!$hasAny) {
            return ['ok' => false, 'error' => 'No usable address fields for this location.'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $addr
     */
    private static function cityFromAddress(array $addr): string
    {
        $c = self::firstStr($addr, ['city', 'town', 'municipality']);
        if ($c !== '') {
            return $c;
        }

        return self::firstStr($addr, ['city_district']);
    }

    /** @param mixed $v */
    private static function scalarStr($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return trim((string) $v);
    }

    /**
     * @param array<string, mixed> $addr
     * @param list<string> $keys
     */
    private static function firstStr(array $addr, array $keys): string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $addr)) {
                continue;
            }
            $v = self::scalarStr($addr[$k]);
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
