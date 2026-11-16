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

admin_layout_start(['pageTitle' => 'Map & geocoding', 'activeAdminNav' => 'settings']);
$pageHeading = 'Map & Geocoding';
$pageDescription = 'Checkout address search (Google Places or Photon). Keys stay on the server; Leaflet maps elsewhere are unchanged.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$settingsTab = 'map_geocoding';
require APNI_TOKRI_ROOT . '/includes/partials/admin/settings-nav.php';
?>

<div class="at-card max-w-2xl mb-4">
    <div class="at-card__body text-sm space-y-2">
        <h3 class="font-bold">Overview</h3>
        <p class="at-muted">Storefront address autocomplete runs through <code class="text-xs">/ajax/address/</code> with CSRF. The Google API key is never sent to the browser.</p>
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
        <h3 class="font-bold">Google Places API</h3>
        <p class="text-xs at-muted">Enable <strong>Places API (New)</strong> for the key’s project. The key is stored encrypted; leave blank when updating other fields to keep the existing key.</p>
        <?php
        $credId = 'google_places_api_key';
        $credName = 'google_places_api_key';
        $credLabel = 'API key';
        $credSaved = $googleSaved;
        require APNI_TOKRI_ROOT . '/includes/partials/admin/secret-credential-field.php';
        ?>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="at-btn at-btn--primary at-focusable">Save settings</button>
        </div>
    </div>
</form>

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
