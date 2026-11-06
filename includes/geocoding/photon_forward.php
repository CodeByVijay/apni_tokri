<?php

declare(strict_types=1);

/**
 * Forward geocoding / autocomplete via Photon (OSM-backed search).
 *
 * @see https://photon.komoot.io/ — public demo instance; reasonable use only, not a SLA.
 * For production at scale: self-host Photon (https://github.com/komoot/photon) or Pelias,
 * then set `geocoding.photon.base_url` in config to your instance. Do not rely on komoot's
 * public server for heavy traffic; this class adds cache + per-IP throttling to reduce load.
 *
 * Nominatim must not be used for search/autocomplete (OSMF usage policy / load); Photon is used here.
 */
final class PhotonForwardGeocoder
{
    public const DEFAULT_BASE = 'https://photon.komoot.io/api/';

    /** @return array<string, mixed> */
    private static function cfg(): array
    {
        try {
            $all = App::config();
            $g = isset($all['geocoding']) && is_array($all['geocoding']) ? $all['geocoding'] : [];
            $p = isset($g['photon']) && is_array($g['photon']) ? $g['photon'] : [];
        } catch (Throwable $e) {
            $p = [];
        }

        return $p;
    }

    public static function isEnabled(): bool
    {
        $p = self::cfg();

        return !isset($p['enabled']) || $p['enabled'] !== false;
    }

    /** @return array{base: string, limit: int, lang: string, bbox: string, bias_lat: float, bias_lon: float, timeout: int, cache_ttl: int, rpm_ip: int} */
    private static function resolved(): array
    {
        $p = self::cfg();
        $base = isset($p['base_url']) && is_string($p['base_url']) && trim($p['base_url']) !== ''
            ? rtrim(trim($p['base_url']), '/') . '/'
            : self::DEFAULT_BASE;
        if (!preg_match('#^https://[^\s"\'<>]+$#', $base)) {
            $base = self::DEFAULT_BASE;
        }

        $limit = isset($p['limit']) && is_numeric($p['limit']) ? (int) $p['limit'] : 7;
        $limit = max(1, min(15, $limit));

        $lang = isset($p['lang']) && is_string($p['lang']) && preg_match('/^[a-z]{2}$/i', $p['lang'])
            ? strtolower($p['lang']) : 'en';

        $bbox = '';
        if (isset($p['bbox']) && is_string($p['bbox'])) {
            $bbox = trim($p['bbox']);
            if (!preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $bbox)) {
                $bbox = '';
            }
        }
        if ($bbox === '') {
            // Indore & vicinity — min_lon,min_lat,max_lon,max_lat (Photon bbox order).
            $bbox = '75.45,22.45,76.15,22.92';
        }

        $map = MapConfig::settings();
        $biasLat = isset($p['bias_lat']) && is_numeric($p['bias_lat']) ? (float) $p['bias_lat'] : (float) $map['default_lat'];
        $biasLon = isset($p['bias_lon']) && is_numeric($p['bias_lon']) ? (float) $p['bias_lon'] : (float) $map['default_lng'];

        $timeout = isset($p['timeout_seconds']) && is_numeric($p['timeout_seconds']) ? (int) $p['timeout_seconds'] : 8;
        $timeout = max(3, min(20, $timeout));

        $cacheTtl = isset($p['cache_ttl_seconds']) && is_numeric($p['cache_ttl_seconds']) ? (int) $p['cache_ttl_seconds'] : 120;
        $cacheTtl = max(30, min(3600, $cacheTtl));

        $rpm = isset($p['max_requests_per_minute_per_ip']) && is_numeric($p['max_requests_per_minute_per_ip'])
            ? (int) $p['max_requests_per_minute_per_ip'] : 40;
        $rpm = max(5, min(120, $rpm));

        return [
            'base' => $base,
            'limit' => $limit,
            'lang' => $lang,
            'bbox' => $bbox,
            'bias_lat' => $biasLat,
            'bias_lon' => $biasLon,
            'timeout' => $timeout,
            'cache_ttl' => $cacheTtl,
            'rpm_ip' => $rpm,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, suggestions?: list<array{label: string, detail: string, lat: float, lng: float}>}
     */
    public static function search(string $query, string $clientIp): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'error' => 'Address search is not available.'];
        }

        $q = trim($query);
        if (mb_strlen($q) < 3) {
            return ['ok' => false, 'error' => 'Type at least 3 characters to search.'];
        }
        if (mb_strlen($q) > 200) {
            $q = mb_substr($q, 0, 200);
        }

        $r = self::resolved();
        $cacheKey = 'photon_' . hash('sha256', $r['base'] . '|' . $q . '|' . $r['limit'] . '|' . $r['lang'] . '|' . $r['bbox'] . '|' . $r['bias_lat'] . '|' . $r['bias_lon']);
        $cached = self::cacheGet($cacheKey, $r['cache_ttl']);
        if ($cached !== null) {
            return ['ok' => true, 'suggestions' => $cached];
        }

        if (!self::throttleAllow($clientIp)) {
            return ['ok' => false, 'error' => 'Too many searches. Please wait a moment and try again.'];
        }

        $params = [
            'q' => $q,
            'limit' => (string) $r['limit'],
            'lang' => $r['lang'],
            'lat' => (string) $r['bias_lat'],
            'lon' => (string) $r['bias_lon'],
            'bbox' => $r['bbox'],
        ];
        $url = $r['base'] . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $base = App::baseUrl();
        $ua = 'VeggieTokriPhoton/1.0 (+' . ($base !== '' ? $base : 'https://veggietokri.com') . ')';

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not reach address search.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ],
            CURLOPT_TIMEOUT => $r['timeout'],
            CURLOPT_CONNECTTIMEOUT => min(6, $r['timeout']),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'error' => $cerr !== '' ? 'Address search failed.' : 'Empty response from address search.'];
        }
        if ($status === 429) {
            return ['ok' => false, 'error' => 'Address search is busy. Try again shortly.'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Address search could not complete.'];
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Invalid response from address search.'];
        }
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'Invalid response from address search.'];
        }

        /** @var list<array<string, mixed>> $features */
        $features = isset($json['features']) && is_array($json['features']) ? $json['features'] : [];
        $suggestions = [];
        foreach ($features as $feat) {
            if (!is_array($feat)) {
                continue;
            }
            $geom = $feat['geometry'] ?? null;
            if (!is_array($geom) || ($geom['type'] ?? '') !== 'Point') {
                continue;
            }
            $coords = $geom['coordinates'] ?? null;
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }
            $lng = (float) $coords[0];
            $lat = (float) $coords[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
                continue;
            }
            $props = isset($feat['properties']) && is_array($feat['properties']) ? $feat['properties'] : [];
            [$label, $detail] = self::formatSuggestion($props);
            if ($label === '') {
                continue;
            }
            $suggestions[] = ['label' => $label, 'detail' => $detail, 'lat' => $lat, 'lng' => $lng];
            if (count($suggestions) >= $r['limit']) {
                break;
            }
        }

        self::cacheSet($cacheKey, $suggestions);

        return ['ok' => true, 'suggestions' => $suggestions];
    }

    /**
     * @param array<string, mixed> $props
     * @return array{0: string, 1: string}
     */
    private static function formatSuggestion(array $props): array
    {
        $name = self::str($props, 'name');
        $street = self::str($props, 'street');
        $district = self::str($props, 'district');
        $city = self::str($props, 'city');
        $locality = self::str($props, 'locality');
        $state = self::str($props, 'state');
        $postcode = self::str($props, 'postcode');

        $primary = $name;
        if ($primary === '') {
            $primary = $street;
        }
        if ($primary === '') {
            $primary = $locality;
        }
        if ($primary === '' && $district !== '') {
            $primary = $district;
        }
        if ($primary === '') {
            $primary = $city;
        }

        $detailParts = array_filter([$street !== '' && $street !== $primary ? $street : '', $district, $city, $state, $postcode]);
        $detailParts = array_values(array_unique(array_filter($detailParts, static fn ($x) => $x !== '')));
        $detail = implode(', ', $detailParts);

        return [$primary, $detail];
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function str(array $props, string $key): string
    {
        $v = $props[$key] ?? '';

        return is_string($v) ? trim($v) : '';
    }

    private static function cacheDir(): string
    {
        return APNI_TOKRI_ROOT . '/storage/temporary';
    }

    /**
     * @return list<array{label: string, detail: string, lat: float, lng: float}>|null
     */
    private static function cacheGet(string $key, int $ttlSeconds): ?array
    {
        $path = self::cacheDir() . '/photon-cache-' . $key . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($data) || !isset($data['t'], $data['s']) || !is_numeric($data['t'])) {
            return null;
        }
        if ((time() - (int) $data['t']) > $ttlSeconds) {
            return null;
        }
        if (!is_array($data['s'])) {
            return null;
        }

        return $data['s'];
    }

    /**
     * @param list<array{label: string, detail: string, lat: float, lng: float}> $suggestions
     */
    private static function cacheSet(string $key, array $suggestions): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/photon-cache-' . $key . '.json';
        $payload = json_encode(['t' => time(), 's' => $suggestions], JSON_THROW_ON_ERROR);
        @file_put_contents($path, $payload, LOCK_EX);
    }

    private static function throttleAllow(string $ip): bool
    {
        $rpm = self::resolved()['rpm_ip'];
        if ($ip === '') {
            $ip = '0.0.0.0';
        }
        $safeIp = preg_replace('/[^0-9a-f.:]/i', '_', $ip) ?? 'unknown';
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $dir . '/photon-throttle-' . hash('sha256', $safeIp) . '.json';
        $now = time();
        $window = 60;
        $data = ['window_start' => $now, 'count' => 0];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && isset($decoded['window_start'], $decoded['count']) && is_numeric($decoded['window_start']) && is_numeric($decoded['count'])) {
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

    /** Expose safe settings for AT_MAP_CONFIG (no secrets). */
    public static function clientSnippet(): array
    {
        if (!self::isEnabled()) {
            return ['enabled' => false];
        }
        $r = self::resolved();

        return [
            'enabled' => true,
            'minChars' => 3,
            'debounceMs' => 400,
            'limit' => $r['limit'],
        ];
    }
}
