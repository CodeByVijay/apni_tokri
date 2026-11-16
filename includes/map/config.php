<?php

declare(strict_types=1);

/**
 * Map tile provider configuration (Leaflet + OpenStreetMap). No API key is needed or supported.
 *
 * Override any value in includes/config.local.php under the 'map' key, e.g.
 *   'map' => ['tile_url' => 'https://tiles.example.org/{z}/{x}/{y}.png', 'attribution' => '&copy; ...']
 *
 * The public tile.openstreetmap.org service has a usage policy (https://operations.osmfoundation.org/policies/tiles/):
 * valid Referer/User-Agent from the browser, caching headers respected, no bulk download / prefetching, and
 * it is not meant for heavy production traffic. Point tile_url at your own or a commercial/self-hosted
 * provider when traffic grows; nothing here silently switches provider.
 */
final class MapConfig
{
    public const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const DEFAULT_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';

    /** @return array{tile_url: string, attribution: string, max_zoom: int, default_lat: float, default_lng: float, default_zoom: int} */
    public static function settings(): array
    {
        $cfg = [];
        try {
            $all = App::config();
            $cfg = isset($all['map']) && is_array($all['map']) ? $all['map'] : [];
        } catch (Throwable $e) {
            $cfg = [];
        }

        $tile = isset($cfg['tile_url']) && is_string($cfg['tile_url']) ? trim($cfg['tile_url']) : '';
        if ($tile === '' || !preg_match('#^https://[^\s"\'<>]+\{z\}[^\s"\'<>]*\{x\}[^\s"\'<>]*\{y\}[^\s"\'<>]*$#', $tile)) {
            $tile = self::DEFAULT_TILE_URL;
        }
        $attr = isset($cfg['attribution']) && is_string($cfg['attribution']) && trim($cfg['attribution']) !== ''
            ? (string) $cfg['attribution'] : self::DEFAULT_ATTRIBUTION;

        $lat = 22.7196;
        $lng = 75.8577;
        try {
            $store = DeliveryConfig::store();
            if (isset($store['latitude'], $store['longitude']) && is_numeric($store['latitude']) && is_numeric($store['longitude'])) {
                $lat = (float) $store['latitude'];
                $lng = (float) $store['longitude'];
            }
        } catch (Throwable $e) {
            // Keep Indore defaults when the delivery store is not configured.
        }
        if (isset($cfg['default_lat'], $cfg['default_lng']) && is_numeric($cfg['default_lat']) && is_numeric($cfg['default_lng'])) {
            $lat = (float) $cfg['default_lat'];
            $lng = (float) $cfg['default_lng'];
        }

        $maxZoom = isset($cfg['max_zoom']) && is_numeric($cfg['max_zoom']) ? max(1, min(20, (int) $cfg['max_zoom'])) : 19;
        $zoom = isset($cfg['default_zoom']) && is_numeric($cfg['default_zoom']) ? max(3, min($maxZoom, (int) $cfg['default_zoom'])) : 13;

        return [
            'tile_url' => $tile,
            'attribution' => $attr,
            'max_zoom' => $maxZoom,
            'default_lat' => $lat,
            'default_lng' => $lng,
            'default_zoom' => $zoom,
        ];
    }

    /** @return array<string, mixed> JSON-safe client config for assets/js/address-map.js */
    public static function clientConfig(): array
    {
        $s = self::settings();
        $photon = self::photonClientConfig();

        return [
            'tileUrl' => $s['tile_url'],
            'attribution' => $s['attribution'],
            'maxZoom' => $s['max_zoom'],
            'center' => [$s['default_lat'], $s['default_lng']],
            'zoom' => $s['default_zoom'],
            'imagePath' => asset_url('assets/vendor/leaflet/images/'),
            'photon' => $photon,
        ];
    }

    /**
     * Photon forward-search settings for checkout map (no API keys).
     *
     * @return array<string, mixed>
     */
    private static function photonClientConfig(): array
    {
        require_once APNI_TOKRI_ROOT . '/includes/geocoding/photon_forward.php';

        return PhotonForwardGeocoder::clientSnippet();
    }

    /** Public OpenStreetMap link for a point (used only in authenticated admin views). */
    public static function osmLink(float $lat, float $lng, int $zoom = 18): string
    {
        return 'https://www.openstreetmap.org/?mlat=' . rawurlencode(sprintf('%.7F', $lat))
            . '&mlon=' . rawurlencode(sprintf('%.7F', $lng))
            . '#map=' . $zoom . '/' . rawurlencode(sprintf('%.6F', $lat)) . '/' . rawurlencode(sprintf('%.6F', $lng));
    }
}
