<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$store = DeliveryConfig::store();
$mode = AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy') ?? 'pincode_legacy';
$errors = [];

$storedProvider = (string) ($store['road_distance_provider'] ?? 'none');
$roadConfigured = $storedProvider !== 'none' && $storedProvider !== '';
$storedDistanceMode = (string) ($store['default_distance_mode'] ?? 'haversine');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($useDb) {
        $newMode = (string) ($_POST['zone_resolution_mode'] ?? 'pincode_legacy');
        if (!in_array($newMode, ['pincode_legacy', 'distance_hybrid'], true)) {
            $newMode = 'pincode_legacy';
        }

        // Existing stored values are kept unless the admin changed them; only providers that already exist are accepted.
        $newProvider = (string) ($_POST['road_distance_provider'] ?? $storedProvider);
        if ($newProvider !== 'none' && $newProvider !== $storedProvider) {
            $newProvider = $storedProvider;
        }
        $newDistanceMode = (string) ($_POST['default_distance_mode'] ?? $storedDistanceMode);
        if (!in_array($newDistanceMode, ['haversine', 'road'], true)) {
            $newDistanceMode = 'haversine';
        }
        if ($newDistanceMode === 'road' && ($newProvider === 'none' || $newProvider === '')) {
            $errors[] = 'Road distance can only be used once a road distance service is set up. Choose “Straight-line distance” for now.';
        }

        $fallbackId = ($_POST['fallback_radius_zone_id'] ?? '') !== '' ? (int) $_POST['fallback_radius_zone_id'] : null;
        if ($fallbackId !== null && DeliveryZoneRepository::find($fallbackId) === null) {
            $errors[] = 'The selected backup delivery area no longer exists. Please choose another.';
        }

        if ($errors === []) {
            Install::ensureConnection();
            AppSettings::set('delivery.zone_resolution_mode', $newMode);
            Database::pdo()->prepare(
                'UPDATE delivery_store SET
                   default_distance_mode = ?,
                   road_distance_provider = ?,
                   overlap_policy = ?,
                   require_coordinates_for_checkout = ?,
                   manual_delivery_when_provider_unavailable = ?,
                   fallback_radius_zone_id = ?
                 WHERE id = 1'
            )->execute([
                $newDistanceMode,
                $newProvider === '' ? 'none' : $newProvider,
                'reject',
                !empty($_POST['require_coordinates_for_checkout']) ? 1 : 0,
                !empty($_POST['manual_delivery_when_provider_unavailable']) ? 1 : 0,
                $fallbackId,
            ]);
            DeliveryConfig::resetCache();
            admin_flash_set('Delivery rules saved.');
            header('Location: ' . url_base() . '/admin/settings/delivery/policies.php', true, 302);
            exit;
        }
        // Re-show what the admin submitted.
        $mode = $newMode;
        $store['require_coordinates_for_checkout'] = !empty($_POST['require_coordinates_for_checkout']) ? 1 : 0;
        $store['manual_delivery_when_provider_unavailable'] = !empty($_POST['manual_delivery_when_provider_unavailable']) ? 1 : 0;
        $store['fallback_radius_zone_id'] = $fallbackId;
    } else {
        header('Location: ' . url_base() . '/admin/settings/delivery/policies.php', true, 302);
        exit;
    }
}

$zones = $useDb ? DeliveryZoneRepository::allZones() : [];
$missingWeights = $useDb ? DeliveryZoneAdminService::countVariantsMissingWeight() : 0;
$distanceMode = ($storedDistanceMode === 'road' && $roadConfigured) ? 'road' : 'haversine';

admin_layout_start(['pageTitle' => 'Delivery rules', 'activeAdminNav' => 'settings']);
$pageHeading = 'Delivery rules';
$pageDescription = 'Choose how customers are matched to a delivery area and what happens at checkout.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'policies';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';

$explainMode = $mode;
$explainZones = $zones;
$explainStore = $store;
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-explainer.php';

if ($errors !== []): ?>
    <div class="at-alert at-alert--error mb-4" role="alert">
        <p class="font-semibold mb-1">We could not save your delivery rules:</p>
        <ul class="list-disc pl-5 text-sm"><?php foreach ($errors as $e): ?><li><?= Security::escape($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="at-admin-form-grid at-admin-form-grid--2">
    <?= Csrf::field() ?>
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">How do you decide where you deliver?</h3>
        <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="zone_resolution_mode" value="pincode_legacy" class="mt-1"<?= $mode === 'pincode_legacy' ? ' checked' : '' ?>>
            <span><strong>Pincode-based delivery</strong> <span class="at-muted">(recommended for most stores)</span><br>
            <span class="text-xs at-muted">Customers are matched using the first digits of their pincode. Example: pincodes starting with 4520. Customers do not need to share a map location.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="zone_resolution_mode" value="distance_hybrid" class="mt-1"<?= $mode === 'distance_hybrid' ? ' checked' : '' ?>>
            <span><strong>Distance-based delivery</strong><br>
            <span class="text-xs at-muted">Customers are matched by how far they are from your store, so they must choose their location on the map. Example: 0–5 km, 5–10 km. Areas set up by pincode still work too.</span></span>
        </label>
    </div></div>

    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">How is distance measured?</h3>
        <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="default_distance_mode" value="haversine" class="mt-1"<?= $distanceMode === 'haversine' ? ' checked' : '' ?>>
            <span><strong>Straight-line distance</strong><br>
            <span class="text-xs at-muted">Distance “as the crow flies” between your store and the customer. Simple and always available.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm<?= $roadConfigured ? '' : ' opacity-60' ?>">
            <input type="radio" name="default_distance_mode" value="road" class="mt-1"<?= $distanceMode === 'road' ? ' checked' : '' ?><?= $roadConfigured ? '' : ' disabled' ?>>
            <span><strong>Road distance</strong><br>
            <span class="text-xs at-muted"><?= $roadConfigured
                ? 'Distance by road, using your connected road distance service.'
                : 'Not available — no road distance service has been connected yet. Straight-line distance is used.' ?></span></span>
        </label>

        <h3 class="font-bold pt-2">At checkout</h3>
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="require_coordinates_for_checkout" value="1" class="mt-1"<?= !empty($store['require_coordinates_for_checkout']) ? ' checked' : '' ?>>
            <span><strong>Require customer map location</strong><br>
            <span class="text-xs at-muted">Customers must pin their address on the map before they can place an order.</span></span>
        </label>
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="manual_delivery_when_provider_unavailable" value="1" class="mt-1"<?= !empty($store['manual_delivery_when_provider_unavailable']) ? ' checked' : '' ?>>
            <span><strong>Allow manual delivery if map service is unavailable</strong><br>
            <span class="text-xs at-muted">If a delivery partner or map service cannot be used, your own team delivers the order instead of the order being blocked.</span></span>
        </label>
    </div></div>

    <?php if ($missingWeights > 0): ?>
    <div class="at-alert at-alert--warning md:col-span-2">
        <?= (int) $missingWeights ?> product variant(s) have no weight set. Maximum Order Weight limits on delivery areas will not be applied until product weights are added.
    </div>
    <?php endif; ?>

    <details class="at-admin-card md:col-span-2">
        <summary class="at-admin-card__body cursor-pointer font-bold">Advanced settings <span class="font-normal text-sm at-muted">(most stores never need to change these)</span></summary>
        <div class="at-admin-card__body space-y-4 pt-0">
            <div>
                <label class="at-label" for="fb-zone">Backup delivery charge for nearby addresses</label>
                <select id="fb-zone" name="fallback_radius_zone_id" class="at-input at-focusable w-full">
                    <option value="">Standard charge (<?= Security::escape(DeliveryAdminUi::inr(40)) ?>, free on orders of <?= Security::escape(DeliveryAdminUi::inr(499)) ?> or more)</option>
                    <?php foreach ($zones as $z): ?>
                        <option value="<?= (int) $z['id'] ?>"<?= (int) ($store['fallback_radius_zone_id'] ?? 0) === (int) $z['id'] ? ' selected' : '' ?>><?= Security::escape((string) $z['name'] . ' — ' . DeliveryAdminUi::chargeSummary($z)) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs at-muted mt-1">Used in Pincode-based delivery when a pincode is not in any delivery area but the address is within <?= Security::escape(DeliveryAdminUi::km($store['max_radius_km'] ?? 12)) ?> of your store. <em>Technical name: fallback radius zone.</em></p>
            </div>
            <div>
                <label class="at-label" for="road-provider">Road distance service</label>
                <select id="road-provider" name="road_distance_provider" class="at-input at-focusable w-full">
                    <option value="none"<?= $roadConfigured ? '' : ' selected' ?>>None connected</option>
                    <?php if ($roadConfigured): ?>
                        <option value="<?= Security::escape($storedProvider) ?>" selected><?= Security::escape($storedProvider) ?> (currently connected)</option>
                    <?php endif; ?>
                </select>
                <p class="text-xs at-muted mt-1">Connecting a new road distance service needs a developer. <em>Technical name: road provider.</em></p>
            </div>
            <p class="text-xs at-muted">Overlapping delivery areas are always rejected so every address matches one area only. This cannot be changed.</p>
            <details class="text-xs at-muted">
                <summary class="cursor-pointer">Technical terms used elsewhere (for developers)</summary>
                <ul class="list-disc pl-5 mt-1 space-y-1">
                    <li><strong>Pincode legacy</strong> = Pincode-based delivery.</li>
                    <li><strong>Distance hybrid</strong> = Distance-based delivery.</li>
                    <li><strong>Haversine</strong> = Straight-line distance.</li>
                    <li><strong>Centroid</strong> = an approximate centre point for a pincode, used only for the nearby-address backup in pincode-based delivery.</li>
                    <li><strong>Provider fallback</strong> = Allow manual delivery if the map service is unavailable.</li>
                </ul>
            </details>
        </div>
    </details>

    <div class="md:col-span-2"><button type="submit" class="at-btn at-btn--primary at-focusable">Save delivery rules</button></div>
</form>
<?php admin_layout_end(); ?>
