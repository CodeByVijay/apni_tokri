<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/photon_forward.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/provider_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/usage_meter.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$q = isset($_POST['q']) && is_string($_POST['q']) ? $_POST['q'] : '';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (!GeoProviderSettings::providerEnabled('photon') || !GeocodingUsageMeter::quotaAllow('photon')) {
    Ajax::error('Address search is temporarily unavailable. You can still enter your address manually.', 503);
}

$t0 = microtime(true);
$res = PhotonForwardGeocoder::search($q, $ip);
GeocodingUsageMeter::record('photon', 'search', !empty($res['ok']), null, (int) ((microtime(true) - $t0) * 1000));
if (empty($res['ok'])) {
    $msg = (string) ($res['error'] ?? 'Search failed.');
    $code = str_contains($msg, 'Too many searches') ? 429 : 400;
    Ajax::error($msg, $code);
}

/** @var list<array{label: string, detail: string, lat: float, lng: float}> $suggestions */
$suggestions = $res['suggestions'] ?? [];
Ajax::success(['suggestions' => $suggestions], '');
