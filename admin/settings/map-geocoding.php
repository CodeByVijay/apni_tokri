<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/settings/address_autocomplete_admin.php';

AdminDemoPerms::denyIfCannot('settings');
$useDb = Install::isSchemaReady() && AdminAuth::check();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = isset($_POST['map_geo_action']) && is_string($_POST['map_geo_action']) ? $_POST['map_geo_action'] : 'save';
    if (!$useDb) {
        admin_flash_persistence_blocked('Settings would be saved when the database is available.');
        header('Location: ' . url_base() . '/admin/settings/map-geocoding.php', true, 302);
        exit;
    }
    if ($action === 'test_google') {
        $r = AddressAutocompleteAdminSettings::testGoogleFromPost($_POST);
        admin_flash_set($r['message'], $r['ok'] ? '' : 'error');
    } else {
        $r = AddressAutocompleteAdminSettings::saveFromPost($_POST);
        admin_flash_set($r['message'] ?? ($r['ok'] ? 'Saved.' : 'Could not save.'), $r['ok'] ? '' : 'error');
    }
    header('Location: ' . url_base() . '/admin/settings/map-geocoding.php', true, 302);
    exit;
}

$enabled = AddressAutocompleteSettings::isEnabled();
$gpsEnabled = AddressAutocompleteSettings::gpsEnabled();
$provider = AddressAutocompleteSettings::provider();
$defaultCountry = (string) (AppSettings::get(AddressAutocompleteSettings::KEY_DEFAULT_COUNTRY, 'India') ?? 'India');
$countryRestriction = (string) (AppSettings::get(AddressAutocompleteSettings::KEY_COUNTRY_RESTRICTION, 'IN') ?? 'IN');
$biasLat = AppSettings::get(AddressAutocompleteSettings::KEY_BIAS_LAT, '');
$biasLng = AppSettings::get(AddressAutocompleteSettings::KEY_BIAS_LNG, '');
$map = MapConfig::settings();
$biasLatDisp = ($biasLat !== null && is_numeric($biasLat)) ? (string) $biasLat : '';
$biasLngDisp = ($biasLng !== null && is_numeric($biasLng)) ? (string) $biasLng : '';
$maxResults = AddressAutocompleteSettings::maxResults();
$timeoutSec = AddressAutocompleteSettings::timeoutSeconds();
$rpm = AddressAutocompleteSettings::ratePerMinuteIp();
$googleSaved = AddressAutocompleteAdminSettings::googleKeySaved();
$googleMapsBrowserSaved = AddressAutocompleteAdminSettings::googleMapsBrowserKeySaved();

$fallback = GeoProviderSettings::searchFallback();
$reverseEnabled = GeoProviderSettings::reverseEnabled();
$reverseProvider = GeoProviderSettings::reverseProvider();
$reverseFallback = strtolower((string) (AppSettings::get('geocoding.reverse_fallback', 'none') ?? 'none'));
$providerState = [];
foreach (GeoProviderSettings::providers() as $p) {
    $q = GeoProviderSettings::quota($p);
    $u = GeocodingUsageMeter::used($p);
    $providerState[$p] = [
        'enabled' => GeoProviderSettings::providerEnabled($p),
        'usable' => GeoProviderSettings::providerUsable($p),
        'quota' => $q,
        'used' => $u,
        'quota_ok' => GeocodingUsageMeter::quotaAllow($p),
    ];
}
$usageRows = GeocodingUsageMeter::dashboardSummary();

admin_layout_start(['pageTitle' => 'Map & geocoding', 'activeAdminNav' => 'settings']);
$pageHeading = 'Map & Geocoding';
$pageDescription = 'Address search (Google Places or Photon), reverse geocoding and the Google Maps delivery pin. The server key stays on the server; the browser map uses a separate referrer-restricted key.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'map_geocoding';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<div class="at-card max-w-2xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Overview</h3>
        <p class="at-muted">Storefront address autocomplete runs through <code class="text-xs">/ajax/address/</code> with CSRF. The server-side Google API key is never sent to the browser. The interactive delivery map uses a separate referrer-restricted browser key (Google Maps JavaScript API).</p>
        <p class="at-muted">Checkout uses the map-free address form when autocomplete is <strong>enabled</strong>. When disabled, checkout falls back to the pin map. My addresses and other pages keep the existing map behaviour.</p>
    </div>
</div>

<form method="post" class="at-card max-w-2xl space-y-4">
    <div class="at-card__body space-y-4">
        <?= Csrf::field() ?>
        <input type="hidden" name="map_geo_action" value="save">

        <label class="flex gap-2 text-sm items-center">
            <input type="checkbox" name="address_autocomplete_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
            Enable address autocomplete on checkout
        </label>

        <label class="flex gap-2 text-sm items-center">
            <input type="checkbox" name="address_autocomplete_gps_enabled" value="1" <?= $gpsEnabled ? 'checked' : '' ?>>
            Enable "Use my current location" (GPS) on address forms
            <span class="at-muted text-xs">— currently off: reverse-geocode detail is not reliable enough</span>
        </label>

        <div>
            <label class="at-label" for="address_autocomplete_provider">Address search provider</label>
            <select id="address_autocomplete_provider" name="address_autocomplete_provider" class="at-input at-focusable">
                <option value="photon" <?= $provider === 'photon' ? 'selected' : '' ?>>Photon (OpenStreetMap)</option>
                <option value="google" <?= $provider === 'google' ? 'selected' : '' ?>>Google Places API</option>
            </select>
        </div>

        <div>
            <label class="at-label" for="geocoding_search_fallback">Fallback search provider</label>
            <select id="geocoding_search_fallback" name="geocoding_search_fallback" class="at-input at-focusable">
                <option value="none" <?= $fallback === null ? 'selected' : '' ?>>None</option>
                <option value="photon" <?= $fallback === 'photon' ? 'selected' : '' ?>>Photon (OpenStreetMap)</option>
                <option value="google" <?= $fallback === 'google' ? 'selected' : '' ?>>Google Places API</option>
            </select>
            <p class="at-field-hint text-xs">Tried only when the primary provider is unavailable, disabled or over quota. Same as primary = treated as none.</p>
        </div>

        <hr class="border-neutral-200">
        <h3 class="font-bold">Providers</h3>
        <p class="text-xs at-muted">Emergency switches stop all calls to a provider immediately. Limits count real external calls only (0 = no limit).</p>
        <div class="grid sm:grid-cols-3 gap-3">
            <?php
            $providerMeta = [
                'photon' => ['name' => 'Photon', 'note' => 'OSM search (komoot public instance)'],
                'nominatim' => ['name' => 'Nominatim', 'note' => 'OSM reverse — usage policy applies'],
                'google' => ['name' => 'Google', 'note' => 'Places / Geocoding — billing may apply'],
            ];
            foreach ($providerMeta as $pid => $meta):
                $st = $providerState[$pid];
                $statusTxt = 'OK';
                if (!$st['enabled']) {
                    $statusTxt = 'Disabled';
                } elseif ($pid === 'google' && !$googleSaved) {
                    $statusTxt = 'No API key';
                } elseif (!$st['quota_ok']) {
                    $statusTxt = 'Quota reached';
                }
            ?>
            <div class="at-card">
                <div class="at-card__body text-sm space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <strong><?= Security::escape($meta['name']) ?></strong>
                        <span class="at-cred-badge <?= $statusTxt === 'OK' ? 'at-cred-badge--saved' : 'at-cred-badge--empty' ?>"><?= Security::escape($statusTxt) ?></span>
                    </div>
                    <p class="at-muted text-xs"><?= Security::escape($meta['note']) ?></p>
                    <p class="at-muted text-xs">Today: <?= (int) $st['used']['daily'] ?><?= $st['quota']['daily'] > 0 ? ' / ' . (int) $st['quota']['daily'] : '' ?>
                        &middot; Month: <?= (int) $st['used']['monthly'] ?><?= $st['quota']['monthly'] > 0 ? ' / ' . (int) $st['quota']['monthly'] : '' ?></p>
                    <label class="flex gap-2 items-center text-sm">
                        <input type="checkbox" name="geocoding_provider_<?= Security::escape($pid) ?>_enabled" value="1" <?= $st['enabled'] ? 'checked' : '' ?>>
                        Enabled
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="at-label text-xs" for="geocoding_quota_<?= Security::escape($pid) ?>_daily">Daily limit</label>
                            <input id="geocoding_quota_<?= Security::escape($pid) ?>_daily" name="geocoding_quota_<?= Security::escape($pid) ?>_daily" type="number" min="0" class="at-input at-focusable" value="<?= (int) $st['quota']['daily'] ?>">
                        </div>
                        <div>
                            <label class="at-label text-xs" for="geocoding_quota_<?= Security::escape($pid) ?>_monthly">Monthly limit</label>
                            <input id="geocoding_quota_<?= Security::escape($pid) ?>_monthly" name="geocoding_quota_<?= Security::escape($pid) ?>_monthly" type="number" min="0" class="at-input at-focusable" value="<?= (int) $st['quota']['monthly'] ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <hr class="border-neutral-200">
        <h3 class="font-bold">Reverse geocoding (map pin &amp; GPS lookups)</h3>
        <label class="flex gap-2 text-sm items-center">
            <input type="checkbox" name="geocoding_reverse_enabled" value="1" <?= $reverseEnabled ? 'checked' : '' ?>>
            Enable reverse geocoding
        </label>
        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="at-label" for="geocoding_reverse_provider">Reverse provider</label>
                <select id="geocoding_reverse_provider" name="geocoding_reverse_provider" class="at-input at-focusable">
                    <option value="nominatim" <?= $reverseProvider === 'nominatim' ? 'selected' : '' ?>>Nominatim (OpenStreetMap)</option>
                    <option value="google" <?= $reverseProvider === 'google' ? 'selected' : '' ?>>Google Geocoding API</option>
                </select>
            </div>
            <div>
                <label class="at-label" for="geocoding_reverse_fallback">Reverse fallback</label>
                <select id="geocoding_reverse_fallback" name="geocoding_reverse_fallback" class="at-input at-focusable">
                    <option value="none" <?= $reverseFallback === 'none' ? 'selected' : '' ?>>None</option>
                    <option value="nominatim" <?= $reverseFallback === 'nominatim' ? 'selected' : '' ?>>Nominatim (OpenStreetMap)</option>
                    <option value="google" <?= $reverseFallback === 'google' ? 'selected' : '' ?>>Google Geocoding API</option>
                </select>
            </div>
        </div>
        <p class="at-field-hint text-xs">Nominatim follows the OSM usage policy (low volume, no bulk). Google Geocoding is a separate paid API and must be enabled on the key's project. If all providers fail, customers can still type the address manually and pick the pin on the map.</p>

        <div>
            <label class="at-label" for="address_autocomplete_default_country">Default country (display)</label>
            <input id="address_autocomplete_default_country" name="address_autocomplete_default_country" class="at-input at-focusable" value="<?= Security::escape($defaultCountry) ?>" maxlength="120">
        </div>

        <div>
            <label class="at-label" for="address_autocomplete_country_restriction">Country restriction</label>
            <input id="address_autocomplete_country_restriction" name="address_autocomplete_country_restriction" class="at-input at-focusable" value="<?= Security::escape($countryRestriction) ?>" placeholder="IN or IN, US">
            <p class="at-field-hint text-xs">ISO 3166-1 alpha-2 codes, comma-separated. Used for Google Places; Photon keeps the Indore area bias from config.</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label class="at-label" for="address_autocomplete_bias_lat">Search bias latitude</label>
                <input id="address_autocomplete_bias_lat" name="address_autocomplete_bias_lat" class="at-input at-focusable" value="<?= Security::escape($biasLatDisp) ?>">
            </div>
            <div>
                <label class="at-label" for="address_autocomplete_bias_lng">Search bias longitude</label>
                <input id="address_autocomplete_bias_lng" name="address_autocomplete_bias_lng" class="at-input at-focusable" value="<?= Security::escape($biasLngDisp) ?>">
            </div>
        </div>
        <p class="at-field-hint text-xs">Leave both empty to use the delivery store coordinates (<?= Security::escape((string) $map['default_lat']) ?>, <?= Security::escape((string) $map['default_lng']) ?>).</p>

        <div class="grid sm:grid-cols-3 gap-3">
            <div>
                <label class="at-label" for="address_autocomplete_max_results">Max suggestions</label>
                <input id="address_autocomplete_max_results" name="address_autocomplete_max_results" type="number" min="3" max="12" class="at-input at-focusable" value="<?= (int) $maxResults ?>">
            </div>
            <div>
                <label class="at-label" for="address_autocomplete_timeout">Timeout (seconds)</label>
                <input id="address_autocomplete_timeout" name="address_autocomplete_timeout" type="number" min="3" max="25" class="at-input at-focusable" value="<?= (int) $timeoutSec ?>">
            </div>
            <div>
                <label class="at-label" for="address_autocomplete_rpm">Max requests / min / IP</label>
                <input id="address_autocomplete_rpm" name="address_autocomplete_rpm" type="number" min="10" max="200" class="at-input at-focusable" value="<?= (int) $rpm ?>">
            </div>
        </div>

        <hr class="border-neutral-200">
        <h3 class="font-bold">Google APIs</h3>
        <p class="text-xs at-muted">Google services are optional and billed per use — one key does not enable everything. Enable on the key's project only what you configure here:</p>
        <ul class="text-xs at-muted list-disc pl-5 space-y-1">
            <li><strong>Places API (New)</strong> — required when Google is the search or fallback provider (autocomplete + place details).</li>
            <li><strong>Geocoding API</strong> — required only when Google is selected as a reverse-geocoding provider.</li>
            <li><strong>Maps JavaScript API</strong> — required for the storefront "Choose your delivery location" map. It uses a separate browser key (below), not the server key.</li>
        </ul>
        <p class="text-xs at-muted">The key is stored encrypted; leave blank when updating other fields to keep the existing key. This is the <strong>server-side</strong> key — it is never sent to the browser. Restrict it by server IP address (or leave it unrestricted and secret) — <strong>never by HTTP referrers</strong>: Google denies referrer-restricted keys on server-to-server calls (<code class="text-xs">REQUEST_DENIED</code>), which leaves address prefill empty.</p>
        <?php
        $credId = 'google_places_api_key';
        $credName = 'google_places_api_key';
        $credLabel = 'Server API key (Places / Geocoding)';
        $credSaved = $googleSaved;
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
        ?>
        <?php if ($googleSaved): ?>
            <label class="flex gap-2 text-sm items-center">
                <input type="checkbox" name="google_places_api_key_remove" value="1">
                Remove saved API key (disables Google providers)
            </label>
        <?php endif; ?>

        <hr class="border-neutral-200">
        <h3 class="font-bold">Google Maps browser key</h3>
        <p class="text-xs at-muted">The storefront delivery-location map needs the <strong>Maps JavaScript API</strong>, which loads in the customer's browser — so it needs a key that is visible in page requests. Create a <strong>separate</strong> API key in Google Cloud and lock it down:</p>
        <ul class="text-xs at-muted list-disc pl-5 space-y-1">
            <li><strong>API restriction:</strong> allow only <em>Maps JavaScript API</em>.</li>
            <li><strong>Application restriction:</strong> HTTP referrers — add <code class="text-xs">https://your-domain/*</code> for every storefront domain (e.g. staging and production), plus <code class="text-xs">http://localhost/*</code> if you test locally.</li>
            <li>Enable <strong>Maps JavaScript API</strong> on the project and confirm billing is active — map loads are billed.</li>
            <li>Never paste the server key above into this field. Without this key the map step shows an "unavailable" message and customers can still type their address manually.</li>
        </ul>
        <?php
        $credId = 'google_maps_browser_api_key';
        $credName = 'google_maps_browser_api_key';
        $credLabel = 'Browser API key (Maps JavaScript API)';
        $credSaved = $googleMapsBrowserSaved;
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
        ?>
        <?php if ($googleMapsBrowserSaved): ?>
            <label class="flex gap-2 text-sm items-center">
                <input type="checkbox" name="google_maps_browser_api_key_remove" value="1">
                Remove saved browser key (storefront map falls back to manual address entry)
            </label>
        <?php endif; ?>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save settings</button>
        </div>
    </div>
</form>

<div class="at-card max-w-2xl mt-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Usage this month</h3>
        <?php if ($usageRows === []): ?>
            <p class="at-muted text-xs">No provider calls recorded yet (or usage table not migrated).</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="at-table text-xs w-full">
                    <thead>
                        <tr><th class="text-left">Provider</th><th class="text-left">Service</th><th>Today</th><th>Failed (today)</th><th>Month</th><th>Failed (month)</th><th>Avg ms</th><th>Last HTTP</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usageRows as $row): ?>
                        <tr>
                            <td><?= Security::escape((string) $row['provider']) ?></td>
                            <td><?= Security::escape((string) $row['service']) ?></td>
                            <td class="text-center"><?= (int) $row['today_requests'] ?></td>
                            <td class="text-center"><?= (int) $row['today_failures'] ?></td>
                            <td class="text-center"><?= (int) $row['month_requests'] ?></td>
                            <td class="text-center"><?= (int) $row['month_failures'] ?></td>
                            <td class="text-center"><?= (int) $row['month_requests'] > 0 ? (int) round(((int) $row['month_ms']) / (int) $row['month_requests']) : 0 ?></td>
                            <td class="text-center"><?= $row['last_http'] !== null ? (int) $row['last_http'] : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="at-muted text-xs">Counts external provider calls only — never search text or addresses.</p>
        <?php endif; ?>
    </div>
</div>

<form method="post" class="at-card max-w-2xl mt-4">
    <div class="at-card__body space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="map_geo_action" value="test_google">
        <h3 class="font-bold">Test Google connection</h3>
        <p class="text-xs at-muted">Runs a minimal Places Autocomplete request. Uses the key below if filled; otherwise the saved encrypted key.</p>
        <?php
        $credId = 'google_places_api_key_test';
        $credName = 'google_places_api_key';
        $credLabel = 'API key (optional for test)';
        $credSaved = false;
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
        ?>
        <button type="submit" class="at-btn at-btn--secondary at-focusable">Test API connection</button>
    </div>
</form>

<?php admin_layout_end(); ?>
