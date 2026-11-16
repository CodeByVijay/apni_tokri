<?php

declare(strict_types=1);

/**
 * Storefront PDP primary image ordering (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-product-storefront-primary-image.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okP(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = bin2hex(random_bytes(3));

$pdo->exec("INSERT INTO categories (name, slug, status, sort_order) VALUES ('Primary Cat', 'prim-cat-{$tok}', 'published', 1)");
$catId = (int) $pdo->lastInsertId();
$ptype = ProductTypeRepository::defaultCode();
$slug = 'prim-prod-' . $tok;
$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status) VALUES (?, ?, ?, ?, ?, ?)'
)->execute(['Primary Test', $slug, 'PRI-' . $tok, $catId, $ptype, 'published']);
$pid = (int) $pdo->lastInsertId();

$paths = [
    'uploads/products/prim-' . $tok . '-a.jpg',
    'uploads/products/prim-' . $tok . '-b.jpg',
    'uploads/products/prim-' . $tok . '-c.jpg',
];
foreach ($paths as $i => $path) {
    $pdo->prepare(
        'INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, ?, ?)'
    )->execute([$pid, $path, $i, $i === 0 ? 1 : 0]);
}

$imageIds = [];
$stmt = $pdo->prepare('SELECT id, file_path FROM product_images WHERE product_id = ? ORDER BY sort_order ASC');
$stmt->execute([$pid]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $imageIds[$row['file_path']] = (int) $row['id'];
}

$loadFirst = static function (int $productId) use ($slug): string {
    $row = ProductRepository::findBySlug($slug, true);
    if ($row === null) {
        return '';
    }
    $pub = ProductRepository::toPublicArray($row);

    return (string) ($pub['images'][0] ?? '');
};

$primaryCount = static function (int $productId) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
    $st->execute([$productId]);

    return (int) $st->fetchColumn();
};

$expectPath = $paths[0];
okP(str_ends_with($loadFirst($pid), basename($expectPath)), 'first image primary → first in storefront list');

ProductRepository::setPrimaryImage($pid, $imageIds[$paths[1]]);
okP($primaryCount($pid) === 1, 'only one primary after set on second image');
okP(str_ends_with($loadFirst($pid), basename($paths[1])), 'second image primary → first in storefront list');

$adminOrder = ProductRepository::images($pid);
okP((string) ($adminOrder[0]['file_path'] ?? '') === $paths[0], 'admin gallery still ordered by sort_order');

$storeRows = ProductRepository::storefrontImages($pid);
okP((string) ($storeRows[0]['file_path'] ?? '') === $paths[1], 'storefrontImages primary first');
okP(
    array_map(static fn (array $r): string => (string) $r['file_path'], array_slice($storeRows, 1))
    === [$paths[0], $paths[2]],
    'non-primary images keep sort_order relative order'
);

ProductRepository::setPrimaryImage($pid, $imageIds[$paths[2]]);
okP(str_ends_with($loadFirst($pid), basename($paths[2])), 'third image primary → first in storefront list');

$pub = ProductRepository::toPublicArray(ProductRepository::find($pid));
okP((int) ($pub['gallery_initial_index'] ?? -1) === 0, 'gallery_initial_index is 0 after primary-first ordering');
okP(count($pub['images'] ?? []) === 3, 'storefront exposes all images');

ProductRepository::setPrimaryImage($pid, $imageIds[$paths[0]]);
$pub2 = ProductRepository::toPublicArray(ProductRepository::find($pid));
okP(str_ends_with((string) ($pub2['images'][0] ?? ''), basename($paths[0])), 'reload: first primary again first in list');

$gallery = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/product-gallery.php');
okP(str_contains($gallery, 'data-initial-index'), 'gallery partial exposes initial index for JS');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/catalogue.js');
okP(str_contains($js, 'data-initial-index'), 'catalogue.js reads gallery initial index');

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
