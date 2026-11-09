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

    /** Second-pass Photon layers: address-like rows only (excludes generic "other" POI/parks). */
    private const DEFAULT_SECOND_PASS_LAYERS = ['house', 'street', 'locality', 'district', 'city'];

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
        // Admin UI (AppSettings) overrides file config when Photon is the storefront provider.
        if (class_exists('AddressAutocompleteSettings', false)
            && AddressAutocompleteSettings::isEnabled()
            && AddressAutocompleteSettings::provider() === AddressAutocompleteSettings::PROVIDER_PHOTON) {
            $p['limit'] = AddressAutocompleteSettings::maxResults();
            $p['timeout_seconds'] = AddressAutocompleteSettings::timeoutSeconds();
            $bp = AddressAutocompleteSettings::biasPoint();
            $p['bias_lat'] = $bp['lat'];
            $p['bias_lon'] = $bp['lng'];
            $cc = AddressAutocompleteSettings::countryRestrictions();
            if ($cc === ['IN'] || $cc === ['in']) {
                // keep default bbox
            }
        }

        return $p;
    }

    public static function isEnabled(): bool
    {
        $p = self::cfg();

        return !isset($p['enabled']) || $p['enabled'] !== false;
    }

    /**
     * @return array{
     *   base: string, limit: int, fetch_limit: int, lang: string, bbox: string, bias_lat: float, bias_lon: float,
     *   zoom: int, location_bias_scale: float, timeout: int, cache_ttl: int, rpm_ip: int, second_pass_layers: list<string>
     * }
     */
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
        $fetchLimit = isset($p['fetch_limit']) && is_numeric($p['fetch_limit']) ? (int) $p['fetch_limit'] : max(12, $limit + 5);
        $fetchLimit = max($limit, min(30, $fetchLimit));

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

        $zoom = isset($p['zoom']) && is_numeric($p['zoom']) ? (int) $p['zoom'] : 17;
        $zoom = max(10, min(18, $zoom));

        $biasScale = isset($p['location_bias_scale']) && is_numeric($p['location_bias_scale'])
            ? (float) $p['location_bias_scale'] : 0.38;
        $biasScale = max(0.0, min(1.0, $biasScale));

        $secondLayers = self::DEFAULT_SECOND_PASS_LAYERS;
        if (isset($p['second_pass_layers']) && is_array($p['second_pass_layers'])) {
            $allowed = ['house', 'street', 'locality', 'district', 'city', 'county', 'state', 'country'];
            $custom = [];
            foreach ($p['second_pass_layers'] as $layer) {
                if (is_string($layer) && in_array($layer, $allowed, true)) {
                    $custom[] = $layer;
                }
            }
            if ($custom !== []) {
                $secondLayers = array_values(array_unique($custom));
            }
        }

        return [
            'base' => $base,
            'limit' => $limit,
            'fetch_limit' => $fetchLimit,
            'lang' => $lang,
            'bbox' => $bbox,
            'bias_lat' => $biasLat,
            'bias_lon' => $biasLon,
            'zoom' => $zoom,
            'location_bias_scale' => $biasScale,
            'timeout' => $timeout,
            'cache_ttl' => $cacheTtl,
            'rpm_ip' => $rpm,
            'second_pass_layers' => $secondLayers,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, suggestions?: list<array<string, mixed>>}
     */
    /**
     * @param string|null $context Pass `checkout` for checkout autocomplete (no map on form); default keeps legacy copy for map picker.
     */
    public static function search(string $query, string $clientIp, ?string $context = null): array
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
        $parsedHouse = self::parseLeadingHouseNumber($q);
        $cacheKey = 'photon_v8_' . hash(
            'sha256',
            $r['base'] . '|' . $q . '|' . $r['limit'] . '|' . $r['fetch_limit'] . '|' . $r['lang'] . '|' . $r['bbox']
            . '|' . $r['bias_lat'] . '|' . $r['bias_lon'] . '|' . $r['zoom'] . '|' . $r['location_bias_scale']
            . '|' . implode(',', $r['second_pass_layers'])
        );
        $cached = self::cacheGet($cacheKey, $r['cache_ttl']);
        if ($cached !== null) {
            return ['ok' => true, 'suggestions' => $cached];
        }

        // Up to two upstream calls (merge + re-rank); throttle counts both when needed.
        if (!self::throttleAllow($clientIp)) {
            return ['ok' => false, 'error' => 'Too many searches. Please wait a moment and try again.'];
        }

        $pass1 = self::photonHttpGet($r, $q, false, true);
        if (!$pass1['ok']) {
            return ['ok' => false, 'error' => (string) ($pass1['error'] ?? 'Address search failed.')];
        }

        $featuresPass1 = $pass1['features'];

        $featuresPass2 = [];
        if (!self::throttleAllow($clientIp)) {
            // Still use pass1 only if second request would exceed throttle.
            $merged = $featuresPass1;
        } else {
            $q2 = self::stripLeadingHouseNumber($q);
            if (mb_strlen($q2) < 3) {
                $q2 = $q;
            }
            if (!preg_match('/\bindore\b/iu', $q2)) {
                $q2 = trim($q2 . ' Indore');
            }
            // Layer filters need a disambiguated query ("Bajrang Nagar" alone matches many cities).
            $pass2 = self::photonHttpGet($r, $q2, true, true);
            if (!empty($pass2['ok'])) {
                $featuresPass2 = $pass2['features'];
            }
            $merged = self::mergeFeatures($featuresPass1, $featuresPass2);
        }

        $rankTake = min(30, max($r['limit'], (int) ceil($r['limit'] * 1.75)));
        $ranked = self::rankAndDedupeFeatures($merged, $q, $parsedHouse !== null, $parsedHouse, self::significantQueryTokens($q), $rankTake);
        $suggestions = self::buildSuggestions($ranked, $q, $parsedHouse, $r['limit'], $context);

        self::cacheSet($cacheKey, $suggestions);

        return ['ok' => true, 'suggestions' => $suggestions];
    }

    /**
     * @param array<string, mixed> $r resolved config
     * @return array{ok: bool, error?: string, features?: list<array<string, mixed>>}
     */
    private static function photonHttpGet(array $r, string $q, bool $layerFilter, bool $useBbox): array
    {
        $params = [
            'q' => $q,
            'limit' => (string) $r['fetch_limit'],
            'lang' => $r['lang'],
            'lat' => (string) $r['bias_lat'],
            'lon' => (string) $r['bias_lon'],
            'zoom' => (string) $r['zoom'],
            'location_bias_scale' => (string) $r['location_bias_scale'],
            'dedupe' => '1',
        ];
        if ($useBbox) {
            $params['bbox'] = $r['bbox'];
        }
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($layerFilter) {
            foreach ($r['second_pass_layers'] as $layer) {
                $query .= '&layer=' . rawurlencode((string) $layer);
            }
        }
        $url = $r['base'] . '?' . $query;

        $base = BusinessSettings::websiteUrl();
        $ua = 'VeggieTokriPhoton/1.0 (+' . $base . ')';

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

        return ['ok' => true, 'features' => $features];
    }

    private static function stripLeadingHouseNumber(string $q): string
    {
        $t = trim(preg_replace('/^\s*\d{1,6}[A-Za-z]?\s+/u', '', $q) ?? $q);

        return trim($t);
    }

    /**
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     * @return list<array<string, mixed>>
     */
    private static function mergeFeatures(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($a, $b) as $feat) {
            if (!is_array($feat)) {
                continue;
            }
            $key = self::featureDedupeKey($feat);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $feat;
        }

        return $out;
    }

    private static function featureDedupeKey(array $feat): string
    {
        $props = isset($feat['properties']) && is_array($feat['properties']) ? $feat['properties'] : [];
        $type = isset($props['osm_type']) && is_string($props['osm_type']) ? $props['osm_type'] : '';
        $id = isset($props['osm_id']) && is_numeric($props['osm_id']) ? (string) $props['osm_id'] : '';
        if ($type !== '' && $id !== '') {
            return $type . ':' . $id;
        }
        $geom = $feat['geometry'] ?? null;
        $coords = is_array($geom) ? ($geom['coordinates'] ?? null) : null;
        if (is_array($coords) && count($coords) >= 2) {
            return 'c:' . round((float) $coords[0], 4) . ':' . round((float) $coords[1], 4);
        }

        return 'h:' . hash('sha256', (string) json_encode($feat));
    }

    /**
     * @param list<array<string, mixed>> $features
     * @param list<string> $sigTokens
     * @return list<array{feat: array<string, mixed>, score: float}>
     */
    private static function rankAndDedupeFeatures(array $features, string $query, bool $hasHouseInQuery, ?string $houseDigits, array $sigTokens, int $take): array
    {
        $indexed = [];
        foreach ($features as $i => $feat) {
            if (!is_array($feat)) {
                continue;
            }
            if (!self::featurePointCoords($feat)) {
                continue;
            }
            $score = self::featureScore($feat, $i, $hasHouseInQuery, $houseDigits, $query, $sigTokens);
            $indexed[] = ['feat' => $feat, 'score' => $score];
        }
        usort($indexed, static function (array $x, array $y): int {
            return $x['score'] <=> $y['score'];
        });

        return array_slice($indexed, 0, max(1, $take));
    }

    /**
     * @return array<string, mixed>|null [lat, lng] or null
     */
    private static function featurePointCoords(array $feat): ?array
    {
        $geom = $feat['geometry'] ?? null;
        if (!is_array($geom) || ($geom['type'] ?? '') !== 'Point') {
            return null;
        }
        $coords = $geom['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }
        $lng = (float) $coords[0];
        $lat = (float) $coords[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * @param list<string> $sigTokens
     */
    private static function featureScore(array $feat, int $photonIndex, bool $hasHouseInQuery, ?string $houseDigits, string $rawQuery, array $sigTokens): float
    {
        $props = isset($feat['properties']) && is_array($feat['properties']) ? $feat['properties'] : [];
        $pType = isset($props['type']) && is_string($props['type']) ? $props['type'] : 'other';
        $osmKey = isset($props['osm_key']) && is_string($props['osm_key']) ? $props['osm_key'] : '';
        $osmVal = isset($props['osm_value']) && is_string($props['osm_value']) ? $props['osm_value'] : '';
        $name = mb_strtolower(self::str($props, 'name'));
        $street = mb_strtolower(self::str($props, 'street'));
        $housenumber = mb_strtolower(self::str($props, 'housenumber'));
        $locality = mb_strtolower(self::str($props, 'locality'));
        $district = mb_strtolower(self::str($props, 'district'));
        $city = mb_strtolower(self::str($props, 'city'));

        $score = (float) ($photonIndex * 2.5);

        // Token overlap (after stripping leading house number from query) nudges toward textual matches.
        $qNorm = mb_strtolower(trim(preg_replace('/^\s*\d+[A-Za-z]?\s+/u', '', $rawQuery) ?? $rawQuery));
        $haystack = $name . ' ' . $street . ' ' . $housenumber . ' ' . $locality . ' ' . $district . ' ' . $city;
        $tokenBonus = self::tokenOverlapScore($qNorm, $haystack);
        $score -= $tokenBonus;

        $match = self::significantTokenMatch($sigTokens, $haystack);

        if ($sigTokens !== []) {
            if ($match['all']) {
                $score -= 115.0;
            } elseif ($match['any'] && count($sigTokens) >= 2) {
                // Ambiguous POI matches only part of the area name — usually noise when a door number was typed.
                $score += 95.0;
            } elseif (!$match['any']) {
                $score += 210.0;
            }
        }

        if ($hasHouseInQuery) {
            if ($pType === 'house') {
                $score -= 55.0;
            }
            if ($pType === 'street') {
                $score -= 38.0;
            }
            if (in_array($pType, ['locality', 'district'], true)) {
                $score -= 22.0;
            }
            if ($pType === 'city') {
                $score -= 12.0;
            }
            $isGreenPoi = $osmKey === 'leisure' && ($osmVal === 'park' || $osmVal === 'garden');
            if ($isGreenPoi) {
                // Parks rank as fallbacks for door-number searches unless they name the area (handled via sigTokens).
                $score += $match['all'] ? 28.0 : 130.0;
            }
            if ($osmKey === 'natural') {
                $score += 70.0;
            }
            if ($osmKey === 'tourism') {
                $score += 45.0;
            }
            if ($pType === 'other' && (str_contains($name, 'park') || str_contains($name, 'garden')) && !$isGreenPoi) {
                $score += 85.0;
            }
            if ($houseDigits !== null && $houseDigits !== '' && str_contains($housenumber, mb_strtolower($houseDigits))) {
                $score -= 40.0;
            }
        } else {
            if (in_array($pType, ['street', 'locality'], true)) {
                $score -= 18.0;
            }
            if ($pType === 'house') {
                $score -= 10.0;
            }
            if ($osmKey === 'leisure' && ($osmVal === 'park' || $osmVal === 'garden')) {
                $score += 18.0;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private static function significantQueryTokens(string $q): array
    {
        $t = mb_strtolower(trim(preg_replace('/^\s*\d+[A-Za-z]?\s+/u', '', $q) ?? $q));
        $stop = ['indore', 'ind', 'mp', 'madhya', 'pradesh', 'india', 'city', 'near', 'the', 'and', 'road', 'rd'];
        $parts = preg_split('/[\s,]+/u', $t) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (in_array($p, $stop, true)) {
                continue;
            }
            $out[] = $p;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $tokens
     * @return array{all: bool, any: bool}
     */
    private static function significantTokenMatch(array $tokens, string $haystack): array
    {
        if ($tokens === []) {
            return ['all' => false, 'any' => false];
        }
        $all = true;
        $any = false;
        foreach ($tokens as $tok) {
            if (mb_strpos($haystack, $tok) !== false) {
                $any = true;
            } else {
                $all = false;
            }
        }

        return ['all' => $all, 'any' => $any];
    }

    private static function tokenOverlapScore(string $queryNorm, string $haystack): float
    {
        if ($queryNorm === '' || $haystack === '') {
            return 0.0;
        }
        $tokens = preg_split('/\s+/u', $queryNorm) ?: [];
        $n = 0.0;
        foreach ($tokens as $tok) {
            $t = trim((string) $tok);
            if (mb_strlen($t) < 2) {
                continue;
            }
            if (mb_strpos($haystack, $t) !== false) {
                $n += 4.0;
            }
        }

        return min(20.0, $n);
    }

    private static function parseLeadingHouseNumber(string $q): ?string
    {
        if (preg_match('/^\s*(\d{1,6}[A-Za-z]?)\b/u', $q, $m)) {
            return (string) $m[1];
        }

        return null;
    }

    /**
     * @param list<array{feat: array<string, mixed>, score: float}> $ranked
     * @return list<array<string, mixed>>
     */
    private static function buildSuggestions(array $ranked, string $rawQuery, ?string $houseDigits, int $limit, ?string $context): array
    {
        $out = [];
        foreach ($ranked as $row) {
            $feat = $row['feat'];
            $coords = self::featurePointCoords($feat);
            if ($coords === null) {
                continue;
            }
            $props = isset($feat['properties']) && is_array($feat['properties']) ? $feat['properties'] : [];
            $built = self::formatSuggestionRich($props);
            if ($built['label'] === '') {
                continue;
            }
            $note = self::relevanceNote($props, $houseDigits);
            $out[] = [
                'label' => $built['label'],
                'detail' => $built['detail'],
                'category' => $built['category'],
                'type' => $built['type'],
                'housenumber' => self::str($props, 'housenumber'),
                'osm_key' => $built['osm_key'],
                'osm_value' => $built['osm_value'],
                'feature_id' => self::featureDedupeKey($feat),
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'relevance_note' => $note,
            ];
        }

        // If the customer typed a house number, OSM often has no matching object — never imply we resolved that unit.
        if ($houseDigits !== null && $out !== []) {
            $want = mb_strtolower($houseDigits);
            $haveExactHouse = false;
            foreach ($out as $s) {
                if (($s['type'] ?? '') !== 'house') {
                    continue;
                }
                $hn = mb_strtolower(trim((string) preg_replace('/\s+/u', '', (string) ($s['housenumber'] ?? ''))));
                $wantNorm = preg_replace('/\s+/u', '', $want) ?? $want;
                if ($hn !== '' && ($hn === $wantNorm || str_contains($hn, $wantNorm))) {
                    $haveExactHouse = true;
                    break;
                }
            }
            if (!$haveExactHouse) {
                $detail = ($context === 'checkout')
                    ? 'OpenStreetMap may not list that house number. Tap an approximate area below, then complete your house/building details.'
                    : 'OpenStreetMap may not list that house number. Tap an area below, then drag the pin on the map.';
                array_unshift($out, [
                    'label' => 'No exact building found for "' . $houseDigits . '"',
                    'detail' => $detail,
                    'category' => 'Notice',
                    'type' => 'notice',
                    'osm_key' => '',
                    'osm_value' => '',
                    'feature_id' => 'notice:no-exact-house',
                    'lat' => null,
                    'lng' => null,
                    'relevance_note' => '',
                    'disabled' => true,
                ]);
            }
        }

        $out = self::dedupeSuggestions($out);

        $cap = $limit + 1;
        if (count($out) > $cap) {
            $out = array_slice($out, 0, $cap);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function dedupeSuggestions(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $fid = isset($row['feature_id']) && is_string($row['feature_id']) ? $row['feature_id'] : '';
            if ($fid !== '') {
                $key = $fid;
            } else {
                $label = mb_strtolower(trim((string) ($row['label'] ?? '')));
                $lat = isset($row['lat']) && is_numeric($row['lat']) ? round((float) $row['lat'], 4) : '';
                $lng = isset($row['lng']) && is_numeric($row['lng']) ? round((float) $row['lng'], 4) : '';
                $key = ($row['type'] ?? '') . '|' . $label . '|' . $lat . '|' . $lng;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $props
     * @return array{label: string, detail: string, category: string, type: string, osm_key: string, osm_value: string}
     */
    private static function formatSuggestionRich(array $props): array
    {
        $name = self::str($props, 'name');
        $housenumber = self::str($props, 'housenumber');
        $street = self::str($props, 'street');
        $district = self::str($props, 'district');
        $city = self::str($props, 'city');
        $locality = self::str($props, 'locality');
        $state = self::str($props, 'state');
        $postcode = self::str($props, 'postcode');
        $pType = isset($props['type']) && is_string($props['type']) ? $props['type'] : 'other';
        $osmKey = isset($props['osm_key']) && is_string($props['osm_key']) ? $props['osm_key'] : '';
        $osmVal = isset($props['osm_value']) && is_string($props['osm_value']) ? $props['osm_value'] : '';

        $category = self::categoryLabel($pType, $osmKey, $osmVal);

        $primary = $name;
        if ($primary === '') {
            $line1 = trim($housenumber !== '' && $street !== '' ? $housenumber . ', ' . $street : ($street !== '' ? $street : ''));
            $primary = $line1 !== '' ? $line1 : $locality;
        }
        if ($primary === '' && $district !== '') {
            $primary = $district;
        }
        if ($primary === '') {
            $primary = $city;
        }

        $line2Parts = [];
        if ($street !== '' && $street !== $primary && !str_contains($primary, $street)) {
            $line2Parts[] = $street;
        }
        if ($locality !== '' && $locality !== $primary && !in_array($locality, $line2Parts, true)) {
            $line2Parts[] = $locality;
        }
        if ($district !== '' && $district !== $primary && !in_array($district, $line2Parts, true)) {
            $line2Parts[] = $district;
        }
        if ($postcode !== '') {
            $line2Parts[] = $postcode;
        }
        if ($city !== '' && $city !== $primary && !in_array($city, $line2Parts, true)) {
            $line2Parts[] = $city;
        }
        if ($state !== '' && !in_array($state, $line2Parts, true)) {
            $line2Parts[] = $state;
        }

        $detail = implode(' · ', array_values(array_unique(array_filter($line2Parts, static fn ($x) => $x !== ''))));

        return [
            'label' => $primary,
            'detail' => $detail,
            'category' => $category,
            'type' => $pType,
            'osm_key' => $osmKey,
            'osm_value' => $osmVal,
        ];
    }

    private static function categoryLabel(string $pType, string $osmKey, string $osmVal): string
    {
        if ($pType === 'house') {
            return $osmKey !== '' && $osmVal !== '' ? 'Address · ' . $osmKey . '/' . $osmVal : 'Address / building';
        }
        if ($pType === 'street') {
            return 'Street / road';
        }
        if (in_array($pType, ['locality', 'district'], true)) {
            return 'Area · ' . $pType;
        }
        if ($pType === 'city') {
            return 'City';
        }
        if ($osmKey !== '' && $osmVal !== '') {
            return $osmKey . ' · ' . $osmVal;
        }

        return $pType !== 'other' ? $pType : 'Place';
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function relevanceNote(array $props, ?string $houseDigits): string
    {
        $pType = isset($props['type']) && is_string($props['type']) ? $props['type'] : 'other';
        $osmKey = isset($props['osm_key']) && is_string($props['osm_key']) ? $props['osm_key'] : '';
        $osmVal = isset($props['osm_value']) && is_string($props['osm_value']) ? $props['osm_value'] : '';
        $hn = self::str($props, 'housenumber');

        if ($houseDigits !== null && $houseDigits !== '') {
            if ($osmKey === 'leisure' && ($osmVal === 'park' || $osmVal === 'garden')) {
                return 'Landmark / green space — not a verified house number. Drag the pin to your building.';
            }
            if ($pType === 'house' && $hn !== '' && !str_contains(mb_strtolower($hn), mb_strtolower($houseDigits))) {
                return 'Building/POI match — house number in map data differs from your search. Verify on the map.';
            }
            if ($pType === 'house' && $hn === '') {
                return 'Map point — no house number in data for this place. Drag the pin to your entrance.';
            }
            if ($pType !== 'house' && $pType !== 'street') {
                return 'Approximate area — exact door number is rarely in map search. Place the pin at your entrance.';
            }
        }

        return '';
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
     * @return list<array<string, mixed>>|null
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
     * @param list<array<string, mixed>> $suggestions
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
