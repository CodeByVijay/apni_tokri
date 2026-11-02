<?php

declare(strict_types=1);

/**
 * Straight-line distance (Haversine). Not road distance — use RoadDistanceProvider when configured (D4+).
 */
interface RoadDistanceProvider
{
    /** @return array{km: float, source: string}|null null when provider unavailable or not configured */
    public function roadKm(float $storeLat, float $storeLon, float $destLat, float $destLon): ?array;
}

final class HaversineRoadDistanceProvider implements RoadDistanceProvider
{
    public function roadKm(float $storeLat, float $storeLon, float $destLat, float $destLon): ?array
    {
        $km = DeliveryService::haversineKm($storeLat, $storeLon, $destLat, $destLon);

        return ['km' => $km, 'source' => 'haversine'];
    }
}

final class DeliveryDistanceService
{
    /** @return array{km: float, source: string}|null */
    public static function distanceFromStore(float $destLat, float $destLon, ?RoadDistanceProvider $road = null): ?array
    {
        $store = DeliveryConfig::store();
        $lat = (float) ($store['latitude'] ?? 0);
        $lon = (float) ($store['longitude'] ?? 0);
        if ($lat === 0.0 && $lon === 0.0) {
            return null;
        }

        $mode = (string) ($store['default_distance_mode'] ?? 'haversine');
        $providerSlug = (string) ($store['road_distance_provider'] ?? 'none');

        if ($mode === 'road' && $providerSlug !== 'none' && $road !== null) {
            $roadResult = $road->roadKm($lat, $lon, $destLat, $destLon);
            if ($roadResult !== null) {
                return $roadResult;
            }
        }

        return [
            'km' => DeliveryService::haversineKm($lat, $lon, $destLat, $destLon),
            'source' => 'haversine',
        ];
    }
}
