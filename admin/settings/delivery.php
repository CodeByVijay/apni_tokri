<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$store = DeliveryConfig::store();
$zones = DeliveryZoneRepository::activeZones();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if ($useDb) {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE delivery_store SET name = ?, latitude = ?, longitude = ?, max_radius_km = ?,
             slots_enabled = ?, prep_minutes = ?, business_open = ?, business_close = ?, updated_at = NOW() WHERE id = 1'
        )->execute([
            trim((string) ($_POST['store_name'] ?? '')),
            (float) ($_POST['latitude'] ?? 0),
            (float) ($_POST['longitude'] ?? 0),
            (float) ($_POST['max_radius_km'] ?? 12),
            !empty($_POST['slots_enabled']) ? 1 : 0,
            max(0, (int) ($_POST['prep_minutes'] ?? 45)),
            (string) ($_POST['business_open'] ?? '08:00'),
            (string) ($_POST['business_close'] ?? '20:00'),
        ]);
        if (isset($_POST['zone_fee'], $_POST['zone_free']) && is_array($_POST['zone_fee'])) {
            foreach ($_POST['zone_fee'] as $zid => $fee) {
                $pdo->prepare('UPDATE delivery_zones SET flat_fee = ?, free_above = ? WHERE id = ?')
                    ->execute([(float) $fee, (float) ($_POST['zone_free'][$zid] ?? 499), (int) $zid]);
            }
        }
        DeliveryConfig::resetCache();
        admin_flash_set('Delivery settings saved.');
    } else {
        admin_flash_persistence_blocked('Delivery settings saved (demo UI).');
    }
    header('Location: ' . url_base() . '/admin/settings/delivery.php', true, 302);
    exit;
}

admin_layout_start(['pageTitle' => 'Delivery settings', 'activeAdminNav' => 'settings']);
$pageHeading = 'Zones, fees & slots';
$pageDescription = 'Store location, Indore zones, fees and slot capacity (Asia/Kolkata).';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'dispatch';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';
?>
<form method="post" class="at-admin-form-grid at-admin-form-grid--2">
    <?= Csrf::field() ?>
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Dispatch location</h3>
        <div><label class="at-label">Name</label><input name="store_name" class="at-input at-focusable" value="<?= Security::escape((string) ($store['name'] ?? '')) ?>"></div>
        <div><label class="at-label">Latitude</label><input name="latitude" class="at-input at-focusable" value="<?= Security::escape((string) ($store['latitude'] ?? '')) ?>"></div>
        <div><label class="at-label">Longitude</label><input name="longitude" class="at-input at-focusable" value="<?= Security::escape((string) ($store['longitude'] ?? '')) ?>"></div>
        <div><label class="at-label">Max radius (km)</label><input name="max_radius_km" class="at-input at-focusable" value="<?= Security::escape((string) ($store['max_radius_km'] ?? '12')) ?>"></div>
        <p class="text-xs at-muted">Haversine radius is used when pincode does not match a zone prefix.</p>
    </div></div>
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Slots & ETA</h3>
        <label class="flex gap-2 text-sm"><input type="checkbox" name="slots_enabled" value="1"<?= !empty($store['slots_enabled']) ? ' checked' : '' ?>> Enable delivery slots</label>
        <div><label class="at-label">Prep buffer (minutes)</label><input name="prep_minutes" type="number" class="at-input at-focusable" value="<?= (int) ($store['prep_minutes'] ?? 45) ?>"></div>
        <div class="grid grid-cols-2 gap-2">
            <div><label class="at-label">Opens</label><input name="business_open" class="at-input at-focusable" value="<?= Security::escape(substr((string) ($store['business_open'] ?? '08:00:00'), 0, 5)) ?>"></div>
            <div><label class="at-label">Closes</label><input name="business_close" class="at-input at-focusable" value="<?= Security::escape(substr((string) ($store['business_close'] ?? '20:00:00'), 0, 5)) ?>"></div>
        </div>
        <p class="text-xs at-muted">When slots are off, checkout shows <?= Security::escape(DeliverySlotService::automaticEta()) ?>.</p>
    </div></div>
    <div class="at-admin-card lg:col-span-2"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Delivery zones</h3>
        <div class="at-admin-table-wrap">
            <table class="at-admin-table at-admin-table--fixed">
                <thead><tr><th>Zone</th><th>Match</th><th>Flat fee ₹</th><th>Free above ₹</th></tr></thead>
                <tbody>
                <?php foreach ($zones as $z): ?>
                    <tr>
                        <td><?= Security::escape((string) $z['name']) ?></td>
                        <td><?= Security::escape((string) $z['match_type']) ?> <?= Security::escape((string) $z['match_value']) ?></td>
                        <td><input name="zone_fee[<?= (int) $z['id'] ?>]" class="at-input at-focusable w-24" value="<?= Security::escape((string) $z['flat_fee']) ?>"></td>
                        <td><input name="zone_free[<?= (int) $z['id'] ?>]" class="at-input at-focusable w-24" value="<?= Security::escape((string) $z['free_above']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs at-muted">Mixed-cart free delivery uses eligible subtotal only (excludes products marked “grants free delivery”).</p>
        <p class="text-xs"><a class="at-focusable underline" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/zones.php">Manage zones</a> · <a class="at-focusable underline" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/slots.php">Manage slots</a> · <a class="at-focusable underline" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/policies.php">Policies</a></p>
        <button type="submit" class="at-btn at-btn--primary at-focusable">Save settings</button>
    </div></div>
</form>
<?php admin_layout_end(); ?>
