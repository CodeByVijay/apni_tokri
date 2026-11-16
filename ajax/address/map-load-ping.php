<?php

declare(strict_types=1);

/**
 * Client-side Google Maps load accounting.
 * The Maps JavaScript API loads in the browser, so server-side metering cannot
 * see it directly. The storefront pings this endpoint once per map open so map
 * usage appears on the geocoding usage dashboard as provider `google_maps_js`.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/provider_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/usage_meter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$ok = isset($_POST['ok']) && $_POST['ok'] === '1';

// Cheap dedup: repeated map opens within a few seconds count as one load.
$last = isset($_SESSION['at_maps_load_ping_at']) && is_int($_SESSION['at_maps_load_ping_at'])
    ? $_SESSION['at_maps_load_ping_at'] : 0;
if (time() - $last >= 10) {
    $_SESSION['at_maps_load_ping_at'] = time();
    GeocodingUsageMeter::record('google_maps_js', 'map_load', $ok, null, 0);
}

Ajax::success(['recorded' => true], '');
