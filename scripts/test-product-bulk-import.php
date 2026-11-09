<?php

declare(strict_types=1);

/**
 * Bulk product import (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-product-bulk-import.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okB(bool $c, string $m): void
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
$tok = strtolower(bin2hex(random_bytes(3)));

$cat = $pdo->query("SELECT id, name, slug FROM categories WHERE status = 'published' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($cat === false) {
    $pdo->exec("INSERT INTO categories (name, slug, status, sort_order) VALUES ('Bulk Cat', 'bulk-cat-{$tok}', 'published', 1)");
    $catId = (int) $pdo->lastInsertId();
    $catName = 'Bulk Cat';
} else {
    $catId = (int) $cat['id'];
    $catName = (string) $cat['name'];
}
$ptype = ProductTypeRepository::defaultCode();

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
) ?: '';
$imgDir = sys_get_temp_dir() . '/bulk-imp-img-' . $tok;
@mkdir($imgDir, 0755, true);
$sharedImg = $imgDir . '/shared.png';
file_put_contents($sharedImg, $png . $tok);

$productRows = [ProductBulkImport::productsHeaders()];
$variantRows = [ProductBulkImport::variantsHeaders()];
$attrRows = [ProductBulkImport::attributesHeaders()];
$imageRows = [ProductBulkImport::imagesHeaders()];
$seoRows = [ProductBulkImport::seoHeaders()];

for ($i = 1; $i <= 50; $i++) {
    $code = 'BI-' . $tok . '-' . $i;
    $sku = 'BI-SKU-' . $tok . '-' . sprintf('%03d', $i);
    $productRows[] = [
        $code, 'Bulk Product ' . $i, 'bulk-product-' . $tok . '-' . $i, $sku,
        $catName, $ptype,
        'Short ' . $i, 'Full description ' . $i,
    ];
    $vCount = ($i % 2 === 0) ? 5 : 4;
    for ($v = 1; $v <= $vCount; $v++) {
        $variantRows[] = [
            $code, $v . ' unit', $sku . '-V' . $v, (string) (20 + $v), '', (string) (50 + $v),
            '5', '1', '0', (string) $v,
        ];
    }
    for ($a = 1; $a <= 7 + ($i % 2); $a++) {
        $attrRows[] = [$code, 'Attr ' . $a, 'Value ' . $a, (string) $a];
    }
    $imageRows[] = [$code, 'shared.png', 'yes', '1', 'Alt ' . $i];
    $seoRows[] = [$code, 'Meta ' . $i, 'Description meta ' . $i, 'yes'];
}

$workDir = sys_get_temp_dir() . '/bulk-imp-wb-' . $tok;
@mkdir($workDir, 0755, true);
$xlsx = $workDir . '/bulk.xlsx';
okB(SimpleXlsx::write([
    ProductBulkImport::SHEET_PRODUCTS => $productRows,
    ProductBulkImport::SHEET_VARIANTS => $variantRows,
    ProductBulkImport::SHEET_ATTRIBUTES => $attrRows,
    ProductBulkImport::SHEET_IMAGES => $imageRows,
    ProductBulkImport::SHEET_SEO => $seoRows,
], $xlsx), 'build 50-product workbook');

$zipPath = $workDir . '/images.zip';
$zip = new ZipArchive();
okB($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'open images zip');
$zip->addFile($sharedImg, 'shared.png');
$zip->close();

$parsed = ProductBulkImport::parseWorkbook($xlsx);
$ex = ProductBulkImport::extractImageZip($zipPath);
$validation = ProductBulkImport::validate($parsed, $ex['index'] ?? null);
okB($validation['ready_count'] === 50, '50 products ready');
okB($validation['ok'], 'validation clean for good workbook');
$summary = is_array($validation['summary'] ?? null) ? $validation['summary'] : [];
okB(($summary['total_products'] ?? 0) === 50, 'summary total products');
okB(($summary['ready_count'] ?? 0) === 50, 'summary ready count');
okB(($summary['products_with_errors'] ?? -1) === 0, 'summary products with errors zero when clean');
okB(isset($validation['products'][0]['import_ready']) && $validation['products'][0]['import_ready'] === true, 'product import_ready flag when valid');
okB(isset($validation['products'][0]['variants'][0]['sheet_row']), 'variant sheet_row in preview');

$bundles = ProductBulkImport::bundleMapFromParsed($parsed, []);
$import1 = ProductBulkImport::importProducts($bundles, $ex['index'] ?? null, 1);
okB($import1['imported'] === 50 && $import1['failed'] === 0, 'import 50 products');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE slug LIKE ?');
$stmt->execute(['bulk-product-' . $tok . '-%']);
okB((int) $stmt->fetchColumn() === 50, '50 product rows in DB');
$flags = $pdo->query(
    "SELECT COUNT(*) FROM products WHERE slug LIKE 'bulk-product-{$tok}-%' AND status = 'draft'"
    . ' AND is_featured = 0 AND is_bestseller = 0 AND is_new_arrival = 0'
)->fetchColumn();
okB((int) $flags === 50, 'all draft with promo flags off');

$varCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE p.slug LIKE 'bulk-product-{$tok}-%'"
)->fetchColumn();
okB($varCount === 50 * 4 + 25, 'variant row count (25×4 + 25×5)'); // i even:5 odd:4 -> 25*5+25*4=225

$attrStmt = $pdo->query(
    "SELECT COUNT(*) FROM product_attributes pa JOIN products p ON p.id = pa.product_id WHERE p.slug LIKE 'bulk-product-{$tok}-%'"
);
okB((int) $attrStmt->fetchColumn() >= 50 * 7, 'attribute rows saved');

$import2 = ProductBulkImport::importProducts($bundles, $ex['index'] ?? null, 1);
okB($import2['failed'] >= 50, 'repeat import fails on existing SKUs');

$badXlsx = $workDir . '/bad.xlsx';
$badProducts = $productRows;
$badProducts[] = [
    'BI-' . $tok . '-dup', 'Dup name', 'bulk-dup-' . $tok, 'BI-SKU-' . $tok . '-001',
    $catName, $ptype, 's', 'd',
];
okB(SimpleXlsx::write([
    ProductBulkImport::SHEET_PRODUCTS => $badProducts,
    ProductBulkImport::SHEET_VARIANTS => $variantRows,
    ProductBulkImport::SHEET_ATTRIBUTES => $attrRows,
    ProductBulkImport::SHEET_IMAGES => $imageRows,
    ProductBulkImport::SHEET_SEO => $seoRows,
], $badXlsx), 'workbook with duplicate SKU');
$badParsed = ProductBulkImport::parseWorkbook($badXlsx);
$badVal = ProductBulkImport::validate($badParsed, $ex['index'] ?? null);
okB(!$badVal['ok'], 'duplicate SKU detected');
$badBundles = ProductBulkImport::bundleMapFromParsed($badParsed, []);
$partial = ProductBulkImport::filterImportableBundles($badVal['errors'], $badBundles);
$partialImport = ProductBulkImport::importProducts($partial, $ex['index'] ?? null, 1);
okB($partialImport['imported'] === 0, 'partial set empty when duplicate SKU blocks new code');

$noZipVal = ProductBulkImport::validate($parsed, null);
okB(!$noZipVal['ok'], 'images zip required when sheet lists files');
$missingZipReason = false;
foreach (is_array($noZipVal['errors'] ?? null) ? $noZipVal['errors'] : [] as $err) {
    if (is_array($err) && ($err['reason'] ?? '') === ProductBulkImport::MSG_MISSING_IMAGES_ZIP) {
        $missingZipReason = true;
        break;
    }
}
okB($missingZipReason, 'missing images zip validation returns helpful message');

$badZip = $workDir . '/bad.zip';
$bz = new ZipArchive();
$bz->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$bz->addFromString('not-image.txt', 'not an image');
$bz->close();
$badEx = ProductBulkImport::extractImageZip($badZip);
okB(empty($badEx['ok']), 'zip rejects non-image content');

if (MediaLibrary::isEnabled()) {
    $rollCode = 'BI-ROLL-' . $tok;
    $rollSku = 'BI-ROLL-SKU-' . $tok;
    $rollProducts = [
        ProductBulkImport::productsHeaders(),
        [$rollCode, 'Roll Product', 'roll-' . $tok, $rollSku, $catName, $ptype, 's', 'd'],
    ];
    $rollVariants = [
        ProductBulkImport::variantsHeaders(),
        [$rollCode, 'Default', $rollSku . '-V1', '10', '', '5', '5', '1', '0', '1'],
    ];
    $good2 = $imgDir . '/good2.png';
    $second = $imgDir . '/second.png';
    file_put_contents($good2, $png . 'good2');
    file_put_contents($second, $png . 'second');
    $rollImages = [
        ProductBulkImport::imagesHeaders(),
        [$rollCode, 'good2.png', 'yes', '1', ''],
        [$rollCode, 'second.png', 'no', '2', ''],
    ];
    $rollXlsx = $workDir . '/roll.xlsx';
    SimpleXlsx::write([
        ProductBulkImport::SHEET_PRODUCTS => $rollProducts,
        ProductBulkImport::SHEET_VARIANTS => $rollVariants,
        ProductBulkImport::SHEET_ATTRIBUTES => [ProductBulkImport::attributesHeaders()],
        ProductBulkImport::SHEET_IMAGES => $rollImages,
        ProductBulkImport::SHEET_SEO => [ProductBulkImport::seoHeaders()],
    ], $rollXlsx);
    $rollZip = $workDir . '/roll.zip';
    $rz = new ZipArchive();
    $rz->open($rollZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $rz->addFile($good2, 'good2.png');
    $rz->addFile($second, 'second.png');
    $rz->close();
    $rollEx = ProductBulkImport::extractImageZip($rollZip);
    $rollParsed = ProductBulkImport::parseWorkbook($rollXlsx);
    $rollBundles = ProductBulkImport::bundleMapFromParsed($rollParsed, []);
    $index = $rollEx['index'] ?? [];
    unset($index['second.png']);
    $mediaBefore = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
    $failImport = ProductBulkImport::importProducts($rollBundles, $index, 1);
    okB(($failImport['failed'] ?? 0) >= 1, 'multi-image import fails when second file missing at import');
    $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE slug = ?');
    $st->execute(['roll-' . $tok]);
    okB((int) $st->fetchColumn() === 0, 'no partial product after image failure');
    $st2 = $pdo->prepare('SELECT COUNT(*) FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.slug = ?');
    $st2->execute(['roll-' . $tok]);
    okB((int) $st2->fetchColumn() === 0, 'no orphan product_images for failed import');
    $mediaAfter = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
    okB($mediaAfter >= $mediaBefore, 'media library assets not removed on rollback');
    $orphan = $pdo->query(
        "SELECT id FROM media_assets WHERE original_filename = 'good2.png' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($orphan)) {
        $mid = (int) $orphan['id'];
        okB(MediaLibrary::productUsageCount($mid) === 0, 'orphan media not counted as product-used');
        okB(MediaLibrary::findActive($mid) !== null, 'orphan media remains visible as active in library');
    }
}

$optCode = 'BI-NOIMG-' . $tok;
$optProducts = [
    ProductBulkImport::productsHeaders(),
    [$optCode, 'No Image', 'no-img-' . $tok, 'BI-NOIMG-' . $tok, $catName, $ptype, 's', 'd'],
];
$optVariants = [
    ProductBulkImport::variantsHeaders(),
    [$optCode, 'Only', 'BI-NOIMG-' . $tok . '-V', '5', '', '1', '5', '1', '0', '1'],
];
$optXlsx = $workDir . '/noimg.xlsx';
SimpleXlsx::write([
    ProductBulkImport::SHEET_PRODUCTS => $optProducts,
    ProductBulkImport::SHEET_VARIANTS => $optVariants,
    ProductBulkImport::SHEET_ATTRIBUTES => [ProductBulkImport::attributesHeaders()],
    ProductBulkImport::SHEET_IMAGES => [ProductBulkImport::imagesHeaders()],
    ProductBulkImport::SHEET_SEO => [ProductBulkImport::seoHeaders()],
], $optXlsx);
$optParsed = ProductBulkImport::parseWorkbook($optXlsx);
$optVal = ProductBulkImport::validate($optParsed, null);
okB($optVal['ready_count'] === 1, 'product without images validates without zip');
$optBundles = ProductBulkImport::filterImportableBundles($optVal['errors'], ProductBulkImport::bundleMapFromParsed($optParsed, []));
$optImp = ProductBulkImport::importProducts($optBundles, null, 1);
okB(($optImp['imported'] ?? 0) === 1, 'product without images imports');
$st3 = $pdo->prepare('SELECT status, is_featured, is_bestseller, is_new_arrival FROM products WHERE slug = ?');
$st3->execute(['no-img-' . $tok]);
$optRow = $st3->fetch(PDO::FETCH_ASSOC);
okB(
    is_array($optRow)
    && ($optRow['status'] ?? '') === 'draft'
    && (int) ($optRow['is_featured'] ?? 1) === 0
    && (int) ($optRow['is_bestseller'] ?? 1) === 0
    && (int) ($optRow['is_new_arrival'] ?? 1) === 0,
    'no-image import draft and promo off'
);

$tplDir = ProductBulkImport::jobDir('template');
@mkdir($tplDir, 0755, true);
$blankTpl = $tplDir . '/test-blank-' . $tok . '.xlsx';
$tplGen = ProductBulkImport::writeTemplate($blankTpl);
okB(!empty($tplGen['ok']) && is_file($blankTpl), 'writeTemplate blank workbook');
$tplSheets = SimpleXlsx::read($blankTpl);
foreach (
    [
        ProductBulkImport::SHEET_INSTRUCTIONS,
        ProductBulkImport::SHEET_PRODUCTS,
        ProductBulkImport::SHEET_VARIANTS,
        ProductBulkImport::SHEET_ATTRIBUTES,
        ProductBulkImport::SHEET_IMAGES,
        ProductBulkImport::SHEET_SEO,
        ProductBulkImport::SHEET_REFERENCE,
    ] as $requiredSheet
) {
    $found = false;
    foreach (array_keys($tplSheets) as $k) {
        if (strcasecmp((string) $k, $requiredSheet) === 0) {
            $found = true;
            break;
        }
    }
    okB($found, 'blank template has sheet: ' . $requiredSheet);
}
$instr = $tplSheets[ProductBulkImport::SHEET_INSTRUCTIONS]
    ?? $tplSheets[array_key_first($tplSheets)] ?? [];
$instrFlat = json_encode($instr);
okB(is_string($instrFlat) && str_contains($instrFlat, 'product_code is used only in Excel'), 'Instructions explain product_code not stored');

$samplePkgPath = $tplDir . '/test-sample-package-' . $tok . '.zip';
$pkgGen = ProductBulkImport::writeSamplePackage($samplePkgPath);
okB(!empty($pkgGen['ok']) && is_file($samplePkgPath), 'writeSamplePackage');
okB(filesize($samplePkgPath) > 500, 'sample package non-empty zip');

$sampleExtract = $workDir . '/sample-pkg';
@mkdir($sampleExtract, 0755, true);
$outer = new ZipArchive();
okB($outer->open($samplePkgPath) === true, 'open sample package zip');
okB($outer->locateName('sample_products.xlsx') !== false, 'package contains sample_products.xlsx');
okB($outer->locateName('images.zip') !== false, 'package contains images.zip');
okB($outer->locateName('README.txt') !== false, 'package contains README.txt');
$outer->extractTo($sampleExtract, ['sample_products.xlsx', 'images.zip', 'README.txt']);
$outer->close();

$sampleXlsx = $sampleExtract . '/sample_products.xlsx';
$sampleSheets = SimpleXlsx::read($sampleXlsx);
okB($sampleSheets !== [], 'read sample_products.xlsx');
foreach (ProductBulkImport::productsHeaders() as $h) {
    $hdr = $sampleSheets[ProductBulkImport::SHEET_PRODUCTS][0] ?? [];
    okB(in_array($h, $hdr, true), 'sample Products header: ' . $h);
}

$sampleParsed = ProductBulkImport::parseWorkbook($sampleXlsx);
$codes = array_column($sampleParsed['products'], 'product_code');
okB(in_array(ProductBulkImport::SAMPLE_CODE_GARLIC, $codes, true), 'sample has DEMO-GARLIC');
okB(in_array(ProductBulkImport::SAMPLE_CODE_ONION, $codes, true), 'sample has DEMO-ONION');

$gVariants = array_filter($sampleParsed['variants'], static fn ($r) => ($r['product_code'] ?? '') === ProductBulkImport::SAMPLE_CODE_GARLIC);
$gAttrs = array_filter($sampleParsed['attributes'], static fn ($r) => ($r['product_code'] ?? '') === ProductBulkImport::SAMPLE_CODE_GARLIC);
okB(count($gVariants) === 3, 'DEMO-GARLIC has 3 variants');
okB(count($gAttrs) >= 4, 'DEMO-GARLIC has 4+ attributes');

$imageNames = array_map(static fn ($r) => strtolower((string) ($r['filename'] ?? '')), $sampleParsed['images']);
$expectedImages = array_map('strtolower', ProductBulkImport::samplePackageImageBasenames());
foreach ($expectedImages as $bn) {
    okB(in_array($bn, $imageNames, true), 'Images sheet lists: ' . $bn);
}

$innerZipPath = $sampleExtract . '/images.zip';
$inner = new ZipArchive();
okB($inner->open($innerZipPath) === true, 'open sample images.zip');
$innerExtract = $workDir . '/sample-images';
@mkdir($innerExtract, 0755, true);
$inner->extractTo($innerExtract);
$inner->close();
foreach (ProductBulkImport::samplePackageImageBasenames() as $bn) {
    $p = $innerExtract . '/' . $bn;
    okB(is_file($p), 'sample image file in images.zip: ' . $bn);
    $dim = @getimagesize($p);
    okB(is_array($dim) && ($dim[0] ?? 0) > 0, 'sample image valid raster: ' . $bn);
}

$sampleImgEx = ProductBulkImport::extractImageZip($innerZipPath);
$sampleVal = ProductBulkImport::validate($sampleParsed, $sampleImgEx['index'] ?? null);
okB(!empty($sampleVal['ok']), 'sample package workbook validates with images zip');
okB(($sampleVal['ready_count'] ?? 0) === 2, 'sample workbook validates 2 demo products');
$sampleImgPath = '';
foreach (is_array($sampleImgEx['index'] ?? null) ? $sampleImgEx['index'] : [] as $imgPath) {
    if (is_string($imgPath) && $imgPath !== '') {
        $sampleImgPath = $imgPath;
        break;
    }
}
if ($sampleImgPath !== '') {
    okB(ProductBulkImport::isTrustedJobExtractImagePath($sampleImgPath), 'job extract image path is trusted for web import');
    $trustedUp = ProductBulkImport::trustedImageUploadFromJobExtract($sampleImgPath);
    okB(!empty($trustedUp['trusted_internal']), 'trusted_internal set on job extract upload');
}
$sampleBundles = ProductBulkImport::bundleMapFromParsed($sampleParsed, []);
$sampleImportSet = ProductBulkImport::filterImportableBundles($sampleVal['errors'], $sampleBundles);
$sampleImport = ProductBulkImport::importProducts($sampleImportSet, $sampleImgEx['index'] ?? null, 1);
okB(($sampleImport['imported'] ?? 0) === 2 && ($sampleImport['failed'] ?? 0) === 0, 'sample package: two validation-ready products import successfully');
$garlicImgs = array_filter($sampleParsed['images'], static fn ($r) => ($r['product_code'] ?? '') === ProductBulkImport::SAMPLE_CODE_GARLIC);
$onionNames = array_map(static fn ($r) => strtolower((string) ($r['filename'] ?? '')), array_filter($sampleParsed['images'], static fn ($r) => ($r['product_code'] ?? '') === ProductBulkImport::SAMPLE_CODE_ONION));
foreach ($garlicImgs as $gr) {
    $fn = strtolower((string) ($gr['filename'] ?? ''));
    okB(!in_array($fn, $onionNames, true), 'sample garlic images are not onion filenames: ' . $fn);
}
$sampleNoZip = ProductBulkImport::validate($sampleParsed, null);
okB(empty($sampleNoZip['ok']), 'sample package without images zip fails validation');
$sampleNoZipHelpful = false;
foreach (is_array($sampleNoZip['errors'] ?? null) ? $sampleNoZip['errors'] : [] as $err) {
    if (is_array($err) && ($err['reason'] ?? '') === ProductBulkImport::MSG_MISSING_IMAGES_ZIP) {
        $sampleNoZipHelpful = true;
        break;
    }
}
okB($sampleNoZipHelpful, 'sample package without zip surfaces helpful missing-zip message');

$page = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/bulk-import.php');
okB(str_contains($page, 'download_sample_package=1'), 'static: sample package download on admin page');
okB(str_contains($page, 'sample_products.xlsx'), 'static: sample package lists sample_products.xlsx');
okB(str_contains($page, 'images.zip'), 'static: sample package explains images.zip');
okB(str_contains($page, 'README.txt'), 'static: sample package lists README.txt');
okB(str_contains($page, 'images.zip</code> included inside the downloaded sample package'), 'static: images zip field sample hint');
okB(str_contains($page, 'missingImagesZipMessage'), 'static: JS promotes missing images zip message');
okB(str_contains($page, 'at-bulk-import-summary'), 'static: validation summary UI');
okB(str_contains($page, 'renderValidationResults'), 'static: product-wise validation renderer');
okB(str_contains($page, 'import_ready'), 'static: uses import_ready from validation payload');
okB(str_contains($page, 'at-bulk-import-action'), 'static: dedicated import action panel');
okB(str_contains($page, 'renderImportOutcome'), 'static: import outcome panel with failure details');
okB(str_contains($page, 'download_template=1'), 'static: blank template download on admin page');
okB(str_contains($page, 'denyIfCannot(\'products\')'), 'static: products permission on bulk-import page');
okB(str_contains($page, 'Csrf::field()'), 'static: bulk-import form uses Csrf::field');
okB(!preg_match('/name="csrf"/', $page), 'static: bulk-import form does not use legacy csrf field name');
okB(str_contains($page, 'body.success'), 'static: bulk-import JS reads Ajax success envelope');
okB(str_contains($page, 'prepareTemplateDownloadFile'), 'static: template download uses temp file helper');
okB(str_contains($page, 'AdminFileDownload::streamAttachment'), 'static: secure download streaming');

$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/product-bulk-import.php');
okB(str_contains($ajax, 'Csrf::requireValid'), 'static: CSRF on bulk import AJAX');
okB(str_contains($ajax, "denyIfCannot('products')"), 'static: products permission');
okB(str_contains($ajax, 'parseWorkbook($workbookPath)'), 'static: import revalidates workbook server-side');
okB(str_contains($ajax, 'skipped_validation'), 'static: import response includes skipped_validation count');

if (MediaLibrary::isEnabled()) {
    $dedupXlsx = $workDir . '/dedup-' . $tok . '.xlsx';
    $c1 = 'DEDUP-A-' . $tok;
    $c2 = 'DEDUP-B-' . $tok;
    $dedupProd = [
        ProductBulkImport::productsHeaders(),
        [$c1, 'Dedup A', 'dedup-a-' . $tok, 'DEDUP-A-SKU-' . $tok, $catName, $ptype, 's', 'd'],
        [$c2, 'Dedup B', 'dedup-b-' . $tok, 'DEDUP-B-SKU-' . $tok, $catName, $ptype, 's', 'd'],
    ];
    $dedupVar = [
        ProductBulkImport::variantsHeaders(),
        [$c1, 'Only', 'DEDUP-A-V-' . $tok, '10', '', '5', '5', '1', '0', '1'],
        [$c2, 'Only', 'DEDUP-B-V-' . $tok, '10', '', '5', '5', '1', '0', '1'],
    ];
    $dedupImg = [
        ProductBulkImport::imagesHeaders(),
        [$c1, 'shared-dedup.png', 'yes', '1', ''],
        [$c2, 'shared-dedup.png', 'yes', '1', ''],
    ];
    SimpleXlsx::write([
        ProductBulkImport::SHEET_PRODUCTS => $dedupProd,
        ProductBulkImport::SHEET_VARIANTS => $dedupVar,
        ProductBulkImport::SHEET_ATTRIBUTES => [ProductBulkImport::attributesHeaders()],
        ProductBulkImport::SHEET_IMAGES => $dedupImg,
        ProductBulkImport::SHEET_SEO => [ProductBulkImport::seoHeaders()],
    ], $dedupXlsx);
    $dedupPng = $imgDir . '/shared-dedup.png';
    file_put_contents($dedupPng, $png . 'dedup');
    $dedupZip = $workDir . '/dedup.zip';
    $dz = new ZipArchive();
    $dz->open($dedupZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $dz->addFile($dedupPng, 'shared-dedup.png');
    $dz->close();
    $dedupEx = ProductBulkImport::extractImageZip($dedupZip);
    $dedupParsed = ProductBulkImport::parseWorkbook($dedupXlsx);
    $dedupBundles = ProductBulkImport::bundleMapFromParsed($dedupParsed, []);
    $mediaBeforeDedup = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
    $dedupImport = ProductBulkImport::importProducts($dedupBundles, $dedupEx['index'] ?? null, 1);
    okB(($dedupImport['imported'] ?? 0) === 2 && ($dedupImport['failed'] ?? 0) === 0, 'shared image dedup: two products import');
    $mediaAfterDedup = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
    okB($mediaAfterDedup - $mediaBeforeDedup <= 1, 'shared image dedup: at most one new media asset for identical file');
}

// --- Product-wise validation summary / mixed partial import ---
$existSku = 'BI-SKU-' . $tok . '-001';
$dupProdVal = ProductBulkImport::validate($parsed, $ex['index'] ?? null);
$hasProdSkuErr = false;
foreach (is_array($dupProdVal['errors'] ?? null) ? $dupProdVal['errors'] : [] as $err) {
    if (is_array($err) && ($err['reason'] ?? '') === 'Product SKU already exists in catalogue.'
        && ($err['product_code'] ?? '') === 'BI-' . $tok . '-1') {
        $hasProdSkuErr = true;
        break;
    }
}
okB($hasProdSkuErr, 'product-level duplicate SKU error after catalogue import');

$slugTok = 'mix-' . $tok;
$pdo->prepare('INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)')
    ->execute([$catId, 'Existing Slug Holder', 'slug-taken-' . $slugTok, 'SLUG-HOLD-' . $tok, 'draft']);
$mixRows = [ProductBulkImport::productsHeaders()];
$mixRows[] = ['GOOD-' . $tok, 'Good Product', 'good-' . $slugTok, 'GOOD-SKU-' . $tok, $catName, $ptype, 's', 'd'];
$mixRows[] = ['BAD-SLUG-' . $tok, 'Bad Slug', 'slug-taken-' . $slugTok, 'BAD-SLUG-SKU-' . $tok, $catName, $ptype, 's', 'd'];
$mixRows[] = ['BAD-VAR-' . $tok, 'Bad Variant', 'bad-var-' . $slugTok, 'BAD-VAR-P-SKU-' . $tok, $catName, $ptype, 's', 'd'];
$mixVariants = [ProductBulkImport::variantsHeaders()];
$mixVariants[] = ['GOOD-' . $tok, '500 g', 'GOOD-SKU-' . $tok . '-V1', '40', '', '10', '5', '1', '0', '1'];
$mixVariants[] = ['BAD-VAR-' . $tok, '200 g', $existSku, '40', '', '10', '5', '1', '0', '1'];
$mixAttrs = [ProductBulkImport::attributesHeaders()];
$mixImages = [ProductBulkImport::imagesHeaders()];
$mixSeo = [ProductBulkImport::seoHeaders()];
$mixXlsx = $workDir . '/mix-' . $tok . '.xlsx';
okB(SimpleXlsx::write([
    ProductBulkImport::SHEET_PRODUCTS => $mixRows,
    ProductBulkImport::SHEET_VARIANTS => $mixVariants,
    ProductBulkImport::SHEET_ATTRIBUTES => $mixAttrs,
    ProductBulkImport::SHEET_IMAGES => $mixImages,
    ProductBulkImport::SHEET_SEO => $mixSeo,
], $mixXlsx), 'build mixed validation workbook');
$mixParsed = ProductBulkImport::parseWorkbook($mixXlsx);
$mixVal = ProductBulkImport::validate($mixParsed, null);
$mixSum = is_array($mixVal['summary'] ?? null) ? $mixVal['summary'] : [];
okB(($mixSum['total_products'] ?? 0) === 3, 'mixed workbook: total products 3');
okB(($mixSum['ready_count'] ?? 0) === 1, 'mixed workbook: one ready product');
okB(($mixSum['products_with_errors'] ?? 0) === 2, 'mixed workbook: two products with errors');
okB(($mixSum['variant_error_count'] ?? 0) >= 1, 'mixed workbook: variant error count');

$hasSlugErr = false;
$hasVarSkuErr = false;
foreach (is_array($mixVal['errors'] ?? null) ? $mixVal['errors'] : [] as $err) {
    if (!is_array($err)) {
        continue;
    }
    if (($err['reason'] ?? '') === 'Slug already used by an existing product.') {
        $hasSlugErr = true;
    }
    if (($err['reason'] ?? '') === 'Variant SKU already exists in catalogue.' && ($err['product_code'] ?? '') === 'BAD-VAR-' . $tok) {
        $hasVarSkuErr = true;
    }
}
okB($hasSlugErr, 'duplicate slug error detected');
okB($hasVarSkuErr, 'variant-level duplicate SKU error detected');

$mixBundles = ProductBulkImport::bundleMapFromParsed($mixParsed, []);
$mixImportable = ProductBulkImport::filterImportableBundles($mixVal['errors'], $mixBundles);
okB(count($mixImportable) === 1 && isset($mixImportable['GOOD-' . $tok]), 'partial import set has only valid product');
$mixImport = ProductBulkImport::importProducts($mixImportable, null, 1);
okB(($mixImport['imported'] ?? 0) === 1, 'partial import imports only valid products');

$imgBadRows = [ProductBulkImport::imagesHeaders()];
$imgBadRows[] = ['IMG-' . $tok, 'missing-in-zip.png', 'yes', '1', 'Alt'];
$imgProd = [ProductBulkImport::productsHeaders()];
$imgProd[] = ['IMG-' . $tok, 'Image Test', 'img-test-' . $tok, 'IMG-SKU-' . $tok, $catName, $ptype, 's', 'd'];
$imgVar = [ProductBulkImport::variantsHeaders()];
$imgVar[] = ['IMG-' . $tok, 'Default', 'IMG-SKU-' . $tok . '-V1', '10', '', '5', '5', '1', '0', '1'];
$imgXlsx = $workDir . '/img-' . $tok . '.xlsx';
okB(SimpleXlsx::write([
    ProductBulkImport::SHEET_PRODUCTS => $imgProd,
    ProductBulkImport::SHEET_VARIANTS => $imgVar,
    ProductBulkImport::SHEET_ATTRIBUTES => $mixAttrs,
    ProductBulkImport::SHEET_IMAGES => $imgBadRows,
    ProductBulkImport::SHEET_SEO => $mixSeo,
], $imgXlsx), 'build image filename error workbook');
$imgParsed = ProductBulkImport::parseWorkbook($imgXlsx);
$imgVal = ProductBulkImport::validate($imgParsed, $ex['index'] ?? null);
$imgFileErr = false;
foreach (is_array($imgVal['errors'] ?? null) ? $imgVal['errors'] : [] as $err) {
    if (is_array($err) && str_contains((string) ($err['reason'] ?? ''), 'Image file not found in uploaded ZIP')) {
        $imgFileErr = true;
        break;
    }
}
okB($imgFileErr, 'image filename not in zip error');

$multiErrXlsx = $workDir . '/multi-' . $tok . '.xlsx';
$multiProd = [ProductBulkImport::productsHeaders()];
$multiProd[] = ['MULTI-' . $tok, '', 'multi-' . $tok, 'MULTI-SKU-' . $tok, $catName, $ptype, 's', 'd'];
$multiVar = [ProductBulkImport::variantsHeaders()];
$multiVar[] = ['MULTI-' . $tok, '', 'MULTI-V-SKU-' . $tok, '10', '', '5', '5', '1', '0', '1'];
okB(SimpleXlsx::write([
    ProductBulkImport::SHEET_PRODUCTS => $multiProd,
    ProductBulkImport::SHEET_VARIANTS => $multiVar,
    ProductBulkImport::SHEET_ATTRIBUTES => $mixAttrs,
    ProductBulkImport::SHEET_IMAGES => $mixImages,
    ProductBulkImport::SHEET_SEO => $mixSeo,
], $multiErrXlsx), 'build multi-error single product workbook');
$multiVal = ProductBulkImport::validate(ProductBulkImport::parseWorkbook($multiErrXlsx), null);
okB(count($multiVal['errors'] ?? []) >= 2, 'multiple errors on one product');
$multiPreview = $multiVal['products'][0] ?? [];
okB(($multiPreview['error_count'] ?? 0) >= 2, 'product error_count reflects multiple issues');

$reval = ProductBulkImport::validate($parsed, $ex['index'] ?? null);
okB(($reval['ready_count'] ?? 50) === 0, 'revalidation prevents import after SKUs exist in catalogue');

$roundTrip = $workDir . '/roundtrip-' . $tok . '.xlsx';
$roundSheets = [
    'Check' => [
        ['Product name', 'Note'],
        ['Cherry Tomato', 'Fresh veg — no formula prefix'],
        ['Potato', 'Storage tips'],
    ],
];
okB(SimpleXlsx::write($roundSheets, $roundTrip), 'SimpleXlsx write round-trip workbook');
$roundRead = SimpleXlsx::read($roundTrip);
okB(($roundRead['Check'][1][0] ?? '') === 'Cherry Tomato', 'SimpleXlsx read preserves normal product name');
okB(($roundRead['Check'][1][1] ?? '') === 'Fresh veg — no formula prefix', 'SimpleXlsx read preserves normal description');
okB(SimpleXlsx::sanitizeCellString('Cherry Tomato') === 'Cherry Tomato', 'sanitize does not alter normal import labels');
okB(SimpleXlsx::sanitizeCellString('=IMPORT') === "'=IMPORT", 'sanitize still prefixes formula-like template text on write');

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
