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
$tok = bin2hex(random_bytes(3));
$createdIds = [];

function t(bool $c, string $m): void
{
    global $pass, $fail;
    echo ($c ? 'PASS' : 'FAIL') . ': ' . $m . "\n";
    $c ? $pass++ : $fail++;
}

function mkSlot(string $slug, string $label, int $sort, bool $active = true): int
{
    global $createdIds;
    $res = DeliverySlotAdminService::saveFromPost([
        'label' => $label,
        'slug' => $slug,
        'window_label' => $label,
        'delivery_day' => 'same_day',
        'start_time' => '09:00',
        'end_time' => '12:00',
        'cutoff_time' => '08:30',
        'capacity' => '5',
        'sort_order' => (string) $sort,
        'is_active' => $active ? '1' : '0',
        'weekday_1' => '1',
    ], null);
    if (empty($res['ok'])) {
        throw new RuntimeException('slot create failed: ' . $slug);
    }
    $row = DeliverySlotRepository::findBySlug($slug);
    $id = (int) ($row['id'] ?? 0);
    $createdIds[] = $id;

    return $id;
}

$a = mkSlot('zt-ro-a-' . $tok, 'RO A ' . $tok, 10);
$b = mkSlot('zt-ro-b-' . $tok, 'RO B ' . $tok, 20);
$c = mkSlot('zt-ro-c-' . $tok, 'RO C ' . $tok, 30, false);

$baseline = DeliverySlotRepository::orderedIdList();
$ourBaseline = array_values(array_filter($baseline, static fn (int $id): bool => in_array($id, [$a, $b, $c], true)));
t(count($ourBaseline) === 3, 'Three test slots present in ordered list');

$permuteTestSlots = static function (array $fullOrder, array $testIds, array $newTestOrder): array {
    $positions = [];
    foreach ($testIds as $tid) {
        $positions[] = (int) array_search($tid, $fullOrder, true);
    }
    $insertAt = min($positions);
    $without = array_values(array_filter($fullOrder, static fn (int $id): bool => !in_array($id, $testIds, true)));
    array_splice($without, $insertAt, 0, $newTestOrder);

    return $without;
};

$beforeA = (int) DeliverySlotRepository::find($a)['is_active'];
$newOrder = $permuteTestSlots($baseline, [$a, $b, $c], [$c, $a, $b]);
$res = DeliverySlotAdminService::reorderAllSlots($newOrder, $baseline);
t(!empty($res['ok']), 'Reorder save succeeds with valid baseline');

$idsAfter = DeliverySlotRepository::orderedIdList();
$posC = array_search($c, $idsAfter, true);
$posA = array_search($a, $idsAfter, true);
$posB = array_search($b, $idsAfter, true);
t($posC !== false && $posA !== false && $posB !== false && $posC < $posA && $posA < $posB, 'New sequence persisted (C before A before B)');

t((int) DeliverySlotRepository::find($c)['sort_order'] === $posC + 1, 'sort_order is sequential (C)');
t((int) DeliverySlotRepository::find($a)['is_active'] === $beforeA, 'Active flag unchanged');
t((int) DeliverySlotRepository::find($c)['is_active'] === 0, 'Inactive slot remains inactive');

$activeStmt = $pdo->prepare('SELECT id FROM delivery_slots WHERE is_active = 1 AND id IN (?,?,?) ORDER BY sort_order, id');
$activeStmt->execute([$a, $b, $c]);
$orderedTestActive = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
t($orderedTestActive === [$a, $b], 'Customer-facing active list order follows sort_order (active only)');

$staleBaseline = $baseline;
$staleAttempt = $permuteTestSlots($idsAfter, [$a, $b, $c], [$b, $c, $a]);
$resStale = DeliverySlotAdminService::reorderAllSlots($staleAttempt, $staleBaseline);
t(empty($resStale['ok']) && str_contains((string) ($resStale['message'] ?? ''), 'changed elsewhere'), 'Stale baseline rejected');

$afterStale = DeliverySlotRepository::orderedIdList();
t($afterStale === $idsAfter, 'Order unchanged after failed stale save');

$bogusOrder = $idsAfter;
$bogusOrder[0] = 999999;
$bogus = DeliverySlotAdminService::reorderAllSlots($bogusOrder, $idsAfter);
t(empty($bogus['ok']), 'Arbitrary slot ID rejected');

$slotsPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/settings/delivery/slots.php');
t(strpos($slotsPhp, "action === 'reorder'") !== false, 'Admin POST handles reorder action');
t(strpos($slotsPhp, 'Reorder slots') !== false, 'Reorder slots action present');
t(strpos($slotsPhp, 'data-at-delivery-slot-reorder') !== false, 'Reorder UI root marker');

$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');
t(strpos($adminJs, 'initDeliverySlotReorder') !== false, 'admin.js initializes slot reorder');

foreach (array_unique($createdIds) as $sid) {
    if ($sid <= 0 || DeliverySlotRepository::hasBlockingReferences($sid)) {
        continue;
    }
    $pdo->prepare('DELETE FROM delivery_slots WHERE id = ?')->execute([$sid]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
