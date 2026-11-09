<?php

declare(strict_types=1);

/**
 * Media library AJAX (list, upload, soft delete, purge, alt).
 */
require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
}
if (!Install::isSchemaReady() || !MediaLibrary::isEnabled()) {
    Ajax::error('Media library is not available. Apply migration 1.37.0.', 503);
}

$action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
}

if ($action === 'trash_list') {
    $q = isset($_REQUEST['q']) ? (string) $_REQUEST['q'] : '';
    $sort = isset($_REQUEST['sort']) ? (string) $_REQUEST['sort'] : 'newest';
    $page = isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 1;
    $per = isset($_REQUEST['per_page']) ? (int) $_REQUEST['per_page'] : 24;
    $result = MediaLibrary::searchTrash($q, $sort === 'oldest' ? 'oldest' : 'newest', $page, $per);
    $items = [];
    foreach ($result['rows'] as $row) {
        $items[] = MediaLibrary::rowForClientTrash($row);
    }
    Ajax::success([
        'items' => $items,
        'total' => $result['total'],
        'page' => $result['page'],
        'per_page' => $result['per_page'],
        'total_pages' => $result['total_pages'],
    ]);
}

if ($action === 'list') {
    $q = isset($_REQUEST['q']) ? (string) $_REQUEST['q'] : '';
    $usage = isset($_REQUEST['usage']) ? (string) $_REQUEST['usage'] : '';
    $sort = isset($_REQUEST['sort']) ? (string) $_REQUEST['sort'] : 'newest';
    $page = isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 1;
    $per = isset($_REQUEST['per_page']) ? (int) $_REQUEST['per_page'] : 24;
  if (!in_array($usage, ['', 'used', 'unused'], true)) {
        $usage = '';
    }
    $result = MediaLibrary::search($q, $usage, $sort === 'oldest' ? 'oldest' : 'newest', $page, $per);
    $items = [];
    foreach ($result['rows'] as $row) {
        $items[] = MediaLibrary::rowForClient($row);
    }
    Ajax::success([
        'items' => $items,
        'total' => $result['total'],
        'page' => $result['page'],
        'per_page' => $result['per_page'],
        'total_pages' => $result['total_pages'],
    ]);
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = ProductImageAdminService::normalizedFileList($_FILES['images'] ?? null);
    if ($files === [] && isset($_FILES['image'])) {
        $files = ProductImageAdminService::normalizedFileList($_FILES['image']);
    }
    if ($files === []) {
        Ajax::error('No files selected.');
    }
    $adminId = AdminAuth::check() ? (int) AdminAuth::id() : null;
    $results = [];
    $created = [];
    foreach ($files as $file) {
        $label = basename((string) ($file['name'] ?? 'image'));
        $ingest = MediaLibrary::ingestUpload($file, $adminId);
        if (!$ingest['ok'] || empty($ingest['media_id'])) {
            $results[] = ['filename' => $label, 'ok' => false, 'message' => (string) ($ingest['message'] ?? 'Failed')];
            continue;
        }
        $media = MediaLibrary::findActive((int) $ingest['media_id']);
        if ($media === null) {
            $results[] = ['filename' => $label, 'ok' => false, 'message' => 'Record missing.'];
            continue;
        }
        $results[] = [
            'filename' => $label,
            'ok' => true,
            'duplicate' => !empty($ingest['duplicate']),
            'media' => MediaLibrary::rowForClient($media),
        ];
        $created[] = MediaLibrary::rowForClient($media);
    }
    Ajax::success(['results' => $results, 'items' => $created], 'Upload finished.');
}

if ($action === 'soft_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
    $res = MediaLibrary::softDelete($mediaId);
    if (!$res['ok']) {
        $msg = (string) ($res['message'] ?? 'Delete failed.');
        if (!empty($res['products']) && is_array($res['products'])) {
            $names = array_map(static fn (array $p): string => (string) ($p['name'] ?? 'Product'), $res['products']);
            $msg .= ' Used by: ' . implode(', ', array_slice($names, 0, 8));
        }
        Ajax::error($msg, 400, 'in_use');
    }
    Ajax::success([], 'Media moved to trash.');
}

if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
    $res = MediaLibrary::restore($mediaId);
    if (!$res['ok']) {
        Ajax::error((string) ($res['message'] ?? 'Restore failed.'), 400);
    }
    Ajax::success([], 'Media restored.');
}

if ($action === 'purge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
    $confirm = isset($_POST['confirm_purge']) && (string) $_POST['confirm_purge'] === '1';
    if (!$confirm) {
        Ajax::error('Confirmation required for permanent delete.');
    }
    $res = MediaLibrary::purgeDeleted($mediaId);
    if (!$res['ok']) {
        Ajax::error((string) ($res['message'] ?? 'Purge failed.'));
    }
    $msg = 'Media permanently deleted.';
    if (!empty($res['file_delete_warning'])) {
        $msg = (string) $res['file_delete_warning'];
    }
    Ajax::success(['file_deleted' => $res['file_deleted'] ?? false], $msg);
}

if ($action === 'update_alt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mediaId = isset($_POST['media_id']) ? (int) $_POST['media_id'] : 0;
    $alt = isset($_POST['alt_text']) ? (string) $_POST['alt_text'] : '';
    if (!MediaLibrary::updateAlt($mediaId, $alt)) {
        Ajax::error('Could not update alt text.');
    }
    Ajax::success([], 'Alt text saved.');
}

if ($action === 'usage' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $mediaId = isset($_GET['media_id']) ? (int) $_GET['media_id'] : 0;
    Ajax::success([
        'count' => MediaLibrary::productUsageCount($mediaId),
        'products' => MediaLibrary::productsUsingMedia($mediaId),
    ]);
}

Ajax::error('Invalid action.');
