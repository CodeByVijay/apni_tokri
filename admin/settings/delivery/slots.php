<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_init.php';
require_once APNI_TOKRI_ROOT . '/includes/admin/list_actions.php';
require_once APNI_TOKRI_ROOT . '/includes/partials/admin/list-pagination.php';

AdminDemoPerms::denyIfCannot('settings');

$useDb = Install::isSchemaReady() && AdminAuth::check();
$self = url_base() . '/admin/settings/delivery/slots.php';
$errors = [];
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editRow = $editId ? DeliverySlotRepository::find($editId) : null;
$slugLocked = $editRow !== null && DeliverySlotRepository::orderReferenceCount((int) $editRow['id']) > 0;

$listQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$listPage = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$listPerPage = DeliverySlotRepository::normalizeListPerPage(isset($_GET['per_page']) ? (int) $_GET['per_page'] : 25);
$listQueryBase = array_filter(['q' => $listQ !== '' ? $listQ : null]);

$capacityGrid = $useDb ? DeliverySlotAdminService::nextSevenDayCapacity() : [];
$allSlots = $useDb ? DeliverySlotRepository::all() : [];
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
            if ($action === 'delete') {
                $delId = (int) ($_POST['slot_id'] ?? 0);
                $result = DeliverySlotAdminService::deleteForAdmin($delId);
                admin_flash_set(
                    (string) ($result['message'] ?? ($result['ok'] ? 'Deleted.' : 'Could not delete.')),
                    !empty($result['ok']) ? 'success' : 'error'
                );
                $redirectQs = array_filter([
                    'q' => trim((string) ($_POST['list_q'] ?? '')),
                    'page' => max(1, (int) ($_POST['list_page'] ?? 1)),
                    'per_page' => DeliverySlotRepository::normalizeListPerPage((int) ($_POST['list_per_page'] ?? 25)),
                ]);
                header('Location: ' . $self . ($redirectQs !== [] ? '?' . http_build_query($redirectQs) : ''), true, 302);
                exit;
            }
            if ($action === 'deactivate') {
                DeliverySlotAdminService::setActive((int) ($_POST['slot_id'] ?? 0), false);
                admin_flash_set('Slot deactivated.');
                header('Location: ' . $self . ($listQueryBase !== [] ? '?' . http_build_query(array_merge($listQueryBase, ['page' => $listPage, 'per_page' => $listPerPage])) : ''), true, 302);
                exit;
            }
            if ($action === 'activate') {
                DeliverySlotAdminService::setActive((int) ($_POST['slot_id'] ?? 0), true);
                admin_flash_set('Slot activated.');
                header('Location: ' . $self . ($listQueryBase !== [] ? '?' . http_build_query(array_merge($listQueryBase, ['page' => $listPage, 'per_page' => $listPerPage])) : ''), true, 302);
                exit;
            }
            $id = isset($_POST['slot_id']) && $_POST['slot_id'] !== '' ? (int) $_POST['slot_id'] : null;
            $result = DeliverySlotAdminService::saveFromPost($_POST, $id);
            if (!$result['ok']) {
                $errors = $result['errors'];
            } else {
                admin_flash_set($id ? 'Slot updated.' : 'Slot created.');
                header('Location: ' . $self, true, 302);
                exit;
            }
        } catch (InvalidArgumentException $e) {
            $errors = [$e->getMessage()];
        }
    }
}

$slotList = $useDb
    ? DeliverySlotRepository::listForAdmin($listQ !== '' ? $listQ : null, $listPage, $listPerPage)
    : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $listPerPage];
$providers = DeliverySlotService::knownProviderSlugs();
$selectedProviders = $editRow
    ? DeliverySlotAdminService::decodeProviders((string) ($editRow['allowed_provider_slugs_json'] ?? ''))
    : ['manual'];

admin_layout_start(['pageTitle' => 'Delivery slots', 'activeAdminNav' => 'settings']);
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
        <form method="post" class="space-y-3"<?= !$slugLocked ? ' data-at-admin-slug data-slug-api="' . Security::escape(url_base()) . '/admin/ajax/slug-from-name.php"' : '' ?>>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editRow): ?><input type="hidden" name="slot_id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <?php if ($slugLocked): ?>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="at-label" for="slot-label">Name</label><input id="slot-label" name="label" class="at-input at-focusable w-full" required value="<?= Security::escape((string) ($editRow['label'] ?? '')) ?>"></div>
                    <div><label class="at-label" for="slot-slug">Slug</label><input id="slot-slug" name="slug" class="at-input at-focusable w-full" required readonly value="<?= Security::escape((string) ($editRow['slug'] ?? '')) ?>"><p class="text-xs at-muted mt-1">Slug is locked because orders reference this slot.</p></div>
                </div>
            <?php else:
                $slugSourceId = 'slot-label';
                $slugSourceName = 'label';
                $slugSourceLabel = 'Name';
                $slugSourceValue = (string) ($editRow['label'] ?? '');
                $slugTargetId = 'slot-slug';
                $slugTargetValue = (string) ($editRow['slug'] ?? '');
                $slugManualId = 'slot-slug-manual';
                $slugHint = 'Generated from the name as you type. Edit the slug to customize; clear it to resume auto-generation.';
                $slugError = null;
                $slugTargetMaxLength = 32;
                require APNI_TOKRI_ROOT . '/includes/partials/admin/slug-field.php';
            endif; ?>
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
            <?php if ($editRow): ?><a class="at-btn at-focusable ml-2" href="<?= Security::escape($self) ?>">Cancel</a><?php endif; ?>
        </form>
    </div></div>

    <div class="at-admin-card"><div class="at-admin-card__body">
        <h3 class="font-bold mb-2">Capacity — next 7 days</h3>
        <p class="text-xs at-muted mb-2">Capacity used = paid orders + active unexpired holds. Consumed reservations are counted only via paid orders. Orphan pending orders (no active hold) are shown separately and do not consume capacity.</p>
        <?php if ($allSlots === []): ?>
            <p class="text-sm at-muted">No slots configured.</p>
        <?php else: ?>
            <div class="at-admin-table-wrap overflow-x-auto">
                <table class="at-admin-table at-admin-table--fixed text-xs">
                    <thead><tr><th>Slot</th><?php foreach ($dates as $d): ?><th><?= Security::escape(substr($d, 5)) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($allSlots as $s): ?>
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
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
        <h3 class="font-bold">All slots</h3>
        <form method="get" class="flex flex-wrap gap-2 items-end">
            <div>
                <label class="at-label" for="slot-search-q">Search</label>
                <input id="slot-search-q" name="q" class="at-input at-focusable" value="<?= Security::escape($listQ) ?>" placeholder="Name or slug">
            </div>
            <input type="hidden" name="per_page" value="<?= (int) $slotList['per_page'] ?>">
            <button type="submit" class="at-btn at-btn--secondary at-focusable">Search</button>
            <?php if ($listQ !== ''): ?>
                <a class="at-btn at-btn--ghost at-focusable" href="<?= Security::escape($self) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="at-admin-table-wrap at-admin-table-wrap--delivery-slots">
        <table class="at-admin-table at-admin-table--delivery-slots">
            <colgroup>
                <col style="width:11%">
                <col style="width:12%">
                <col style="width:18%">
                <col style="width:13%">
                <col style="width:7%">
                <col style="width:15%">
                <col style="width:9%">
                <col style="width:15%">
            </colgroup>
            <thead><tr>
                <th class="at-dslots-col-name">Name</th>
                <th class="at-dslots-col-slug">Slug</th>
                <th class="at-dslots-col-window">Window</th>
                <th class="at-dslots-col-cutoff">Legacy cutoff</th>
                <th class="is-num at-dslots-col-cap">Cap</th>
                <th class="is-num at-dslots-col-orders" title="Paid non-cancelled orders plus active checkout holds, summed across all delivery dates (per-date counts are in the grid above)">Orders (all dates)</th>
                <th class="at-dslots-col-active">Active</th>
                <th class="is-actions at-dslots-col-actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php if ($slotList['rows'] === []): ?>
                <tr><td colspan="8" class="text-sm at-muted">No slots match your search.</td></tr>
            <?php endif; ?>
            <?php foreach ($slotList['rows'] as $s):
                $sid = (int) $s['id'];
                $label = (string) ($s['label'] ?? $s['slug']);
                $deleteConfirm = 'Delete delivery slot “' . str_replace(['"', "\r", "\n"], '', $label) . '”? This cannot be undone.';
                ?>
                <?php
                $winLabel = trim((string) ($s['window_label'] ?? ''));
                $winStart = DeliveryAdminUi::formatClockTime12h((string) ($s['start_time'] ?? ''));
                $winEnd = DeliveryAdminUi::formatClockTime12h((string) ($s['end_time'] ?? ''));
                $cutoffClock = DeliveryAdminUi::formatClockTime12h((string) ($s['cutoff_time'] ?? ''));
                ?>
                <tr>
                    <td class="at-dslots-col-name"><?= Security::escape($label) ?></td>
                    <td class="at-dslots-col-slug"><?= Security::escape((string) $s['slug']) ?></td>
                    <td class="at-dslots-col-window">
                        <?php if ($winLabel !== ''): ?>
                            <span class="at-dslots-window__label"><?= Security::escape($winLabel) ?></span>
                        <?php endif; ?>
                        <span class="at-dslots-window__times"><?= Security::escape($winStart) ?> – <?= Security::escape($winEnd) ?></span>
                    </td>
                    <td class="at-dslots-col-cutoff">
                        <span class="at-dslots-cutoff__time"><?= Security::escape($cutoffClock) ?></span>
                        <span class="at-dslots-cutoff__tz at-muted">Asia/Kolkata</span>
                    </td>
                    <td class="is-num at-dslots-col-cap"><?= (int) $s['capacity'] ?></td>
                    <td class="is-num at-dslots-col-orders"><?= DeliverySlotRepository::capacityConsumingOrderCount($sid) ?></td>
                    <td class="at-dslots-col-active"><?= admin_status_badge(!empty($s['is_active']) ? 'active' : 'inactive', !empty($s['is_active']) ? 'Active' : 'Inactive') ?></td>
                    <td class="is-actions at-dslots-col-actions">
                        <span class="at-admin-actions at-admin-actions--delivery-slots">
                            <?= admin_action_edit_link('?edit=' . $sid . ($listQ !== '' ? '&q=' . rawurlencode($listQ) : '') . '&page=' . (int) $slotList['page'] . '&per_page=' . (int) $slotList['per_page'], 'Edit slot') ?>
                            <form method="post" class="at-admin-actions__form" onsubmit="return confirm(<?= json_encode($deleteConfirm, JSON_UNESCAPED_UNICODE) ?>);">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="slot_id" value="<?= $sid ?>">
                                <input type="hidden" name="list_q" value="<?= Security::escape($listQ) ?>">
                                <input type="hidden" name="list_page" value="<?= (int) $slotList['page'] ?>">
                                <input type="hidden" name="list_per_page" value="<?= (int) $slotList['per_page'] ?>">
                                <?= admin_icon_delete_button('Delete slot') ?>
                            </form>
                            <form method="post" class="at-admin-actions__form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="slot_id" value="<?= $sid ?>">
                                <input type="hidden" name="action" value="<?= !empty($s['is_active']) ? 'deactivate' : 'activate' ?>">
                                <button type="submit" class="at-btn at-btn--sm <?= !empty($s['is_active']) ? 'at-btn--danger' : 'at-btn--ghost' ?> at-focusable"><?= !empty($s['is_active']) ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    admin_list_pagination(
        (int) $slotList['page'],
        (int) $slotList['total'],
        (int) $slotList['per_page'],
        $listQueryBase,
        $self
    );
    ?>
</div></div>
<?php admin_layout_end(); ?>
