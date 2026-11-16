<?php

declare(strict_types=1);

/**
 * Admin products list catalogue columns (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-admin-products-list-catalogue.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okCat(bool $c, string $m): void
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
$ptype = ProductTypeRepository::defaultCode();

$pdo->exec("INSERT INTO categories (name, slug, status, sort_order) VALUES ('Cat {$tok}', 'cat-{$tok}', 'published', 1)");
$catId = (int) $pdo->lastInsertId();

$slug = "cat-list-{$tok}";
$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'Catalogue Stats Product', $slug, "SKU-{$tok}", $catId, $ptype, 'draft',
    'S', 'D', '', 'Has description only', 1,
]);
$pid = (int) $pdo->lastInsertId();

$row = ProductRepository::searchForAdmin('', 'cat-' . $tok, '', 1, 10, 'updated_at', 'desc')['rows'][0] ?? [];
okCat((int) ($row['catalogue_image_count'] ?? -1) === 0, 'zero images shows 0');
okCat(ProductRepository::adminCatalogueSeoCompleteFromRow($row) === false, 'SEO missing when meta title empty');

$pdo->prepare(
    'INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, 1, 1), (?, ?, 2, 0)'
)->execute([$pid, 'uploads/tmp/pri-' . $tok . '.png', $pid, 'uploads/tmp/sec-' . $tok . '.png']);

$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, 10, 5, 1, 1, 1), (?, ?, ?, 20, 5, 1, 1, 2), (?, ?, ?, 30, 5, 1, 1, 3)'
)->execute([
    $pid, 'A', 'V-A-' . $tok,
    $pid, 'B', 'V-B-' . $tok,
    $pid, 'C', 'V-C-' . $tok,
]);

$pdo->prepare(
    'INSERT INTO product_attributes (product_id, attr_key, attr_value, sort_order) VALUES
     (?, ?, ?, 1), (?, ?, ?, 2), (?, ?, ?, 3), (?, ?, ?, 4)'
)->execute([
    $pid, 'Origin', 'MP',
    $pid, 'Pack', 'Box',
    $pid, 'Origin', 'MP',
    $pid, 'Weight', '500g',
]);

$row = ProductRepository::searchForAdmin($slug, '', '', 1, 10, 'updated_at', 'desc')['rows'][0] ?? [];
okCat(ProductRepository::adminCatalogueImageCountFromRow($row) === 2, 'primary and secondary images counted');
okCat(ProductRepository::adminCatalogueVariantCountFromRow($row) === 3, 'variant count correct');
okCat(ProductRepository::adminCatalogueAttributeCountFromRow($row) === 3, 'distinct attribute keys (duplicate Origin ignored)');

$pdo->prepare('UPDATE products SET meta_title = ?, meta_description = ? WHERE id = ?')
    ->execute(['Full title', 'Full description', $pid]);
$row = ProductRepository::find($pid);
okCat(ProductRepository::adminCatalogueSeoCompleteFromRow(
    ProductRepository::searchForAdmin($slug, '', '', 1, 10, 'updated_at', 'desc')['rows'][0] ?? []
), 'SEO complete when title and description set');
okCat(
    ProductRepository::adminCatalogueSeoCompleteFromRow(['meta_title' => 'T', 'meta_description' => '', 'robots_index' => 1, 'catalogue_seo_complete' => 0]) === false,
    'robots_index alone does not mark SEO complete'
);

$mediaTable = $pdo->query("SHOW TABLES LIKE 'media_assets'")->fetchColumn();
if ($mediaTable) {
    $pdo->prepare(
        'INSERT INTO media_assets (original_filename, storage_filename, file_path, mime_type, file_size, alt_text)
         VALUES (?, ?, ?, ?, 100, ?)'
    )->execute(['trash.png', 'trash-' . $tok . '.png', 'uploads/media/trash-' . $tok . '.png', 'image/png', '']);
    $mediaId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO product_images (product_id, media_id, file_path, sort_order, is_primary)
         VALUES (?, ?, ?, 3, 0)'
    )->execute([$pid, $mediaId, 'uploads/media/trash-' . $tok . '.png']);
    $pdo->prepare('UPDATE media_assets SET deleted_at = NOW() WHERE id = ?')->execute([$mediaId]);
    $row = ProductRepository::searchForAdmin($slug, '', '', 1, 10, 'updated_at', 'desc')['rows'][0] ?? [];
    okCat(ProductRepository::adminCatalogueImageCountFromRow($row) === 2, 'trashed media not counted');
} else {
    echo "SKIP: media_assets table (run with --migrate-media-library)\n";
}

$draftRow = ProductRepository::searchForAdmin('', 'cat-' . $tok, 'draft', 1, 50, 'updated_at', 'desc');
okCat($draftRow['total'] >= 1, 'status filter still returns draft product');
$filtered = ProductRepository::searchForAdmin('Catalogue Stats', '', '', 1, 50, 'updated_at', 'desc');
okCat($filtered['total'] === 1, 'search filter still works');
okCat(ProductRepository::countAdminExport('Catalogue Stats', '', '') === 1, 'export count query unchanged');

$src = file_get_contents(APNI_TOKRI_ROOT . '/includes/catalogue/product_repository.php') ?: '';
okCat(
    substr_count($src, 'adminCatalogueStatsSelectSql') >= 2
        && strpos($src, 'catalogue_image_count') !== false,
    'listing uses aggregate subqueries (no per-row queries in index)'
);

echo "\nTotal failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
