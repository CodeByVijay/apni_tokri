<?php

declare(strict_types=1);

/**
 * Admin product multi-image upload (product_images table).
 * CLI: php scripts/run-in-disposable-db.php scripts/test-admin-product-images.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/catalogue/helpers.php';

$fail = 0;
$ok = static function (bool $c, string $m) use (&$fail): void {
    echo ($c ? 'PASS: ' : 'FAIL: ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

$edit = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/edit.php');
$panel = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/product-images-panel.php');
$gallery = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/partials/admin/product-images-gallery-item.php');
$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/product-images.php');
$svc = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/catalogue/product_image_admin.php');
$upload = (string) file_get_contents(APNI_TOKRI_ROOT . '/includes/upload.php');
$js = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');

$ok(str_contains($edit, 'product-images-panel.php'), 'static: edit page loads images partial');
$ok(str_contains($panel, 'data-at-product-images'), 'static: product images panel markup');
$ok(str_contains($panel, 'action" value="upload_image"'), 'static: legacy single upload (noscript) preserved');
$ok(str_contains($gallery, 'delete_image'), 'static: delete image POST preserved');
$ok(str_contains($edit, 'hasPrimaryImage'), 'static: primary only when none exists (single upload)');
$ok(str_contains($ajax, 'Csrf::requireValid'), 'static: ajax CSRF');
$ok(str_contains($ajax, 'storeProductImage') === false && str_contains($svc, 'storeProductImage'), 'static: product MIME via service');
$ok(str_contains($upload, 'storeProductImage'), 'static: product upload helper');
$ok(str_contains($js, 'initAdminProductImages'), 'static: admin JS multi-upload');
$ok(str_contains((string) file_get_contents(APNI_TOKRI_ROOT . '/includes/catalogue/product_repository.php'), 'MAX_IMAGES_PER_PRODUCT'), 'static: 10 image cap constant');

if (!Install::isSchemaReady()) {
    fwrite(STDERR, "SKIP DB: schema not ready.\n");
    exit($fail > 0 ? 1 : 0);
}

Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(3)));
$pdo->exec("INSERT INTO categories (name, slug, status) VALUES ('PI$tok', 'pi-$tok', 'published')");
$cat = (int) $pdo->lastInsertId();
$prod = ProductRepository::saveGeneral(null, [
    'name' => "PI $tok",
    'slug' => "pi-prod-$tok",
    'status' => 'published',
    'category_id' => (string) $cat,
]);
$pid = (int) $prod['id'];

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$addDiskImage = static function (int $productId, string $suffix, bool $primary) use ($png, $pid): void {
    $subdir = 'products/' . $productId;
    $destDir = APNI_TOKRI_ROOT . '/uploads/' . $subdir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    $name = bin2hex(random_bytes(8)) . '-' . $suffix . '.png';
    file_put_contents($destDir . '/' . $name, $png);
    ProductRepository::addImage($productId, 'uploads/' . $subdir . '/' . $name, $primary);
};

$addDiskImage($pid, 'a', true);
$addDiskImage($pid, 'b', false);
$ok(ProductRepository::imageCount($pid) === 2, 'db: two images stored');
$ok(ProductRepository::hasPrimaryImage($pid), 'db: primary image exists');

$imgs = ProductRepository::images($pid);
ProductImageAdminService::setPrimary($pid, (int) $imgs[1]['id']);
$imgs = ProductRepository::images($pid);
$ok(!empty($imgs[1]['is_primary']), 'db: primary can be changed');
$ids = array_column($imgs, 'id');
$ok(ProductImageAdminService::reorder($pid, array_reverse($ids)), 'db: reorder persists');

$existing = ProductRepository::imageCount($pid);
$stubs = [];
for ($i = 0; $i < 9; $i++) {
    $stubs[] = ['name' => "s$i.png", 'error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'size' => 100];
}
$reject = ProductImageAdminService::uploadBatch($pid, $stubs);
$ok(ProductRepository::imageCount($pid) === $existing, 'db: over-limit batch rejected without adding');
$ok(
    empty($reject['results'][0]['ok']) && str_contains((string) ($reject['results'][0]['message'] ?? ''), 'Too many'),
    'db: over-limit message'
);

$pub = ProductRepository::toPublicArray(ProductRepository::find($pid) ?? []);
$ok(count($pub['images'] ?? []) === $existing, 'db: storefront gallery image count unchanged');

$pdo->exec('DELETE FROM product_images WHERE product_id = ' . (int) $pid);
$pdo->exec('DELETE FROM products WHERE id = ' . (int) $pid);

exit($fail > 0 ? 1 : 0);
