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

function mkCat(string $slug, string $name, int $sort, string $status = 'published', int $showHome = 1): int
{
    global $createdIds;
    $res = CategoryRepository::save(null, [
        'name' => $name,
        'slug' => $slug,
        'slug_manual' => '1',
        'parent_id' => '',
        'description' => '',
        'sort_order' => $sort,
        'show_on_homepage' => $showHome ? '1' : '0',
        'status' => $status,
        'meta_title' => '',
        'meta_description' => '',
    ]);
    if (empty($res['ok'])) {
        throw new RuntimeException('category create failed');
    }
    $id = (int) ($res['id'] ?? 0);
    $createdIds[] = $id;

    return $id;
}

$a = mkCat('zt-cro-a-' . $tok, 'CRO A ' . $tok, 910);
$b = mkCat('zt-cro-b-' . $tok, 'CRO B ' . $tok, 920);
$c = mkCat('zt-cro-c-' . $tok, 'CRO C ' . $tok, 930, 'hidden', 0);

$baseline = CategoryRepository::orderedIdList();
t(in_array($a, $baseline, true) && in_array($b, $baseline, true) && in_array($c, $baseline, true), 'Test categories in ordered list');

$permute = static function (array $fullOrder, array $testIds, array $newTestOrder): array {
    $positions = [];
    foreach ($testIds as $tid) {
        $positions[] = (int) array_search($tid, $fullOrder, true);
    }
    $insertAt = min($positions);
    $without = array_values(array_filter($fullOrder, static fn (int $id): bool => !in_array($id, $testIds, true)));
    array_splice($without, $insertAt, 0, $newTestOrder);

    return $without;
};

$beforeNames = [];
foreach ([$a, $b, $c] as $id) {
    $beforeNames[$id] = (string) CategoryRepository::find($id)['name'];
}

$newOrder = $permute($baseline, [$a, $b, $c], [$c, $a, $b]);
$res = CategoryRepository::reorderAll($newOrder, $baseline);
t(!empty($res['ok']), 'Reorder save succeeds');

$idsAfter = CategoryRepository::orderedIdList();
$posC = array_search($c, $idsAfter, true);
$posA = array_search($a, $idsAfter, true);
$posB = array_search($b, $idsAfter, true);
t($posC !== false && $posA !== false && $posB !== false && $posC < $posA && $posA < $posB, 'Persisted order C, A, B');

t((int) CategoryRepository::find($c)['sort_order'] === $posC + 1, 'sort_order sequential');
t((string) CategoryRepository::find($c)['status'] === 'hidden', 'Hidden category stays hidden');
t((int) CategoryRepository::find($c)['show_on_homepage'] === 0, 'Homepage flag unchanged');
t((string) CategoryRepository::find($a)['name'] === $beforeNames[$a], 'Name unchanged');

$publishedIds = array_map(static fn (array $r): int => (int) $r['id'], CategoryRepository::published());
$subset = array_values(array_intersect($publishedIds, [$a, $b, $c]));
t($subset === [$a, $b], 'Published list order respects sort_order (hidden excluded)');

$homeIds = array_map(static fn (array $r): int => (int) $r['id'], CategoryRepository::homepage());
t(!in_array($c, $homeIds, true), 'Hidden category not on homepage');
$homeSubset = array_values(array_intersect($homeIds, [$a, $b]));
t($homeSubset === [$a, $b], 'Homepage tiles follow sort_order among published categories');

$stale = CategoryRepository::reorderAll($permute($idsAfter, [$a, $b, $c], [$b, $c, $a]), $baseline);
t(empty($stale['ok']) && str_contains((string) ($stale['message'] ?? ''), 'changed elsewhere'), 'Stale baseline rejected');
t(CategoryRepository::orderedIdList() === $idsAfter, 'Order unchanged after stale save');

$bogus = $idsAfter;
$bogus[0] = 999999;
t(empty(CategoryRepository::reorderAll($bogus, $idsAfter)['ok']), 'Invalid category id rejected');

$indexPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/categories/index.php');
t(strpos($indexPhp, "action === 'reorder'") !== false, 'Categories admin POST reorder');
t(strpos($indexPhp, 'data-at-category-reorder') !== false, 'Reorder UI marker');
t(strpos($indexPhp, 'Csrf::requireValid()') !== false, 'CSRF on POST');

$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');
t(strpos($adminJs, 'initCategoryReorder') !== false, 'admin.js category reorder init');

foreach (array_unique($createdIds) as $cid) {
    if ($cid <= 0) {
        continue;
    }
    CategoryRepository::deleteForAdmin($cid);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
