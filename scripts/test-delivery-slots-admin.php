<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "Schema not ready.\n");
    exit(1);
}

Install::ensureConnection();
$pdo = Database::pdo();
$pass = 0;
$fail = 0;

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

$tok = bin2hex(random_bytes(3));
$createdSlotIds = [];
$createdOrderIds = [];

function mkSlot(string $slug, string $label): int
{
    global $createdSlotIds;
    $res = DeliverySlotAdminService::saveFromPost([
        'label' => $label,
        'slug' => $slug,
        'window_label' => $label,
        'delivery_day' => 'same_day',
        'start_time' => '09:00',
        'end_time' => '12:00',
        'cutoff_time' => '08:30',
        'capacity' => '5',
        'sort_order' => '99',
        'is_active' => '1',
        'weekday_1' => '1',
    ], null);
    if (empty($res['ok'])) {
        throw new RuntimeException('slot create failed');
    }
    $row = DeliverySlotRepository::findBySlug($slug);
    $id = (int) ($row['id'] ?? 0);
    $createdSlotIds[] = $id;

    return $id;
}

// Auto slug from name
$autoLabel = 'Evening Express ' . $tok;
$autoSlug = CatalogueSlug::fromName($autoLabel);
$res = DeliverySlotAdminService::saveFromPost([
    'label' => $autoLabel,
    'slug' => '',
    'window_label' => 'Express',
    'delivery_day' => 'same_day',
    'start_time' => '18:00',
    'end_time' => '20:00',
    'cutoff_time' => '17:00',
    'capacity' => '8',
    'sort_order' => '50',
    'is_active' => '1',
    'weekday_1' => '1',
], null);
t(!empty($res['ok']), 'Create slot with blank slug');
$row = DeliverySlotRepository::findBySlug($autoSlug);
t($row !== null && (string) $row['slug'] === $autoSlug, 'Slug auto-generated from slot name');
if ($row !== null) {
    $createdSlotIds[] = (int) $row['id'];
}

// Manual slug
$manualSlug = 'zt-manual-' . $tok;
$resM = DeliverySlotAdminService::saveFromPost([
    'label' => 'Ignored Label ' . $tok,
    'slug' => $manualSlug,
    'slug_manual' => '1',
    'window_label' => 'Manual',
    'delivery_day' => 'same_day',
    'start_time' => '10:00',
    'end_time' => '11:00',
    'cutoff_time' => '09:00',
    'capacity' => '3',
    'sort_order' => '51',
    'is_active' => '1',
    'weekday_2' => '1',
], null);
t(!empty($resM['ok']), 'Manual slug save');
$manualRow = DeliverySlotRepository::findBySlug($manualSlug);
t($manualRow !== null && (string) $manualRow['slug'] === $manualSlug, 'Manual slug preserved');
if ($manualRow !== null) {
    $createdSlotIds[] = (int) $manualRow['id'];
}

// Duplicate slug
$dup = DeliverySlotAdminService::saveFromPost([
    'label' => 'Duplicate Try',
    'slug' => $manualSlug,
    'slug_manual' => '1',
    'window_label' => 'X',
    'delivery_day' => 'same_day',
    'start_time' => '10:00',
    'end_time' => '11:00',
    'cutoff_time' => '09:00',
    'capacity' => '3',
    'sort_order' => '52',
    'is_active' => '1',
    'weekday_1' => '1',
], null);
t(empty($dup['ok']), 'Duplicate slug rejected');

// Clear slug resumes auto on update
if ($row !== null) {
    $id = (int) $row['id'];
    $upd = DeliverySlotAdminService::saveFromPost([
        'label' => 'Renamed Express ' . $tok,
        'slug' => '',
        'window_label' => 'Express',
        'delivery_day' => 'same_day',
        'start_time' => '18:00',
        'end_time' => '20:00',
        'cutoff_time' => '17:00',
        'capacity' => '8',
        'sort_order' => '50',
        'is_active' => '1',
        'weekday_1' => '1',
    ], $id);
    t(!empty($upd['ok']), 'Update with cleared slug succeeds');
    $expected = CatalogueSlug::fromName('Renamed Express ' . $tok);
    $after = DeliverySlotRepository::find($id);
    t($after !== null && (string) $after['slug'] === $expected, 'Clearing slug regenerates from name');
}

// Pagination + search
for ($i = 0; $i < 12; $i++) {
    mkSlot('zt-page-' . $tok . '-' . $i, 'ZT Page Slot ' . $tok . ' ' . $i);
}
$page1 = DeliverySlotRepository::listForAdmin('ZT Page Slot ' . $tok, 1, 10);
t($page1['total'] >= 12 && count($page1['rows']) === 10 && $page1['per_page'] === 10, 'Pagination page size 10');
$page2 = DeliverySlotRepository::listForAdmin('ZT Page Slot ' . $tok, 2, 10);
t(count($page2['rows']) >= 2 && $page2['page'] === 2, 'Pagination page 2');
t(DeliverySlotRepository::normalizeListPerPage(999) === 25, 'Invalid page size defaults to 25');
$uniqueSearchSlug = 'zt-search-only-' . $tok;
mkSlot($uniqueSearchSlug, 'Search Target ' . $tok);
$bySlug = DeliverySlotRepository::listForAdmin($uniqueSearchSlug, 1, 10);
t($bySlug['total'] === 1 && ($bySlug['rows'][0]['slug'] ?? '') === $uniqueSearchSlug, 'Search by slug');

// Delete unused slot
$orphanSlug = 'zt-delete-ok-' . $tok;
$orphanId = mkSlot($orphanSlug, 'Delete Me ' . $tok);
$delOk = DeliverySlotAdminService::deleteForAdmin($orphanId);
t(!empty($delOk['ok']) && DeliverySlotRepository::find($orphanId) === null, 'Delete unused slot');

// Block delete when order references slot
$refSlug = 'zt-delete-block-' . $tok;
$refId = mkSlot($refSlug, 'Referenced ' . $tok);
$pdo->prepare(
    "INSERT INTO orders (order_number, status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot, delivery_slot_id, delivery_date, placed_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,NOW())"
)->execute(['ZT-SLOT-' . $tok, 'confirmed', 100, 0, 40, 140, 'paid', $refSlug, $refId, date('Y-m-d')]);
$createdOrderIds[] = (int) $pdo->lastInsertId();
$delBlock = DeliverySlotAdminService::deleteForAdmin($refId);
t(empty($delBlock['ok']) && str_contains((string) ($delBlock['message'] ?? ''), 'cannot be deleted'), 'Referenced slot delete blocked');
DeliverySlotAdminService::setActive($refId, false);
t((int) DeliverySlotRepository::find($refId)['is_active'] === 0, 'Deactivate referenced slot');

// CSRF + permission wiring (static)
$slotsPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/delivery/slots.php');
t(strpos($slotsPhp, 'Csrf::requireValid()') !== false, 'Slots admin POST requires CSRF');
t(strpos($slotsPhp, 'AdminDemoPerms::denyIfCannot') !== false, 'Slots admin requires settings permission');
t(strpos($slotsPhp, 'data-at-admin-slug') !== false, 'Slot form uses shared slug sync');
t(strpos($slotsPhp, 'admin_icon_delete_button') !== false, 'Delete uses trash icon helper');
t(strpos($slotsPhp, 'admin_list_pagination') !== false, 'List pagination partial used');

// Cleanup
foreach ($createdOrderIds as $oid) {
    $pdo->exec('DELETE FROM orders WHERE id = ' . (int) $oid);
}
foreach (array_unique($createdSlotIds) as $sid) {
    if ($sid <= 0) {
        continue;
    }
    if (DeliverySlotRepository::hasBlockingReferences($sid)) {
        continue;
    }
    $pdo->prepare('DELETE FROM delivery_slots WHERE id = ?')->execute([$sid]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
