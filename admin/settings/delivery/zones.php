<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$errors = [];
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editRow = $editId ? DeliveryZoneRepository::find($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($useDb) {
        try {
            if ($action === 'deactivate') {
                DeliveryZoneAdminService::setActive((int) ($_POST['zone_id'] ?? 0), false);
                admin_flash_set('Zone deactivated.');
            } elseif ($action === 'activate') {
                DeliveryZoneAdminService::setActive((int) ($_POST['zone_id'] ?? 0), true);
                admin_flash_set('Zone activated.');
            } else {
                $id = isset($_POST['zone_id']) && $_POST['zone_id'] !== '' ? (int) $_POST['zone_id'] : null;
                $result = DeliveryZoneAdminService::saveFromPost($_POST, $id);
                if (!$result['ok']) {
                    $errors = $result['errors'];
                } else {
                    admin_flash_set($id ? 'Zone updated.' : 'Zone created.');
                    header('Location: ' . url_base() . '/admin/settings/delivery/zones.php', true, 302);
                    exit;
                }
            }
        } catch (InvalidArgumentException $e) {
            $errors = [$e->getMessage()];
        }
        if ($action !== 'save' || $errors === []) {
            if ($action !== 'save') {
                header('Location: ' . url_base() . '/admin/settings/delivery/zones.php', true, 302);
                exit;
            }
        }
    }
}

$zones = $useDb ? DeliveryZoneAdminService::listAll() : [];
$missingWeights = $useDb ? DeliveryZoneAdminService::countVariantsMissingWeight() : 0;
$hasWeightCap = false;
foreach ($zones as $z) {
    if (!empty($z['max_order_weight_kg'])) {
        $hasWeightCap = true;
        break;
    }
}

admin_layout_start(['pageTitle' => 'Delivery zones', 'activeAdminNav' => 'settings']);
$pageHeading = 'Delivery zones';
$pageDescription = 'Pincode, distance bands [min, max) km, and hybrid rules. Overlapping active bands are rejected on save.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'zones';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';

if ($errors !== []): ?>
    <div class="at-admin-card mb-4"><div class="at-admin-card__body text-red-700">
        <ul class="list-disc pl-5"><?php foreach ($errors as $e): ?><li><?= Security::escape($e) ?></li><?php endforeach; ?></ul>
    </div></div>
<?php endif;

if ($hasWeightCap && $missingWeights > 0): ?>
    <div class="at-admin-card mb-4"><div class="at-admin-card__body text-amber-800 bg-amber-50 rounded-lg p-3 text-sm">
        <?= (int) $missingWeights ?> product variant(s) have no weight_kg. Weight limits are not enforced until weights are configured.
    </div></div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-4">
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold"><?= $editRow ? 'Edit zone' : 'Add zone' ?></h3>
        <form method="post" class="space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editRow): ?><input type="hidden" name="zone_id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div><label class="at-label">Name</label><input name="name" class="at-input at-focusable w-full" required value="<?= Security::escape((string) ($editRow['name'] ?? $_POST['name'] ?? '')) ?>"></div>
            <div><label class="at-label">Kind</label>
                <select name="zone_kind" class="at-input at-focusable w-full">
                    <?php foreach (['pincode' => 'Pincode prefix', 'distance_band' => 'Distance band', 'hybrid' => 'Hybrid (pin + distance)'] as $k => $label): ?>
                        <option value="<?= Security::escape($k) ?>"<?= (($editRow['zone_kind'] ?? $_POST['zone_kind'] ?? 'pincode') === $k) ? ' selected' : '' ?>><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="at-label">Pincode prefix</label><input name="match_value" class="at-input at-focusable w-full" placeholder="4520" value="<?= Security::escape((string) ($editRow['match_value'] ?? $_POST['match_value'] ?? '')) ?>"></div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="at-label">Min km (inclusive)</label><input name="min_distance_km" type="number" step="0.01" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['min_distance_km'] ?? $_POST['min_distance_km'] ?? '')) ?>"></div>
                <div><label class="at-label">Max km (exclusive)</label><input name="max_distance_km" type="number" step="0.01" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['max_distance_km'] ?? $_POST['max_distance_km'] ?? '')) ?>"></div>
            </div>
            <p class="text-xs at-muted">Distance bands use [min, max) km. Hybrid requires pin prefix and customer map coordinates at checkout.</p>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="at-label">Flat fee ₹</label><input name="flat_fee" type="number" step="0.01" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['flat_fee'] ?? $_POST['flat_fee'] ?? '40')) ?>"></div>
                <div><label class="at-label">Free above ₹</label><input name="free_above" type="number" step="0.01" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['free_above'] ?? $_POST['free_above'] ?? '499')) ?>"></div>
            </div>
            <div><label class="at-label">Max order weight (kg, optional)</label><input name="max_order_weight_kg" type="number" step="0.001" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['max_order_weight_kg'] ?? $_POST['max_order_weight_kg'] ?? '')) ?>"></div>
            <div><label class="at-label">ETA label</label><input name="eta_display" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['eta_display'] ?? $_POST['eta_display'] ?? '')) ?>"></div>
            <div><label class="at-label">Sort order</label><input name="sort_order" type="number" class="at-input at-focusable w-24" value="<?= (int) ($editRow['sort_order'] ?? $_POST['sort_order'] ?? 0) ?>"></div>
            <label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1"<?= !isset($editRow) || !empty($editRow['is_active']) ? ' checked' : '' ?>> Active</label>
            <button type="submit" class="at-btn at-btn--primary at-focusable"><?= $editRow ? 'Update zone' : 'Create zone' ?></button>
            <?php if ($editRow): ?><a class="at-btn at-focusable ml-2" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/zones.php">Cancel edit</a><?php endif; ?>
        </form>
    </div></div>

    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Test resolver</h3>
        <p class="text-xs at-muted">Mode: <?= Security::escape(DeliveryZoneResolver::zoneResolutionMode()) ?>. Straight-line km only (Haversine).</p>
        <?php
        $testPin = preg_replace('/\D+/', '', (string) ($_GET['test_pin'] ?? '')) ?? '';
        $testLat = isset($_GET['test_lat']) && $_GET['test_lat'] !== '' ? (float) $_GET['test_lat'] : null;
        $testLon = isset($_GET['test_lon']) && $_GET['test_lon'] !== '' ? (float) $_GET['test_lon'] : null;
        ?>
        <form method="get" class="space-y-2">
            <div><label class="at-label">Pincode</label><input name="test_pin" class="at-input at-focusable" value="<?= Security::escape($testPin) ?>"></div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="at-label">Latitude</label><input name="test_lat" class="at-input at-focusable" value="<?= Security::escape((string) ($_GET['test_lat'] ?? '')) ?>"></div>
                <div><label class="at-label">Longitude</label><input name="test_lon" class="at-input at-focusable" value="<?= Security::escape((string) ($_GET['test_lon'] ?? '')) ?>"></div>
            </div>
            <button type="submit" class="at-btn at-focusable">Run test</button>
        </form>
        <?php if ($testPin !== '' && preg_match('/^\d{6}$/', $testPin)): ?>
            <?php $tr = DeliveryZoneResolver::resolveForAdminTest($testPin, $testLat, $testLon); ?>
            <pre class="text-xs bg-slate-50 p-3 rounded overflow-auto"><?= Security::escape(json_encode($tr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php endif; ?>
    </div></div>
</div>

<div class="at-admin-card mt-4"><div class="at-admin-card__body">
    <h3 class="font-bold mb-3">All zones</h3>
    <div class="at-admin-table-wrap">
        <table class="at-admin-table">
            <thead><tr><th>Name</th><th>Kind</th><th>Pin</th><th>Band km</th><th>Fees</th><th>Active</th><th class="is-actions"></th></tr></thead>
            <tbody>
            <?php foreach ($zones as $z): ?>
                <tr>
                    <td><?= Security::escape((string) $z['name']) ?></td>
                    <td><?= Security::escape((string) ($z['zone_kind'] ?? 'pincode')) ?></td>
                    <td><?= Security::escape((string) ($z['match_value'] ?? '')) ?></td>
                    <td><?php
                        if (isset($z['min_distance_km'], $z['max_distance_km'])) {
                            echo Security::escape('[' . $z['min_distance_km'] . ', ' . $z['max_distance_km'] . ')');
                        } else {
                            echo '—';
                        }
                    ?></td>
                    <td>₹<?= Security::escape((string) $z['flat_fee']) ?> / free ₹<?= Security::escape((string) $z['free_above']) ?></td>
                    <td><?= admin_status_badge(!empty($z['is_active']) ? 'active' : 'inactive', !empty($z['is_active']) ? 'Active' : 'Inactive') ?></td>
                    <td class="is-actions">
                        <span class="at-admin-actions">
                            <?= admin_action_edit_link('?edit=' . (int) $z['id'], 'Edit') ?>
                        </span>
                        <form method="post" class="inline mt-1 sm:mt-0 sm:ml-2">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="zone_id" value="<?= (int) $z['id'] ?>">
                            <input type="hidden" name="action" value="<?= !empty($z['is_active']) ? 'deactivate' : 'activate' ?>">
                            <button type="submit" class="at-btn at-btn--sm <?= !empty($z['is_active']) ? 'at-btn--danger' : 'at-btn--ghost' ?> at-focusable"><?= !empty($z['is_active']) ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div></div>
<?php admin_layout_end(); ?>
