<?php

declare(strict_types=1);

/**
 * Phase 4 — geocoding provider settings, routing, metering, quotas,
 * credential masking/removal, against a disposable database.
 *
 * CLI: php scripts/run-in-disposable-db.php scripts/test-geocoding-providers.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/disposable_db_runner.php';
require_once __DIR__ . '/lib/test_script_safety.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/provider_settings.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/usage_meter.php';
require_once APNI_TOKRI_ROOT . '/includes/geocoding/router.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_admin.php';

if (PHP_SAPI !== 'cli') {
    exit(2);
}
TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();

$fail = 0;
function gOk(bool $c, string $l): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $l . PHP_EOL;
    if (!$c) {
        ++$fail;
    }
}

$pdo = Database::pdo();

// Apply migration if the runner predates it.
if (!GeocodingUsageMeter::tableReady()) {
    $pdo->exec((string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.43.0_geocoding_usage.sql'));
    GeocodingUsageMeter::resetTableCheck();
}
gOk(GeocodingUsageMeter::tableReady(), 'usage table exists (migration applied)');

// Clean slate for the keys under test.
$keys = [];
foreach (['geocoding.%', 'address_autocomplete.%', 'google_places.%'] as $like) {
    $st = $pdo->prepare('DELETE FROM settings WHERE setting_key LIKE ?');
    $st->execute([$like]);
}
$pdo->exec('DELETE FROM geocoding_usage_daily');
AppSettings::resetCache();

// --- Defaults ---
gOk(GeoProviderSettings::providerEnabled('photon'), 'default: photon enabled');
gOk(GeoProviderSettings::providerEnabled('google'), 'default: google enabled');
gOk(GeoProviderSettings::searchFallback() === null, 'default: no fallback');
gOk(GeoProviderSettings::reverseEnabled(), 'default: reverse enabled');
gOk(GeoProviderSettings::reverseProvider() === 'nominatim', 'default: reverse provider nominatim');
gOk(GeoProviderSettings::quota('google') === ['daily' => 0, 'monthly' => 0], 'default: google quota unlimited');
gOk(!GeoProviderSettings::providerUsable('google'), 'no key: google not usable');
gOk(GeoProviderSettings::providerUsable('photon'), 'no key needed: photon usable');
gOk(AddressAutocompleteSettings::provider() === 'photon', 'default primary = photon');
gOk(GeoProviderSettings::searchOrder() === ['photon'], 'search order = primary only');

// --- Admin save: routing + quotas + switches ---
$r = AddressAutocompleteAdminSettings::saveFromPost([
    'address_autocomplete_enabled' => '1',
    'address_autocomplete_provider' => 'google',
    'geocoding_search_fallback' => 'photon',
    'geocoding_provider_google_enabled' => '1',
    'geocoding_provider_nominatim_enabled' => '1',
    // photon intentionally unchecked -> disabled
    'geocoding_quota_google_daily' => '25',
    'geocoding_quota_google_monthly' => '500',
    'geocoding_quota_photon_daily' => '100',
    'geocoding_reverse_enabled' => '1',
    'geocoding_reverse_provider' => 'google',
    'geocoding_reverse_fallback' => 'nominatim',
    'google_places_api_key' => 'AIzaTESTKEY1234567890abcdef',
]);
AppSettings::resetCache();
gOk(!empty($r['ok']), 'admin save accepts Phase 4 fields');
gOk(AddressAutocompleteSettings::provider() === 'google', 'primary = google');
gOk(GeoProviderSettings::searchFallback() === 'photon', 'fallback = photon');
gOk(GeoProviderSettings::searchOrder() === ['google', 'photon'], 'order = google then photon');
gOk(!GeoProviderSettings::providerEnabled('photon'), 'photon emergency-disabled');
gOk(GeoProviderSettings::providerEnabled('google'), 'google enabled');
gOk(GeoProviderSettings::quota('google') === ['daily' => 25, 'monthly' => 500], 'google quotas saved');
gOk(GeoProviderSettings::reverseProvider() === 'google', 'reverse provider = google');
gOk(GeoProviderSettings::reverseOrder() === ['google', 'nominatim'], 'reverse order = google then nominatim');
gOk(AddressAutocompleteSettings::googleKeyConfigured(), 'google key saved');

// Credential must be encrypted at rest, never the plaintext.
$row = $pdo->query("SELECT setting_value, is_encrypted FROM settings WHERE setting_key='google_places.api_key'")->fetch(PDO::FETCH_ASSOC);
gOk($row !== false && (int) $row['is_encrypted'] === 1, 'api key stored encrypted');
gOk($row !== false && strpos((string) $row['setting_value'], 'AIzaTESTKEY') === false, 'plaintext key absent from storage');
$pub = AddressAutocompleteSettings::publicClientConfig();
gOk(strpos(json_encode($pub) ?: '', 'AIzaTESTKEY') === false, 'public client config leaks no key');

// Fallback to primary when fallback == primary collapses to none.
AddressAutocompleteAdminSettings::saveFromPost([
    'address_autocomplete_provider' => 'photon',
    'geocoding_search_fallback' => 'photon',
    'geocoding_provider_photon_enabled' => '1',
    'geocoding_provider_google_enabled' => '1',
    'geocoding_provider_nominatim_enabled' => '1',
    'geocoding_reverse_enabled' => '1',
]);
AppSettings::resetCache();
gOk(GeoProviderSettings::searchFallback() === null, 'fallback same as primary collapses to none');

// --- Usage metering + quota enforcement ---
GeocodingUsageMeter::record('google', 'search', true, 200, 120);
GeocodingUsageMeter::record('google', 'search', false, 500, 400);
GeocodingUsageMeter::record('google', 'place_details', true, 200, 90);
$used = GeocodingUsageMeter::used('google');
gOk($used['daily'] === 3 && $used['monthly'] === 3, 'usage: 3 google calls recorded (daily+monthly)');
gOk(GeocodingUsageMeter::quotaAllow('google'), 'quota under limit: allowed');
AppSettings::set(sprintf(GeoProviderSettings::KEY_DAILY_LIMIT, 'google'), '3');
AppSettings::resetCache();
gOk(!GeocodingUsageMeter::quotaAllow('google'), 'daily limit reached: google blocked');
gOk(!GeocodingUsageMeter::providerAvailable('google'), 'providerAvailable false at quota');
$dash = GeocodingUsageMeter::dashboardSummary();
gOk(count($dash) >= 2, 'dashboard rows for google services present');
gOk(array_sum(array_map(static fn ($x) => (int) $x['month_failures'], $dash)) === 1, 'dashboard: 1 failure tracked');
AppSettings::set(sprintf(GeoProviderSettings::KEY_DAILY_LIMIT, 'google'), '0');
AppSettings::resetCache();

// --- Routing: primary google fails (no network / REQUEST_DENIED), photon fallback attempted ---
GeocodingUsageMeter::record('__probe__', 'x', true, 200, 1); // ensure table write path ok
$pdo->exec("DELETE FROM geocoding_usage_daily WHERE provider IN ('google','photon')");
AppSettings::set(AddressAutocompleteSettings::KEY_ENABLED, '1');
AppSettings::set(AddressAutocompleteSettings::KEY_PROVIDER, 'google');
AppSettings::set(GeoProviderSettings::KEY_FALLBACK, 'photon');
AppSettings::resetCache();
$svc = new ReflectionClass('AddressAutocompleteService');
$search = $svc->getMethod('search');
$res = $search->invoke(null, 'indore', '127.0.0.1', null);
// google attempt must be recorded even when it fails; photon fallback attempted too.
$g = (int) $pdo->query("SELECT COALESCE(SUM(requests),0) FROM geocoding_usage_daily WHERE provider='google'")->fetchColumn();
$p = (int) $pdo->query("SELECT COALESCE(SUM(requests),0) FROM geocoding_usage_daily WHERE provider='photon'")->fetchColumn();
gOk($g === 1, 'fallback flow: google attempt metered');
gOk($p === 1, 'fallback flow: photon fallback attempted + metered');
gOk(is_array($res) && isset($res['ok']), 'search returns consistent shape');

// Both providers unavailable -> graceful failure, no fatal.
GeoProviderSettings::setProviderEnabled('google', false);
GeoProviderSettings::setProviderEnabled('photon', false);
AppSettings::resetCache();
$res = AddressAutocompleteService::search('indore', '127.0.0.1', null);
gOk(empty($res['ok']) && isset($res['error']), 'all providers disabled: graceful error');
GeoProviderSettings::setProviderEnabled('google', true);
GeoProviderSettings::setProviderEnabled('photon', true);
AppSettings::resetCache();

// --- Reverse geocoding gate ---
AppSettings::set(GeoProviderSettings::KEY_REVERSE_ENABLED, '0');
AppSettings::resetCache();
$rev = GeocodingRouter::reverse(22.7196, 75.8577);
gOk(empty($rev['ok']) && stripos((string) $rev['error'], 'disabled') !== false, 'reverse disabled: refused before any call');
AppSettings::set(GeoProviderSettings::KEY_REVERSE_ENABLED, '1');
AppSettings::set(GeoProviderSettings::KEY_REVERSE_PROVIDER, 'google');
AppSettings::set('geocoding.reverse_fallback', 'none');
GeoProviderSettings::setProviderEnabled('google', false);
AppSettings::resetCache();
$rev = GeocodingRouter::reverse(22.7196, 75.8577);
gOk(empty($rev['ok']), 'reverse: disabled google provider -> graceful error, no call');
$cnt = (int) $pdo->query("SELECT COALESCE(SUM(requests),0) FROM geocoding_usage_daily WHERE provider='google' AND service='reverse'")->fetchColumn();
gOk($cnt === 0, 'disabled provider: zero external calls made');
GeoProviderSettings::setProviderEnabled('google', true);
AppSettings::resetCache();

// --- Credential replacement + removal ---
$r = AddressAutocompleteAdminSettings::saveFromPost([
    'address_autocomplete_provider' => 'photon',
    'geocoding_provider_photon_enabled' => '1',
    'geocoding_provider_google_enabled' => '1',
    'geocoding_provider_nominatim_enabled' => '1',
    'geocoding_reverse_enabled' => '1',
    'google_places_api_key_remove' => '1',
]);
AppSettings::resetCache();
gOk(!AddressAutocompleteSettings::googleKeyConfigured(), 'remove checkbox clears saved key');
gOk(!GeoProviderSettings::providerUsable('google'), 'google unusable without key');

// Unrelated settings save must NOT wipe the key (preserve semantics: blank keeps key).
AppSettings::set(AddressAutocompleteSettings::KEY_GOOGLE_KEY, 'AIzaSECONDKEY0000000000', true);
AppSettings::resetCache();
AddressAutocompleteAdminSettings::saveFromPost([
    'address_autocomplete_provider' => 'photon',
    'geocoding_provider_photon_enabled' => '1',
    'geocoding_provider_google_enabled' => '1',
    'geocoding_provider_nominatim_enabled' => '1',
    'geocoding_reverse_enabled' => '1',
    'address_autocomplete_max_results' => '9',
]);
AppSettings::resetCache();
gOk(AddressAutocompleteSettings::googleKeyConfigured(), 'unrelated save preserves saved key');
gOk(AddressAutocompleteSettings::maxResults() === 9, 'unrelated field saved correctly');
gOk(AddressAutocompleteSettings::googleApiKey() === 'AIzaSECONDKEY0000000000', 'key readable server-side only');

// Autocomplete master off -> search refuses before providers.
AppSettings::set(AddressAutocompleteSettings::KEY_ENABLED, '0');
AppSettings::resetCache();
$res = AddressAutocompleteService::search('indore', '127.0.0.1', null);
gOk(empty($res['ok']) && stripos((string) $res['error'], 'disabled') !== false, 'autocomplete disabled: refused');

echo ($fail === 0 ? 'ALL OK' : 'FAILURES: ' . $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
