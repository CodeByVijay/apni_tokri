<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$errors = [];
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editRow = $editId ? DeliveryZoneRepository::find($editId) : null;
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost) {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($useDb) {
        try {
            if ($action === 'deactivate') {
                DeliveryZoneAdminService::setActive((int) ($_POST['zone_id'] ?? 0), false);
                admin_flash_set('Delivery area disabled. Customers can no longer be matched to it.');
            } elseif ($action === 'activate') {
                DeliveryZoneAdminService::setActive((int) ($_POST['zone_id'] ?? 0), true);
                admin_flash_set('Delivery area enabled.');
            } else {
                $id = isset($_POST['zone_id']) && $_POST['zone_id'] !== '' ? (int) $_POST['zone_id'] : null;
                $errors = DeliveryAdminUi::validateZoneInput($_POST);
                if ($errors === []) {
                    $result = DeliveryZoneAdminService::saveFromPost($_POST, $id);
                    if (!$result['ok']) {
                        $errors = array_map([DeliveryAdminUi::class, 'friendlyError'], $result['errors']);
                    } else {
                        admin_flash_set($id ? 'Delivery area updated.' : 'Delivery area created.');
                        header('Location: ' . url_base() . '/admin/settings/delivery/zones.php', true, 302);
                        exit;
                    }
                }
            }
        } catch (InvalidArgumentException $e) {
            $errors = [DeliveryAdminUi::friendlyError($e->getMessage())];
        }
        if ($action !== 'save') {
            if ($errors === []) {
                header('Location: ' . url_base() . '/admin/settings/delivery/zones.php', true, 302);
                exit;
            }
            admin_flash_set($errors[0], 'error');
            header('Location: ' . url_base() . '/admin/settings/delivery/zones.php', true, 302);
            exit;
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
$currentMode = DeliveryZoneResolver::zoneResolutionMode();
$store = DeliveryConfig::store();

/** Form value: submitted value after a failed save, else the saved zone, else default. */
$fv = static function (string $field, string $default = '') use ($isPost, $editRow): string {
    if ($isPost && array_key_exists($field, $_POST)) {
        return (string) $_POST[$field];
    }
    if ($editRow !== null && isset($editRow[$field])) {
        return (string) $editRow[$field];
    }

    return $default;
};
$formKind = $fv('zone_kind', 'pincode');
if (!isset(DeliveryAdminUi::KIND_LABELS[$formKind])) {
    $formKind = 'pincode';
}
$formActive = $isPost ? !empty($_POST['is_active']) : ($editRow === null || !empty($editRow['is_active']));

// Test calculator (read-only; same resolver and fee function as checkout)
$testRan = isset($_GET['test_pin']);
$test = $testRan
    ? DeliveryAdminUi::testCalculation(
        (string) ($_GET['test_pin'] ?? ''),
        (string) ($_GET['test_lat'] ?? ''),
        (string) ($_GET['test_lon'] ?? ''),
        (string) ($_GET['test_amount'] ?? '')
    )
    : null;

admin_layout_start(['pageTitle' => 'Delivery areas', 'activeAdminNav' => 'settings']);
$pageHeading = 'Delivery areas';
$pageDescription = 'Decide where you deliver, what you charge, and when delivery is free.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'zones';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';

$explainMode = $currentMode;
$explainZones = $zones;
$explainStore = $store;
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-explainer.php';

if ($errors !== []): ?>
    <div class="at-alert at-alert--error mb-4" role="alert">
        <p class="font-semibold mb-1">We could not save this delivery area:</p>
        <ul class="list-disc pl-5 text-sm"><?php foreach ($errors as $e): ?><li><?= Security::escape($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif;

if ($hasWeightCap && $missingWeights > 0): ?>
    <div class="at-alert at-alert--warning mb-4">
        <?= (int) $missingWeights ?> product variant(s) have no weight set. Maximum Order Weight limits will not be applied until product weights are added.
    </div>
<?php endif;

$modeMismatch = false;
if ($currentMode === 'pincode_legacy') {
    foreach ($zones as $z) {
        if (!empty($z['is_active']) && (string) ($z['zone_kind'] ?? 'pincode') !== 'pincode') {
            $modeMismatch = true;
            break;
        }
    }
}
if ($modeMismatch): ?>
    <div class="at-alert at-alert--warning mb-4">
        Some enabled delivery areas use distance, but your store is set to <strong>Pincode-based delivery</strong>, so those areas are not used.
        You can change this on the <a class="underline" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/policies.php">Delivery rules</a> page.
    </div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-4">
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold"><?= $editRow ? 'Edit delivery area' : 'Add a delivery area' ?></h3>
        <form method="post" class="space-y-4" id="zone-form" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editRow): ?><input type="hidden" name="zone_id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

            <div>
                <label class="at-label" for="zone-name">Delivery Area Name</label>
                <input id="zone-name" name="name" class="at-input at-focusable w-full" required maxlength="128" placeholder="Indore City" value="<?= Security::escape($fv('name')) ?>">
                <p class="text-xs at-muted mt-1">A name only you and your team see, for example “Indore City” or “Nearby villages (0–5 km)”.</p>
            </div>

            <div>
                <label class="at-label" for="zone-kind">Area Identification Method</label>
                <select id="zone-kind" name="zone_kind" class="at-input at-focusable w-full">
                    <?php foreach (DeliveryAdminUi::KIND_LABELS as $k => $label): ?>
                        <option value="<?= Security::escape($k) ?>"<?= $formKind === $k ? ' selected' : '' ?>><?= Security::escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs at-muted mt-1" data-kind-help="pincode">Customers are matched by the first digits of their pincode.</p>
                <p class="text-xs at-muted mt-1" data-kind-help="distance_band">Customers are matched by how far they are from your store (needs the customer's map location).</p>
                <p class="text-xs at-muted mt-1" data-kind-help="hybrid">Customers must match the pincode digits <em>and</em> be within the distance range.</p>
            </div>

            <div data-kind-section="pincode hybrid">
                <label class="at-label" for="zone-pin">Pincode Starting Digits</label>
                <input id="zone-pin" name="match_value" inputmode="numeric" maxlength="6" class="at-input at-focusable w-full" placeholder="4520" value="<?= Security::escape($fv('match_value')) ?>">
                <p class="text-xs at-muted mt-1">Example: entering <strong>4520</strong> covers every pincode that starts with 4520 (452001, 452010, 452099 …). Enter up to 6 digits.</p>
            </div>

            <div data-kind-section="distance_band hybrid">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="at-label" for="zone-min">Minimum Distance (km)</label>
                        <input id="zone-min" name="min_distance_km" type="number" min="0" step="0.01" class="at-input at-focusable w-full" placeholder="0" value="<?= Security::escape($fv('min_distance_km')) ?>">
                    </div>
                    <div>
                        <label class="at-label" for="zone-max">Maximum Distance (km)</label>
                        <input id="zone-max" name="max_distance_km" type="number" min="0" step="0.01" class="at-input at-focusable w-full" placeholder="5" value="<?= Security::escape($fv('max_distance_km')) ?>">
                    </div>
                </div>
                <p class="text-xs at-muted mt-1">Example: Minimum 0 and Maximum 5 covers customers from 0 km up to, but not including, 5 km from your store. The next area can start at 5. Distances are straight-line, not road distance.</p>
            </div>

            <fieldset class="space-y-3">
                <legend class="at-label">Charges</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="at-label" for="zone-fee">Delivery Charge (₹)</label>
                        <input id="zone-fee" name="flat_fee" type="number" min="0" step="0.01" class="at-input at-focusable w-full" required value="<?= Security::escape($fv('flat_fee', '40')) ?>">
                        <p class="text-xs at-muted mt-1">What the customer pays for delivery.</p>
                    </div>
                    <div>
                        <label class="at-label" for="zone-free">Free Delivery on Orders Above (₹)</label>
                        <input id="zone-free" name="free_above" type="number" min="0" step="0.01" class="at-input at-focusable w-full" required value="<?= Security::escape($fv('free_above', '499')) ?>">
                        <p class="text-xs at-muted mt-1">Orders of this amount or more get free delivery.</p>
                    </div>
                </div>
                <div>
                    <label class="at-label" for="zone-min-order">Minimum Order Amount (₹)</label>
                    <input id="zone-min-order" name="minimum_order_amount" type="number" min="0" step="0.01" class="at-input at-focusable w-full" value="<?= Security::escape($fv('minimum_order_amount')) ?>">
                    <p class="text-xs at-muted mt-1">Leave blank to use the global minimum order amount configured in Marketing settings.</p>
                </div>
                <p class="text-xs at-muted" id="zone-charge-preview" aria-live="polite"></p>
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="at-label" for="zone-weight">Maximum Order Weight (kg) — optional</label>
                    <input id="zone-weight" name="max_order_weight_kg" type="number" min="0" step="0.001" class="at-input at-focusable w-full" value="<?= Security::escape($fv('max_order_weight_kg')) ?>">
                    <p class="text-xs at-muted mt-1">Leave empty for no weight limit. Example: 25.</p>
                </div>
                <div>
                    <label class="at-label" for="zone-eta">Expected Delivery Time</label>
                    <input id="zone-eta" name="eta_display" maxlength="128" class="at-input at-focusable w-full" placeholder="Within 2 hours" value="<?= Security::escape($fv('eta_display')) ?>">
                    <p class="text-xs at-muted mt-1">Shown to customers, e.g. “Same day” or “Within 2 hours”.</p>
                </div>
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" class="mt-1"<?= $formActive ? ' checked' : '' ?>>
                <span><strong>Enable Delivery in This Area</strong><br><span class="text-xs at-muted">Untick to keep the area saved but stop delivering there.</span></span>
            </label>

            <details class="rounded border p-3">
                <summary class="cursor-pointer text-sm font-semibold">Advanced: priority</summary>
                <div class="mt-2">
                    <label class="at-label" for="zone-sort">Priority number</label>
                    <input id="zone-sort" name="sort_order" type="number" class="at-input at-focusable w-28" value="<?= (int) $fv('sort_order', '0') ?>">
                    <p class="text-xs at-muted mt-1">If an address could match more than one area, the area with the lower number is used first. Most stores can leave this at 0.</p>
                </div>
            </details>

            <div class="flex flex-wrap gap-2">
                <button type="submit" class="at-btn at-btn--primary at-focusable"><?= $editRow ? 'Save changes' : 'Add delivery area' ?></button>
                <?php if ($editRow): ?><a class="at-btn at-focusable" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/zones.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div></div>

    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold">Test a delivery address</h3>
        <p class="text-xs at-muted">Check what a customer would get. This only reads your settings — nothing is saved or charged. Currently using <strong><?= Security::escape(DeliveryAdminUi::modeLabel($currentMode)) ?></strong>.</p>
        <form method="get" class="space-y-3">
            <div>
                <label class="at-label" for="t-pin">Customer pincode</label>
                <input id="t-pin" name="test_pin" inputmode="numeric" maxlength="6" class="at-input at-focusable w-full" placeholder="452010" value="<?= Security::escape((string) ($_GET['test_pin'] ?? '')) ?>">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="at-label" for="t-lat">Map location: Latitude (optional)</label>
                    <input id="t-lat" name="test_lat" class="at-input at-focusable w-full" placeholder="22.7196" value="<?= Security::escape((string) ($_GET['test_lat'] ?? '')) ?>">
                </div>
                <div>
                    <label class="at-label" for="t-lon">Map location: Longitude (optional)</label>
                    <input id="t-lon" name="test_lon" class="at-input at-focusable w-full" placeholder="75.8577" value="<?= Security::escape((string) ($_GET['test_lon'] ?? '')) ?>">
                </div>
            </div>
            <div>
                <label class="at-label" for="t-amt">Order amount ₹ (optional)</label>
                <input id="t-amt" name="test_amount" type="number" min="0" step="0.01" class="at-input at-focusable w-full" placeholder="350" value="<?= Security::escape((string) ($_GET['test_amount'] ?? '')) ?>">
            </div>
            <button type="submit" class="at-btn at-focusable">Check delivery</button>
        </form>

        <?php if ($test !== null): ?>
            <?php require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-test-result.php'; ?>
        <?php endif; ?>
    </div></div>
</div>

<div class="at-admin-card mt-4"><div class="at-admin-card__body">
    <h3 class="font-bold mb-3">Your delivery areas</h3>
    <?php if ($zones === []): ?>
        <p class="text-sm at-muted">No delivery areas yet. Add your first one using the form above.</p>
    <?php else: ?>
    <div class="at-admin-table-wrap">
        <table class="at-admin-table">
            <thead><tr><th>Delivery area</th><th>Identified</th><th>Covers</th><th>Charges</th><th>Expected time</th><th>Status</th><th class="is-actions"></th></tr></thead>
            <tbody>
            <?php foreach ($zones as $z): $isOn = !empty($z['is_active']); ?>
                <tr<?= $isOn ? '' : ' class="opacity-60"' ?>>
                    <td><strong><?= Security::escape((string) $z['name']) ?></strong></td>
                    <td><?= Security::escape(DeliveryAdminUi::kindLabel((string) ($z['zone_kind'] ?? 'pincode'))) ?></td>
                    <td><?= Security::escape(DeliveryAdminUi::coverage($z)) ?></td>
                    <td><?= Security::escape(DeliveryAdminUi::chargeSummary($z)) ?></td>
                    <td><?= trim((string) ($z['eta_display'] ?? '')) !== '' ? Security::escape((string) $z['eta_display']) : '—' ?></td>
                    <td><?= admin_status_badge($isOn ? 'active' : 'inactive', $isOn ? 'Delivering' : 'Not delivering') ?></td>
                    <td class="is-actions">
                        <span class="at-admin-actions">
                            <?= admin_action_edit_link('?edit=' . (int) $z['id'], 'Edit') ?>
                        </span>
                        <form method="post" class="inline mt-1 sm:mt-0 sm:ml-2">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="zone_id" value="<?= (int) $z['id'] ?>">
                            <input type="hidden" name="action" value="<?= $isOn ? 'deactivate' : 'activate' ?>">
                            <button type="submit" class="at-btn at-btn--sm <?= $isOn ? 'at-btn--danger' : 'at-btn--ghost' ?> at-focusable"><?= $isOn ? 'Disable' : 'Enable' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div></div>

<script <?= Security::cspNonceAttr() ?>>
(function () {
    var form = document.getElementById('zone-form');
    if (!form) { return; }
    var kind = document.getElementById('zone-kind');
    function apply() {
        var k = kind.value;
        form.querySelectorAll('[data-kind-section]').forEach(function (sec) {
            var show = sec.getAttribute('data-kind-section').split(' ').indexOf(k) !== -1;
            sec.classList.toggle('hidden', !show);
            sec.querySelectorAll('input').forEach(function (inp) { inp.disabled = !show; });
        });
        form.querySelectorAll('[data-kind-help]').forEach(function (p) {
            p.classList.toggle('hidden', p.getAttribute('data-kind-help') !== k);
        });
    }
    function money(n) { return '₹' + (Math.round(n * 100) / 100).toString(); }
    var fee = document.getElementById('zone-fee'), free = document.getElementById('zone-free'), prev = document.getElementById('zone-charge-preview');
    function preview() {
        var f = parseFloat(fee.value), t = parseFloat(free.value);
        prev.textContent = (isNaN(f) || isNaN(t)) ? '' :
            'Customers pay ' + money(f) + ' on orders below ' + money(t) + '; orders of ' + money(t) + ' or more get free delivery.';
    }
    kind.addEventListener('change', apply);
    fee.addEventListener('input', preview);
    free.addEventListener('input', preview);
    form.addEventListener('submit', function (e) {
        var msgs = [], k = kind.value;
        if (!document.getElementById('zone-name').value.trim()) { msgs.push('Please enter a Delivery Area Name.'); }
        var pin = document.getElementById('zone-pin').value.trim();
        if (k !== 'distance_band' && !pin) { msgs.push('Please enter the Pincode Starting Digits, for example 4520.'); }
        if (pin && k !== 'distance_band' && !/^\d{1,6}$/.test(pin)) { msgs.push('Pincode Starting Digits must be 1 to 6 digits only.'); }
        if (k !== 'pincode') {
            var mn = parseFloat(document.getElementById('zone-min').value), mx = parseFloat(document.getElementById('zone-max').value);
            if (isNaN(mn) || isNaN(mx)) { msgs.push('Please enter both a Minimum and a Maximum Distance.'); }
            else if (mn < 0 || mx <= mn) { msgs.push('Maximum Distance must be greater than Minimum Distance.'); }
        }
        var mo = document.getElementById('zone-min-order').value.trim();
        if (mo !== '' && (isNaN(parseFloat(mo)) || parseFloat(mo) <= 0)) { msgs.push('Minimum Order Amount must be a number above 0, or left empty.'); }
        if (msgs.length) { e.preventDefault(); alert(msgs.join('\n')); }
    });
    apply();
    preview();
})();
</script>
<?php admin_layout_end(); ?>
