<?php

declare(strict_types=1);

/**
 * Product gallery: multi-upload, reorder, set primary (admin).
 * POST multipart or application/x-www-form-urlencoded + CSRF.
 */
require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}
Csrf::requireValid();

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
if ($productId <= 0) {
    Ajax::error('Invalid product.');
}

if ($action === 'upload') {
    $files = ProductImageAdminService::normalizedFileList($_FILES['images'] ?? null);
    if ($files === [] && isset($_FILES['image'])) {
        $files = ProductImageAdminService::normalizedFileList($_FILES['image']);
    }
    $batch = ProductImageAdminService::uploadBatch($productId, $files);
    $okAny = false;
    $failAny = false;
    foreach ($batch['results'] as $r) {
        if (!empty($r['ok'])) {
            $okAny = true;
        } else {
            $failAny = true;
        }
    }
    $message = $okAny && $failAny
        ? 'Some images uploaded; others failed. See details below.'
        : ($okAny ? 'Images uploaded.' : 'No images were uploaded.');
    Ajax::success([
        'results' => $batch['results'],
        'images' => imagesForClient($batch['images']),
        'count' => count($batch['images']),
    ], $message);
}

if ($action === 'set_primary') {
    $imageId = isset($_POST['image_id']) ? (int) $_POST['image_id'] : 0;
    if (!ProductImageAdminService::setPrimary($productId, $imageId)) {
        Ajax::error('Could not set primary image.');
    }
    Ajax::success(['images' => imagesForClient(ProductRepository::images($productId))], 'Primary image updated.');
}

if ($action === 'reorder') {
    $raw = isset($_POST['image_order']) ? (string) $_POST['image_order'] : '';
    $ids = [];
    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
        if (ctype_digit($p)) {
            $ids[] = (int) $p;
        }
    }
    if (!ProductImageAdminService::reorder($productId, $ids)) {
        Ajax::error('Could not save image order.');
    }
    Ajax::success(['images' => imagesForClient(ProductRepository::images($productId))], 'Image order saved.');
}

Ajax::error('Invalid action.');

/** @param list<array<string, mixed>> $rows */
function imagesForClient(array $rows): array
{
    $out = [];
    foreach ($rows as $img) {
        $out[] = [
            'id' => (int) $img['id'],
            'url' => asset_url((string) $img['file_path']),
            'is_primary' => !empty($img['is_primary']),
            'sort_order' => (int) ($img['sort_order'] ?? 0),
        ];
    }

    return $out;
}
