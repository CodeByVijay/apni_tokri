<?php

declare(strict_types=1);

/**
 * Bulk product export (disposable DB).
 * Run: php scripts/run-in-disposable-db.php scripts/test-product-bulk-export.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
$skipped = 0;
function okEx(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function skipEx(string $m): void
{
    global $skipped;
    echo 'SKIP: ' . $m . "\n";
    $skipped++;
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = bin2hex(random_bytes(3));

$pdo->exec("INSERT INTO categories (name, slug, status, sort_order) VALUES ('Ex Cat', 'ex-cat-{$tok}', 'published', 1)");
$catId = (int) $pdo->lastInsertId();
$ptype = ProductTypeRepository::defaultCode();

$ids = [];
for ($i = 1; $i <= 3; $i++) {
    $slug = "ex-prod-{$tok}-{$i}";
    $sku = "EX-SKU-{$tok}-{$i}";
    $pdo->prepare(
        'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'Export Product ' . $i, $slug, $sku, $catId, $ptype,
        $i === 1 ? 'published' : ($i === 2 ? 'draft' : 'archived'),
        'Short ' . $i, 'Full ' . $i, 'Meta ' . $i, 'Desc meta ' . $i, $i % 2,
    ]);
    $ids[] = (int) $pdo->lastInsertId();
}

$pdo->prepare(
    'INSERT INTO product_variants (product_id, name, sku, price, sale_price, stock_qty, low_stock_threshold, is_active, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1), (?, ?, ?, ?, NULL, ?, ?, 1, 2)'
)->execute([
    $ids[0], '500 g', 'EX-V1-A', 100, 90, 5, 2,
    $ids[0], '1 kg', 'EX-V1-B', 180, 5, 2,
]);

$pdo->prepare(
    'INSERT INTO product_attributes (product_id, attr_key, attr_value, sort_order) VALUES (?, ?, ?, 1), (?, ?, ?, 2)'
)->execute([$ids[0], 'Origin', 'MP', $ids[0], 'Pack', 'Pouch']);

$imgDir = APNI_TOKRI_ROOT . '/uploads/products/ex-test-' . $tok;
@mkdir($imgDir, 0755, true);
$pngPath = $imgDir . '/shared.png';
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
) ?: 'x';
file_put_contents($pngPath, $png);
$rel = 'uploads/products/ex-test-' . $tok . '/shared.png';
$pdo->prepare(
    'INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, 1, 1), (?, ?, 2, 0)'
)->execute([$ids[0], $rel, $ids[1], $rel]);

$missingPath = 'uploads/products/ex-test-' . $tok . '/missing-file.jpg';
$pdo->prepare(
    'INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, 1, 1)'
)->execute([$ids[2], $missingPath]);

okEx(ProductBulkExport::resolveExportCount(['scope' => ProductBulkExport::SCOPE_ALL])['count'] >= 3, 'count all includes test products');
okEx(
    ProductBulkExport::resolveExportCount(['scope' => ProductBulkExport::SCOPE_FILTERED, 'status' => 'draft'])['count'] >= 1,
    'status filter count'
);
okEx(
    ProductBulkExport::resolveExportCount(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => [$ids[0], $ids[1]]])['count'] === 2,
    'selected count'
);
okEx(
    ProductBulkExport::resolveExportCount(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => [99999999]])['count'] === 0,
    'invalid ids count zero'
);

okEx(SimpleXlsx::sanitizeCellString('=1+1')[0] === "'", 'spreadsheet sanitize leading equals');
okEx(SimpleXlsx::sanitizeCellString('+cmd') === "'+cmd", 'spreadsheet sanitize leading plus');
okEx(SimpleXlsx::sanitizeCellString('-1+2') === "'-1+2", 'spreadsheet sanitize leading minus');
okEx(SimpleXlsx::sanitizeCellString('@evil') === "'@evil", 'spreadsheet sanitize leading at');
okEx(SimpleXlsx::sanitizeCellString('hello') === 'hello', 'spreadsheet sanitize plain text');
okEx(SimpleXlsx::sanitizeCellString('Export Product 1') === 'Export Product 1', 'spreadsheet leaves normal product name');

$badScope = ProductBulkExport::validateAndNormalizeExportFilters(['scope' => 'injected']);
okEx(empty($badScope['ok']), 'invalid export scope rejected');
$badStatus = ProductBulkExport::validateAndNormalizeExportFilters(['scope' => ProductBulkExport::SCOPE_FILTERED, 'status' => 'invalid']);
okEx(empty($badStatus['ok']), 'invalid export status rejected');
$okScope = ProductBulkExport::validateAndNormalizeExportFilters(['scope' => ProductBulkExport::SCOPE_ALL]);
okEx(!empty($okScope['ok']) && ($okScope['filters']['scope'] ?? '') === ProductBulkExport::SCOPE_ALL, 'valid scope all accepted');
$bogusCount = ProductBulkExport::resolveExportCount(['scope' => 'injected', 'status' => 'draft']);
okEx(empty($bogusCount['ok']), 'resolveExportCount rejects invalid scope');
$badStatusCount = ProductBulkExport::resolveExportCount(['scope' => ProductBulkExport::SCOPE_FILTERED, 'status' => 'bogus']);
okEx(empty($badStatusCount['ok']), 'resolveExportCount rejects invalid status');

$listTotal = ProductRepository::searchForAdmin('Export Product', 'ex-cat-' . $tok, '', 1, 50, 'updated_at', 'desc')['total'];
$exportTotal = ProductRepository::countAdminExport('Export Product', 'ex-cat-' . $tok, '');
okEx($listTotal === $exportTotal, 'admin list total matches export count (q+cat)');
$listDraft = ProductRepository::searchForAdmin('', 'ex-cat-' . $tok, 'draft', 1, 50, 'updated_at', 'desc')['total'];
$exportDraft = ProductRepository::countAdminExport('', 'ex-cat-' . $tok, 'draft');
okEx($listDraft === $exportDraft, 'admin list total matches export count (status)');

$resolved = ProductBulkExport::resolveExportProductIds([
    'scope' => ProductBulkExport::SCOPE_SELECTED,
    'product_ids' => [$ids[0], 0, -1, 99999999],
]);
okEx(!empty($resolved['ok']) && count($resolved['product_ids']) === 1, 'invalid product ids filtered');

$tooManyIds = range(1, ProductBulkExport::MAX_EXPORT + 1);
$tooMany = ProductBulkExport::resolveExportProductIds(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => $tooManyIds]);
okEx(empty($tooMany['ok']), 'selected scope rejects more than MAX_EXPORT unique ids');
$capCount = ProductBulkExport::resolveExportCount(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => $tooManyIds]);
okEx(!empty($capCount['capped']), 'selected count preview capped over MAX_EXPORT');

$formulaSlug = 'ex-formula-' . $tok;
$formulaSku = 'EX-FORM-' . $tok;
$pdo->prepare(
    'INSERT INTO products (name, slug, sku, category_id, product_type, status, short_description, description, meta_title, meta_description, robots_index)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    '=SUM(1+1)', $formulaSlug, $formulaSku, $catId, $ptype, 'draft',
    '-1+2', '@note', '+meta', '=desc', 0,
]);
$formulaId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO product_attributes (product_id, attr_key, attr_value, sort_order) VALUES (?, ?, ?, 1)'
)->execute([$formulaId, '=key', '+value']);

$data = ProductBulkExport::buildExportData([$ids[0], $ids[1], $ids[2]], true);
okEx(!empty($data['ok']), 'build export data');
$sheets = $data['sheets'] ?? [];
okEx(isset($sheets[ProductBulkImport::SHEET_PRODUCTS][1]), 'products rows');
$hdr = $sheets[ProductBulkImport::SHEET_PRODUCTS][0] ?? [];
okEx(in_array('export_product_id', $hdr, true) && in_array('status', $hdr, true), 'products export headers');
okEx($sheets[ProductBulkImport::SHEET_PRODUCTS][1][0] === (string) $ids[0], 'export_product_id column');
okEx($sheets[ProductBulkImport::SHEET_PRODUCTS][1][1] === ProductBulkExport::productCode($ids[0]), 'product_code');
okEx(count($sheets[ProductBulkImport::SHEET_VARIANTS] ?? []) >= 3, 'variant rows incl header');
okEx(count($sheets[ProductBulkImport::SHEET_ATTRIBUTES] ?? []) >= 3, 'attribute rows');
okEx(count($sheets[ProductBulkImport::SHEET_IMAGES] ?? []) >= 3, 'image rows');
$seoRow = $sheets[ProductBulkImport::SHEET_SEO][1] ?? [];
okEx(($seoRow[3] ?? '') === 'yes' || ($seoRow[3] ?? '') === 'no', 'seo allow_indexing');

okEx(isset($data['image_files']) && count($data['image_files']) === 1, 'shared image deduped in file map');
okEx(($data['missing_images'] ?? []) !== [], 'missing image reported');

$dl = ProductBulkExport::prepareDownloadFile(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => $ids, 'include_images' => false]);
okEx(!empty($dl['ok']) && is_file($dl['path'] ?? ''), 'xlsx download prepare');
$read = SimpleXlsx::read((string) $dl['path']);
okEx(isset($read[ProductBulkImport::SHEET_PRODUCTS]), 'xlsx readable');
$plainName = $read[ProductBulkImport::SHEET_PRODUCTS][1][2] ?? '';
okEx($plainName === 'Export Product 1', 'normal product name unchanged in xlsx export');
ProductBulkExport::unlinkDownloadTempFile($dl['path'] ?? null);

$formulaDl = ProductBulkExport::prepareDownloadFile([
    'scope' => ProductBulkExport::SCOPE_SELECTED,
    'product_ids' => [$formulaId],
    'include_images' => false,
]);
okEx(!empty($formulaDl['ok']), 'formula product xlsx prepare');
$formulaRead = SimpleXlsx::read((string) ($formulaDl['path'] ?? ''));
$prodRow = $formulaRead[ProductBulkImport::SHEET_PRODUCTS][1] ?? [];
okEx(str_starts_with((string) ($prodRow[2] ?? ''), "'"), 'xlsx product_name formula injection sanitized');
okEx(str_starts_with((string) ($prodRow[8] ?? ''), "'"), 'xlsx short_description formula injection sanitized');
okEx(str_starts_with((string) ($prodRow[9] ?? ''), "'"), 'xlsx full_description formula injection sanitized');
$attrRow = $formulaRead[ProductBulkImport::SHEET_ATTRIBUTES][1] ?? [];
okEx(str_starts_with((string) ($attrRow[1] ?? ''), "'"), 'xlsx attribute key formula injection sanitized');
okEx(str_starts_with((string) ($attrRow[2] ?? ''), "'"), 'xlsx attribute value formula injection sanitized');
$seoRowF = $formulaRead[ProductBulkImport::SHEET_SEO][1] ?? [];
okEx(str_starts_with((string) ($seoRowF[1] ?? ''), "'"), 'xlsx seo meta_title formula injection sanitized');
okEx(str_starts_with((string) ($seoRowF[2] ?? ''), "'"), 'xlsx seo meta_description formula injection sanitized');
ProductBulkExport::unlinkDownloadTempFile($formulaDl['path'] ?? null);

$dbFormula = $pdo->prepare('SELECT name, short_description, description, meta_title, meta_description FROM products WHERE id = ?');
$dbFormula->execute([$formulaId]);
$dbRow = $dbFormula->fetch(PDO::FETCH_ASSOC) ?: [];
okEx(($dbRow['name'] ?? '') === '=SUM(1+1)', 'export does not modify DB product name');
okEx(($dbRow['short_description'] ?? '') === '-1+2', 'export does not modify DB short description');

$zipSafe = new ReflectionMethod(ProductBulkExport::class, 'safeZipEntryName');
$zipSafe->setAccessible(true);
okEx($zipSafe->invoke(null, '../../../etc/passwd') === 'passwd', 'safeZipEntryName collapses traversal to basename');
okEx($zipSafe->invoke(null, 'valid-image.png') === 'valid-image.png', 'safeZipEntryName keeps safe basename');
okEx($zipSafe->invoke(null, 'bad..segment') === '', 'safeZipEntryName rejects double-dot in entry name');

$pkg = ProductBulkExport::prepareDownloadFile(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => [$ids[0]], 'include_images' => true]);
okEx(!empty($pkg['ok']) && str_ends_with((string) ($pkg['filename'] ?? ''), '.zip'), 'package prepare');
$z = new ZipArchive();
okEx($z->open((string) $pkg['path']) === true, 'package zip opens');
okEx($z->locateName('images.zip') !== false && $z->locateName('export-summary.txt') !== false, 'package contents');
$z->close();
ProductBulkExport::unlinkDownloadTempFile($pkg['path'] ?? null);

$empty = ProductBulkExport::prepareDownloadFile(['scope' => ProductBulkExport::SCOPE_SELECTED, 'product_ids' => []]);
okEx(empty($empty['ok']), 'empty selected export fails');

$badPath = ProductBulkExport::resolveSafeImagePath('../etc/passwd');
okEx($badPath === null, 'reject path traversal image');

$page = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/bulk-export.php');
okEx(str_contains($page, "denyIfCannot('products')"), 'bulk-export permission');
okEx(str_contains($page, 'Csrf::requireValid'), 'bulk-export POST CSRF');
okEx(str_contains($page, 'Csrf::field()'), 'bulk-export form uses Csrf::field');
okEx(!preg_match('/name="csrf"/', $page), 'bulk-export form does not use legacy csrf field name');
okEx(str_contains($page, 'body.success'), 'bulk-export JS reads Ajax success envelope');
$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/product-bulk-export.php');
okEx(str_contains($ajax, 'Csrf::requireValid'), 'bulk-export ajax CSRF');
okEx(str_contains($ajax, "denyIfCannot('products')"), 'bulk-export ajax permission');

$index = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/index.php');
okEx(str_contains($index, 'bulk-export.php'), 'index bulk export link');

@unlink($pngPath);
@rmdir($imgDir);

echo "\nSUMMARY fail={$fail} skipped={$skipped}\n";
exit($fail > 0 ? 1 : 0);
