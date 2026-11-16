<?php

declare(strict_types=1);

/**
 * Media library + product image integration (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okM(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

function ml_test_uploads_has_sha_file(string $sha256): bool
{
    $base = APNI_TOKRI_ROOT . '/uploads/media';
    if (!is_dir($base)) {
        return false;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && hash_file('sha256', $file->getPathname()) === $sha256) {
            return true;
        }
    }

    return false;
}

if (!MediaLibrary::isEnabled()) {
    echo "SKIP: media_assets table not present — apply migration 1.37.0\n";
    exit(0);
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
TestScriptSafety::assertSafeToMutateSettings();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(4)));
$pdo->prepare('DELETE FROM products WHERE slug LIKE ?')->execute(['ml-test-' . $tok . '%']);
$pdo->exec("DELETE FROM media_assets WHERE original_filename LIKE 'ml-{$tok}%'");

$catId = (int) $pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
if ($catId < 1) {
    $pdo->exec("INSERT INTO categories (name, slug) VALUES ('ML Cat', 'ml-cat-" . $tok . "')");
    $catId = (int) $pdo->lastInsertId();
}
$slug = 'ml-test-' . $tok;
$pdo->prepare(
    'INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)'
)->execute([$catId, 'ML Test', $slug, 'ML-' . $tok . '-1', 'draft']);
$pid = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)'
)->execute([$catId, 'ML Test 2', $slug . '-b', 'ML-' . $tok . '-2', 'draft']);
$pid2 = (int) $pdo->lastInsertId();

$fixture = static function (string $name) use ($tok): array {
    $dir = sys_get_temp_dir();
    $path = $dir . '/ml-' . $tok . '-' . $name . '.png';
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, $path);
        imagedestroy($im);
    } else {
        // 1x1 PNG + unique suffix (CLI may lack ext-gd; avoids identical SHA across fixtures).
        file_put_contents(
            $path,
            (base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true
            ) ?: '') . $tok . '-' . $name
        );
    }

    return [
        'name' => 'ml-' . $tok . '-' . $name . '.png',
        'type' => 'image/png',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
};

Upload::ensureUploadsRootSecured();
okM(is_dir(APNI_TOKRI_ROOT . '/uploads'), 'uploads root exists before ingest');

$f1 = $fixture('a');
$ing1 = MediaLibrary::ingestUpload($f1, 1);
okM(!empty($ing1['ok']) && !empty($ing1['media_id']), 'single upload creates media row');
$mid1 = (int) ($ing1['media_id'] ?? 0);
$media = MediaLibrary::findActive($mid1);
okM($media !== null && is_file(APNI_TOKRI_ROOT . '/' . ltrim((string) $media['file_path'], '/')), 'physical file saved once');

$beforeDup = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
$ingDup = MediaLibrary::ingestUpload($f1, 1);
$afterDup = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
okM(!empty($ingDup['ok']) && !empty($ingDup['duplicate']) && (int) ($ingDup['media_id'] ?? 0) === $mid1, 'duplicate SHA reuses media');
okM($beforeDup === $afterDup, 'duplicate upload does not insert second media row');

$f2 = $fixture('b');
$ingRace = MediaLibrary::ingestUpload($f2, 1);
$ingRace2 = MediaLibrary::ingestUpload($f2, 1);
okM((int) ($ingRace['media_id'] ?? 0) === (int) ($ingRace2['media_id'] ?? 0), 'sequential duplicate race returns same id');

$filePut = sys_get_temp_dir() . '/ml-fake-' . $tok . '.png';
file_put_contents($filePut, 'not-a-real-png');
$fakePng = [
    'name' => 'fake.png',
    'type' => 'image/png',
    'tmp_name' => $filePut,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($filePut),
];
okM(!Upload::storeMediaRaster($fakePng)['ok'], 'invalid image content rejected (decode)');

file_put_contents(sys_get_temp_dir() . '/ml-evil-' . $tok . '.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
$badSvg = [
    'name' => 'evil.svg',
    'type' => 'image/svg+xml',
    'tmp_name' => sys_get_temp_dir() . '/ml-evil-' . $tok . '.svg',
    'error' => UPLOAD_ERR_OK,
    'size' => filesize(sys_get_temp_dir() . '/ml-evil-' . $tok . '.svg'),
];
okM(!MediaLibrary::ingestUpload($badSvg, 1)['ok'], 'SVG rejected');

$jpegPath = sys_get_temp_dir() . '/ml-' . $tok . '.jpg';
file_put_contents(
    $jpegPath,
    base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIy'
        . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB'
        . '/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=',
        true
    ) ?: ''
);
$jpegFile = [
    'name' => 'ml-' . $tok . '.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => $jpegPath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($jpegPath),
];
$ingJpeg = MediaLibrary::ingestUpload($jpegFile, 1);
okM(!empty($ingJpeg['ok']), 'JPEG upload accepted');
$midJpeg = (int) ($ingJpeg['media_id'] ?? 0);

$webpOk = false;
$webpPath = sys_get_temp_dir() . '/ml-' . $tok . '.webp';
if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
    $wim = imagecreatetruecolor(2, 2);
    imagewebp($wim, $webpPath);
    imagedestroy($wim);
    $webpOk = is_file($webpPath);
}
if ($webpOk) {
    $webpFile = [
        'name' => 'ml-' . $tok . '.webp',
        'type' => 'image/webp',
        'tmp_name' => $webpPath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($webpPath),
    ];
    okM(!empty(MediaLibrary::ingestUpload($webpFile, 1)['ok']), 'WebP upload accepted');
} else {
    echo "SKIP: WebP upload (ext-gd imagewebp unavailable)\n";
}

$badMimePath = sys_get_temp_dir() . '/ml-badmime-' . $tok . '.png';
file_put_contents($badMimePath, 'plain text not an image');
$badMime = [
    'name' => 'bad.png',
    'type' => 'image/png',
    'tmp_name' => $badMimePath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($badMimePath),
];
okM(!Upload::storeMediaRaster($badMime)['ok'], 'invalid MIME/content rejected');

$hugePath = sys_get_temp_dir() . '/ml-huge-' . $tok . '.png';
$huge = fopen($hugePath, 'wb');
if ($huge !== false) {
    fwrite($huge, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    ) ?: '');
    fwrite($huge, str_repeat("\0", (5 * 1024 * 1024) + 1));
    fclose($huge);
    $hugeFile = [
        'name' => 'huge.png',
        'type' => 'image/png',
        'tmp_name' => $hugePath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($hugePath),
    ];
    okM(!MediaLibrary::ingestUpload($hugeFile, 1)['ok'], 'file over 5 MB rejected');
}

if (function_exists('imagecreatetruecolor')) {
    $cfgOrig = App::config();
    $dimPath = sys_get_temp_dir() . '/ml-dim-' . $tok . '.png';
    $dimIm = imagecreatetruecolor(32, 32);
    imagepng($dimIm, $dimPath);
    imagedestroy($dimIm);
    App::setConfig(array_replace_recursive($cfgOrig, [
        'upload' => ['max_image_width' => 8, 'max_image_height' => 8],
    ]));
    $dimFile = [
        'name' => 'ml-dim-' . $tok . '.png',
        'type' => 'image/png',
        'tmp_name' => $dimPath,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($dimPath),
    ];
    okM(!MediaLibrary::ingestUpload($dimFile, 1)['ok'], 'image over max width/height rejected');
    App::setConfig($cfgOrig);
    @unlink($dimPath);
} else {
    echo "SKIP: max dimensions (ext-gd unavailable)\n";
}

$badAdminId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM admin_users')->fetchColumn() + 999999;
$dbFailPath = sys_get_temp_dir() . '/ml-dbfail-' . $tok . '.png';
file_put_contents(
    $dbFailPath,
    (base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true
    ) ?: '') . $tok . '-dbfail'
);
$dbFailName = 'ml-dbfail-' . $tok . '.png';
$dbFailSha = hash('sha256', (string) file_get_contents($dbFailPath));
$dbFailFile = [
    'name' => $dbFailName,
    'type' => 'image/png',
    'tmp_name' => $dbFailPath,
    'error' => UPLOAD_ERR_OK,
    'size' => (int) filesize($dbFailPath),
];
$stmtDb = $pdo->prepare('SELECT COUNT(*) FROM media_assets WHERE file_sha256 = ?');
$stmtDb->execute([$dbFailSha]);
$beforeDbFail = (int) $stmtDb->fetchColumn();
$ingDbFail = MediaLibrary::ingestUpload($dbFailFile, $badAdminId);
okM(empty($ingDbFail['ok']), 'ingest fails when DB insert rejected (invalid uploaded_by)');
$stmtDb->execute([$dbFailSha]);
okM((int) $stmtDb->fetchColumn() === $beforeDbFail, 'DB failure did not create media_assets row');
$orphanStmt = $pdo->prepare('SELECT file_path FROM media_assets WHERE original_filename = ?');
$orphanStmt->execute([$dbFailName]);
okM($orphanStmt->fetchColumn() === false, 'DB failure left no media row for orphan cleanup check');
okM(!ml_test_uploads_has_sha_file($dbFailSha), 'DB failure removed orphan upload file from disk');
@unlink($dbFailPath);

$mid2 = (int) ($ingRace2['media_id'] ?? 0);

$attach = MediaLibrary::attachToProduct($pid, [$mid1], true);
okM($attach['attached'] !== [], 'attach media to product');
$images = ProductRepository::images($pid);
okM(count($images) === 1 && (int) ($images[0]['media_id'] ?? 0) === $mid1, 'product_images has media_id + file_path');
okM(strpos((string) $images[0]['file_path'], 'uploads/') === 0, 'file_path relative uploads URL');

$attach2 = MediaLibrary::attachToProduct($pid2, [$mid1], true);
okM(count($attach2['attached']) === 1, 'same media on second product');
okM(MediaLibrary::productUsageCount($mid1) === 2, 'usage count = 2');

if ($mid2 > 0 && $mid2 !== $mid1) {
    $multi = MediaLibrary::attachToProduct($pid, [$mid2], false);
    okM(count($multi['attached']) === 1, 'multiple images: second media attached to product');
    $imgsMulti = ProductRepository::images($pid);
    okM(count($imgsMulti) >= 2, 'multiple product_images rows on one product');
    $secondImgId = (int) ($imgsMulti[count($imgsMulti) - 1]['id'] ?? 0);
    if ($secondImgId > 0) {
        ProductRepository::setPrimaryImage($pid, $secondImgId);
        okM(ProductRepository::hasPrimaryImage($pid), 'main image selection persisted');
        $ids = array_map(static fn (array $im): int => (int) $im['id'], $imgsMulti);
        $ids = array_reverse($ids);
        ProductRepository::reorderImages($pid, $ids);
        $reordered = ProductRepository::images($pid);
        okM((int) ($reordered[0]['id'] ?? 0) === $ids[0], 'image sort order persisted');
    }
}

$delUsed = MediaLibrary::softDelete($mid1);
okM(empty($delUsed['ok']), 'soft delete blocked while in use');
$pathAfterSoftBlock = (string) ($media['file_path'] ?? '');
okM(is_file(APNI_TOKRI_ROOT . '/' . ltrim($pathAfterSoftBlock, '/')), 'soft-delete block leaves physical file');

ProductRepository::deleteImage($pid, (int) $images[0]['id']);
okM(MediaLibrary::productUsageCount($mid1) === 1, 'detach product does not delete media');
okM(is_file(APNI_TOKRI_ROOT . '/' . ltrim($pathAfterSoftBlock, '/')), 'physical file remains after detach');

$pdo->prepare('UPDATE media_assets SET file_path = ? WHERE id = ?')->execute([
    'uploads/media/missing/ml-missing-' . $tok . '.png',
    $mid1,
]);
$badAttach = MediaLibrary::attachToProduct($pid, [$mid1], false);
okM($badAttach['attached'] === [] && $badAttach['errors'] !== [], 'attach rejects missing physical file during attachment');
$pdo->prepare('UPDATE media_assets SET file_path = ? WHERE id = ?')->execute([
    $pathAfterSoftBlock,
    $mid1,
]);

ProductRepository::deleteImage($pid2, (int) ProductRepository::images($pid2)[0]['id']);
$delFree = MediaLibrary::softDelete($mid1);
okM(!empty($delFree['ok']), 'soft delete unused media');
okM(is_file(APNI_TOKRI_ROOT . '/' . ltrim($pathAfterSoftBlock, '/')), 'soft delete does not unlink file');

$reup = MediaLibrary::ingestUpload($f1, 1);
okM(empty($reup['ok']) && str_contains((string) ($reup['message'] ?? ''), 'soft-deleted'), 're-upload blocked when soft-deleted sha exists');

$pdo->prepare('INSERT INTO product_images (product_id, media_id, file_path, sort_order, is_primary) VALUES (?, ?, ?, 0, 0)')
    ->execute([$pid, $mid1, $pathAfterSoftBlock]);
okM(!MediaLibrary::purgeDeleted($mid1)['ok'], 'purge rejected while product_images references media');
$pdo->prepare('DELETE FROM product_images WHERE product_id = ? AND media_id = ?')->execute([$pid, $mid1]);

$pdo->prepare(
    'INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, 0, 0)'
)->execute([$pid, $pathAfterSoftBlock]);
okM(!MediaLibrary::purgeDeleted($mid1)['ok'], 'purge rejected when legacy file_path still referenced');
$pdo->prepare('DELETE FROM product_images WHERE product_id = ? AND file_path = ? AND media_id IS NULL')
    ->execute([$pid, $pathAfterSoftBlock]);

$purge = MediaLibrary::purgeDeleted($mid1);
okM(!empty($purge['ok']), 'purge soft-deleted unused media');
okM(MediaLibrary::findActive($mid1) === null, 'purged media not active');
okM(!is_file(APNI_TOKRI_ROOT . '/' . ltrim($pathAfterSoftBlock, '/')), 'purge removes physical file');

if (ProductRepository::imageCount($pid) < ProductRepository::MAX_IMAGES_PER_PRODUCT) {
    $leg = Upload::storeProductImage($fixture('leg'), 'products/' . $pid);
    if (!empty($leg['ok'])) {
        ProductRepository::addImage($pid, (string) $leg['path'], false);
        $pub = ProductRepository::toPublicArray(ProductRepository::find($pid) ?: [], true);
        okM($pub['images'] !== [], 'legacy product_images still render URLs');
        $legPath = (string) $leg['path'];
        ProductRepository::addImage($pid2, $legPath, false);
        ProductRepository::deleteImage($pid, (int) ProductRepository::images($pid)[0]['id']);
        okM(is_file(APNI_TOKRI_ROOT . '/' . ltrim($legPath, '/')), 'legacy shared path: detach keeps file for other product');
        foreach (ProductRepository::images($pid2) as $im) {
            if ((string) $im['file_path'] === $legPath) {
                ProductRepository::deleteImage($pid2, (int) $im['id']);
            }
        }
    }
}

$ajaxMedia = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/media-library.php');
$ajaxProd = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/product-images.php');
okM(str_contains($ajaxMedia, 'AdminDemoPerms::denyIfCannot') && str_contains($ajaxMedia, 'Csrf::requireValid'), 'static: media AJAX auth + CSRF');
okM(str_contains($ajaxProd, 'denyIfCannot') && str_contains($ajaxProd, 'Csrf::requireValid'), 'static: product-images AJAX auth + CSRF');

$mig = (string) file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.37.0_media_library.sql');
okM(str_contains($mig, 'uq_media_file_sha256'), 'static: migration unique sha256 index');
okM(Install::SCHEMA_VERSION === '1.37.0-media-library', 'install.php schema version');

$pdo->prepare('DELETE FROM product_images WHERE product_id IN (?,?)')->execute([$pid, $pid2]);
$pdo->prepare('DELETE FROM products WHERE id IN (?,?)')->execute([$pid, $pid2]);
@unlink($f1['tmp_name']);
@unlink($f2['tmp_name']);
@unlink($filePut);
@unlink(sys_get_temp_dir() . '/ml-evil-' . $tok . '.svg');
@unlink($jpegPath);
@unlink($badMimePath);
if (isset($hugePath) && is_file($hugePath)) {
    @unlink($hugePath);
}
if (isset($webpPath) && is_file($webpPath)) {
    @unlink($webpPath);
}
if ($midJpeg > 0) {
    MediaLibrary::softDelete($midJpeg);
    MediaLibrary::purgeDeleted($midJpeg);
}

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
