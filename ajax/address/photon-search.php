<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/photon_forward.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed', 405);
}
Csrf::requireValid();

$q = isset($_POST['q']) && is_string($_POST['q']) ? $_POST['q'] : '';
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$res = PhotonForwardGeocoder::search($q, $ip);
if (empty($res['ok'])) {
    $msg = (string) ($res['error'] ?? 'Search failed.');
    $code = str_contains($msg, 'Too many searches') ? 429 : 400;
    Ajax::error($msg, $code);
}

/** @var list<array{label: string, detail: string, lat: float, lng: float}> $suggestions */
$suggestions = $res['suggestions'] ?? [];
Ajax::success(['suggestions' => $suggestions], '');
