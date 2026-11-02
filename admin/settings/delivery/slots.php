<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$errors = [];
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editRow = $editId ? DeliverySlotRepository::find($editId) : null;
$capacityGrid = $useDb ? DeliverySlotAdminService::nextSevenDayCapacity() : [];
$dates = [];
if ($useDb) {
    $start = DeliveryConfig::now();
    for ($i = 0; $i < 7; $i++) {
        $dates[] = $start->modify('+' . $i . ' days')->format('Y-m-d');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? 'save');
    if ($useDb) {
        try {
            if ($action === 'deactivate') {
                DeliverySlotAdminService::setActive((int) ($_POST['slot_id'] ?? 0), false);
                admin_flash_set('Slot deactivated.');
                header('Location: ' . url_base() . '/admin/settings/delivery/slots.php', true, 302);
                exit;
            }
            if ($action === 'activate') {
                DeliverySlotAdminService::setActive((int) ($_POST['slot_id'] ?? 0), true);
                admin_flash_set('Slot activated.');
                header('Location: ' . url_base() . '/admin/settings/delivery/slots.php', true, 302);
                exit;
            }
            $id = isset($_POST['slot_id']) && $_POST['slot_id'] !== '' ? (int) $_POST['slot_id'] : null;
            $result = DeliverySlotAdminService::saveFromPost($_POST, $id);
            if (!$result['ok']) {
                $errors = $result['errors'];
            } else {
                admin_flash_set($id ? 'Slot updated.' : 'Slot created.');
                header('Location: ' . url_base() . '/admin/settings/delivery/slots.php', true, 302);
                exit;
            }
        } catch (InvalidArgumentException $e) {
            $errors = [$e->getMessage()];
        }
    }
}

$slots = $useDb ? DeliverySlotRepository::all() : [];
$providers = DeliverySlotService::knownProviderSlugs();
$selectedProviders = $editRow
    ? DeliverySlotAdminService::decodeProviders((string) ($editRow['allowed_provider_slugs_json'] ?? ''))
    : ['manual'];

admin_layout_start(['pageTitle' => 'Delivery slots', 'activeAdminNav' => 'settings']);
admin_flash_render();
$pageHeading = 'Delivery slots';
$pageDescription = 'Windows, capacity, weekdays, cutoffs, and provider eligibility (Asia/Kolkata). Deactivate instead of delete when orders reference a slot.';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$deliveryNavActive = 'slots';
require APNI_TOKRI_ROOT . '/includes/partials/admin/delivery-settings-nav.php';

if ($errors !== []): ?>
    <div class="at-admin-card mb-4"><div class="at-admin-card__body text-red-700">
        <ul class="list-disc pl-5"><?php foreach ($errors as $e): ?><li><?= Security::escape($e) ?></li><?php endforeach; ?></ul>
    </div></div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-4">
    <div class="at-admin-card"><div class="at-admin-card__body space-y-3">
        <h3 class="font-bold"><?= $editRow ? 'Edit slot' : 'Add slot' ?></h3>
        <form method="post" class="space-y-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editRow): ?><input type="hidden" name="slot_id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="at-label">Name</label><input name="label" class="at-input at-focusable w-full" required value="<?= Security::escape((string) ($editRow['label'] ?? '')) ?>"></div>
                <div><label class="at-label">Slug</label><input name="slug" class="at-input at-focusable w-full" required value="<?= Security::escape((string) ($editRow['slug'] ?? '')) ?>"<?= $editRow && DeliverySlotRepository::orderReferenceCount((int) $editRow['id']) > 0 ? ' readonly' : '' ?>></div>
            </div>
            <div><label class="at-label">Window label</label><input name="window_label" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['window_label'] ?? '')) ?>"></div>
            <div><label class="at-label">Delivery day rule</label>
                <select name="delivery_day" class="at-input at-focusable w-full">
                    <option value="same_day"<?= (($editRow['delivery_day'] ?? 'same_day') === 'same_day') ? ' selected' : '' ?>>Same day (checkout date)</option>
                    <option value="next_day"<?= (($editRow['delivery_day'] ?? '') === 'next_day') ? ' selected' : '' ?>>Next calendar day</option>
                </select>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <div><label class="at-label">Start</label><input name="start_time" type="time" class="at-input at-focusable w-full" value="<?= Security::escape(substr((string) ($editRow['start_time'] ?? '09:00:00'), 0, 5)) ?>"></div>
                <div><label class="at-label">End</label><input name="end_time" type="time" class="at-input at-focusable w-full" value="<?= Security::escape(substr((string) ($editRow['end_time'] ?? '12:00:00'), 0, 5)) ?>"></div>
                <div><label class="at-label">Legacy cutoff</label><input name="cutoff_time" type="time" class="at-input at-focusable w-full" value="<?= Security::escape(substr((string) ($editRow['cutoff_time'] ?? '08:30:00'), 0, 5)) ?>"></div>
            </div>
            <p class="text-xs at-muted">Booking deadline (Asia/Kolkata): the <strong>earliest</strong> of — (1) legacy cutoff time on the delivery date, (2) slot start minus booking cutoff minutes, (3) slot start minus prep buffer (slot override or store default from Dispatch). Customers cannot book after that moment.</p>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="at-label">Max orders</label><input name="capacity" type="number" min="1" class="at-input at-focusable w-full" value="<?= (int) ($editRow['capacity'] ?? 25) ?>"></div>
                <div><label class="at-label">Sort order</label><input name="sort_order" type="number" class="at-input at-focusable w-full" value="<?= (int) ($editRow['sort_order'] ?? 0) ?>"></div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="at-label">Prep buffer (min)</label><input name="prep_buffer_minutes" type="number" class="at-input at-focusable w-full" placeholder="Store default" value="<?= Security::escape((string) ($editRow['prep_buffer_minutes'] ?? '')) ?>"></div>
                <div><label class="at-label">Booking cutoff (min before start)</label><input name="booking_cutoff_minutes" type="number" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['booking_cutoff_minutes'] ?? '')) ?>"></div>
            </div>
            <div><label class="at-label">Max delivery distance (km)</label><input name="max_distance_km" type="number" step="0.01" class="at-input at-focusable w-full" value="<?= Security::escape((string) ($editRow['max_distance_km'] ?? '')) ?>"></div>
            <fieldset><legend class="at-label">Weekdays (Asia/Kolkata delivery date)</legend>
                <div class="flex flex-wrap gap-2 text-sm">
                    <?php
                    $mask = (int) ($editRow['weekday_mask'] ?? 127);
                    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                    foreach ($days as $n => $label):
                        $checked = ($mask & (1 << ($n - 1))) !== 0;
                        ?>
                        <label class="flex gap-1"><input type="checkbox" name="weekday_<?= $n ?>" value="1"<?= $checked ? ' checked' : '' ?>><?= $label ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset><legend class="at-label">Allowed providers</legend>
                <?php foreach ($providers as $slug => $label): ?>
                    <?php $enabled = $slug === 'manual'; ?>
                    <label class="flex gap-2 text-sm<?= !$enabled ? ' at-muted' : '' ?>">
                        <input type="checkbox" name="providers[]" value="<?= Security::escape($slug) ?>"<?= in_array($slug, $selectedProviders, true) ? ' checked' : '' ?><?= !$enabled ? ' disabled' : '' ?>>
                        <?= Security::escape($label) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label class="flex gap-2 text-sm"><input type="checkbox" name="is_active" value="1"<?= !isset($editRow) || !empty($editRow['is_active']) ? ' checked' : '' ?>> Active</label>
            <button type="submit" class="at-btn at-btn--primary at-focusable"><?= $editRow ? 'Update slot' : 'Create slot' ?></button>
            <?php if ($editRow): ?><a class="at-btn at-focusable ml-2" href="<?= Security::escape(url_base()) ?>/admin/settings/delivery/slots.php">Cancel</a><?php endif; ?>
        </form>
    </div></div>

    <div class="at-admin-card"><div class="at-admin-card__body">
        <h3 class="font-bold mb-2">Capacity — next 7 days</h3>
        <p class="text-xs at-muted mb-2">Capacity used = paid orders + active unexpired holds. Consumed reservations are counted only via paid orders. Orphan pending orders (no active hold) are shown separately and do not consume capacity.</p>
        <?php if ($slots === []): ?>
            <p class="text-sm at-muted">No slots configured.</p>
        <?php else: ?>
            <div class="at-admin-table-wrap overflow-x-auto">
                <table class="at-admin-table at-admin-table--fixed text-xs">
                    <thead><tr><th>Slot</th><?php foreach ($dates as $d): ?><th><?= Security::escape(substr($d, 5)) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($slots as $s): ?>
                        <tr>
                            <td><?= Security::escape((string) $s['slug']) ?></td>
                            <?php foreach ($dates as $d):
                                $st = $capacityGrid[(int) $s['id']][$d] ?? ['paid' => 0, 'holds' => 0, 'used' => 0, 'remaining' => 0, 'capacity' => 0, 'orphans' => 0];
                                ?>
                                <td>
                                    used <?= (int) $st['used'] ?>/<?= (int) $st['capacity'] ?>
                                    (paid <?= (int) $st['paid'] ?>, hold <?= (int) $st['holds'] ?>, <?= (int) $st['remaining'] ?> left)
                                    <?php if (!empty($st['orphans'])): ?><span class="text-amber-700"> · <?= (int) $st['orphans'] ?> orphan pending</span><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div></div>
</div>

<div class="at-admin-card mt-4"><div class="at-admin-card__body">
    <h3 class="font-bold mb-3">All slots</h3>
    <div class="at-admin-table-wrap">
        <table class="at-admin-table">
            <thead><tr><th>Slug</th><th>Window</th><th class="is-num">Cap</th><th class="is-num">Orders</th><th>Active</th><th class="is-actions"></th></tr></thead>
            <tbody>
            <?php foreach ($slots as $s): ?>
                <tr>
                    <td><?= Security::escape((string) $s['slug']) ?></td>
                    <td><?= Security::escape((string) $s['window_label']) ?> · <?= Security::escape(substr((string) $s['start_time'], 0, 5)) ?>–<?= Security::escape(substr((string) $s['end_time'], 0, 5)) ?></td>
                    <td class="is-num"><?= (int) $s['capacity'] ?></td>
                    <td class="is-num"><?= DeliverySlotRepository::orderReferenceCount((int) $s['id']) ?></td>
                    <td><?= admin_status_badge(!empty($s['is_active']) ? 'active' : 'inactive', !empty($s['is_active']) ? 'Active' : 'Inactive') ?></td>
                    <td class="is-actions">
                        <a class="at-btn at-btn--sm at-btn--secondary at-focusable" href="?edit=<?= (int) $s['id'] ?>">Edit</a>
                        <form method="post" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="action" value="<?= !empty($s['is_active']) ? 'deactivate' : 'activate' ?>">
                            <button type="submit" class="at-btn at-btn--sm <?= !empty($s['is_active']) ? 'at-btn--danger' : 'at-btn--ghost' ?> at-focusable"><?= !empty($s['is_active']) ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div></div>
<?php admin_layout_end(); ?>
