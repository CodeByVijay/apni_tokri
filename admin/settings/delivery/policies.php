<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$store = DeliveryConfig::store();
$mode = AppSettings::get('delivery.zone_resolution_mode', 'pincode_legacy') ?? 'pincode_legacy';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($useDb) {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $newMode = (string) ($_POST['zone_resolution_mode'] ?? 'pincode_legacy');
        if (!in_array($newMode, ['pincode_legacy', 'distance_hybrid'], true)) {
            $newMode = 'pincode_legacy';
        }
        AppSettings::set('delivery.zone_resolution_mode', $newMode);

        $pdo->prepare(
            'UPDATE delivery_store SET
               default_distance_mode = ?,
               road_distance_provider = ?,
               overlap_policy = ?,
               require_coordinates_for_checkout = ?,
               manual_delivery_when_provider_unavailable = ?,
               fallback_radius_zone_id = ?
             WHERE id = 1'
        )->execute([
            (string) ($_POST['default_distance_mode'] ?? 'haversine'),
            (string) ($_POST['road_distance_provider'] ?? 'none'),
            'reject',
            !empty($_POST['require_coordinates_for_checkout']) ? 1 : 0,
            !empty($_POST['manual_delivery_when_provider_unavailable']) ? 1 : 0,
            ($_POST['fallback_radius_zone_id'] ?? '') !== '' ? (int) $_POST['fallback_radius_zone_id'] : null,
        ]);
        DeliveryConfig::resetCache();
        admin_flash_set('Delivery policies saved.');
    }
    header('Location: ' . url_base() . '/admin/settings/delivery/policies.php', true, 302);
    exit;
}

$zones = $useDb ? DeliveryZoneRepository::allZones() : [];
$missingWeights = $useDb ? DeliveryZoneAdminService::countVariantsMissingWeight() : 0;

admin_layout_start(['pageTitle' => 'Delivery policies', 'activeAdminNav' => 'settings']);
$pageHeading = 'Delivery policies';
$pageDescription = 'Zone resolution mode, distance source, and checkout provider fallback flags.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'policies';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';
?>
<form method="post" class="at-admin-form-grid at-admin-form-grid--2">
    <?= Csrf::field() ?>
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Zone resolution</h3>
        <label class="flex gap-2 text-sm"><input type="radio" name="zone_resolution_mode" value="pincode_legacy"<?= $mode === 'pincode_legacy' ? ' checked' : '' ?>> Pincode legacy (Stage 8 — default)</label>
        <label class="flex gap-2 text-sm"><input type="radio" name="zone_resolution_mode" value="distance_hybrid"<?= $mode === 'distance_hybrid' ? ' checked' : '' ?>> Distance hybrid (map coordinates required for distance bands)</label>
        <p class="text-xs at-muted">Legacy keeps pincode prefix + approximate pincode-centroid radius fallback. Hybrid never uses pincode centroid as customer location.</p>
        <div><label class="at-label">Fallback radius zone (legacy centroid match)</label>
            <select name="fallback_radius_zone_id" class="at-input at-focusable w-full">
                <option value="">— Synthetic ₹40 / ₹499 —</option>
                <?php foreach ($zones as $z): ?>
                    <option value="<?= (int) $z['id'] ?>"<?= (int) ($store['fallback_radius_zone_id'] ?? 0) === (int) $z['id'] ? ' selected' : '' ?>><?= Security::escape((string) $z['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div></div>
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Distance & providers</h3>
        <div><label class="at-label">Default distance mode</label>
            <select name="default_distance_mode" class="at-input at-focusable w-full">
                <option value="haversine"<?= (($store['default_distance_mode'] ?? 'haversine') === 'haversine') ? ' selected' : '' ?>>Haversine (straight-line)</option>
                <option value="road" disabled>Road (requires configured provider)</option>
            </select>
        </div>
        <div><label class="at-label">Road provider</label>
            <select name="road_distance_provider" class="at-input at-focusable w-full">
                <option value="none" selected>None</option>
            </select>
            <p class="text-xs at-muted">External road routing is not configured yet. Haversine is straight-line distance, not road distance.</p>
        </div>
        <p class="text-xs at-muted">Overlap policy: <strong>reject</strong> (mandatory).</p>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="require_coordinates_for_checkout" value="1"<?= !empty($store['require_coordinates_for_checkout']) ? ' checked' : '' ?>> Require map coordinates at checkout</label>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="manual_delivery_when_provider_unavailable" value="1"<?= !empty($store['manual_delivery_when_provider_unavailable']) ? ' checked' : '' ?>> Allow manual delivery when provider unavailable</label>
    </div></div>
    <?php if ($missingWeights > 0): ?>
    <div class="at-admin-card lg:col-span-2"><div class="at-admin-card__body text-sm text-amber-800">
        Warning: <?= (int) $missingWeights ?> variant(s) missing weight_kg. Weight caps on zones are not enforced until catalogue weights are set.
    </div></div>
    <?php endif; ?>
    <div class="lg:col-span-2"><button type="submit" class="at-btn at-btn--primary at-focusable">Save policies</button></div>
</form>
<?php admin_layout_end(); ?>
