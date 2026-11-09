<?php

declare(strict_types=1);

/**
 * Media library bulk trash / restore / purge (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library-bulk.php
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

if (!MediaLibrary::isEnabled()) {
    echo "SKIP: media_assets not present\n";
    exit(0);
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = strtolower(bin2hex(random_bytes(4)));

$fixture = static function (string $suffix) use ($tok): array {
    $path = sys_get_temp_dir() . '/ml-bulk-' . $tok . '-' . $suffix . '.png';
    file_put_contents(
        $path,
        (base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        ) ?: '') . $tok . '-' . $suffix
    );

    return [
        'name' => 'ml-bulk-' . $tok . '-' . $suffix . '.png',
        'type' => 'image/png',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($path),
    ];
};

$ids = [];
for ($i = 0; $i < 4; $i++) {
    $ing = MediaLibrary::ingestUpload($fixture('a' . $i), 1);
    $id = (int) ($ing['media_id'] ?? 0);
    okB($id > 0, 'ingest bulk fixture ' . $i);
    $ids[] = $id;
}

$bulkTrash = MediaLibrary::bulkSoftDelete($ids);
okB(!empty($bulkTrash['ok']), 'bulk soft delete ok');
okB(($bulkTrash['succeeded'] ?? 0) === 4, 'bulk soft delete 4 succeeded');

$bulkRestore = MediaLibrary::bulkRestore($ids);
okB(!empty($bulkRestore['ok']), 'bulk restore ok');
okB(($bulkRestore['succeeded'] ?? 0) === 4, 'bulk restore 4 succeeded');

MediaLibrary::bulkSoftDelete($ids);

$ingRef = MediaLibrary::ingestUpload($fixture('ref'), 1);
$refId = (int) ($ingRef['media_id'] ?? 0);
$pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)')->execute(['Blk Cat', 'blk-' . $tok]);
$cat = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)'
)->execute([$cat, 'Blk Prod', 'blk-prod-' . $tok, 'BLK-' . $tok, 'draft']);
$pid = (int) $pdo->lastInsertId();
MediaLibrary::attachToProduct($pid, [$refId], true);
MediaLibrary::restore($ids[3]);

$blocked = MediaLibrary::bulkSoftDelete([$refId, $ids[3]]);
okB(!empty($blocked['ok']), 'bulk soft partial ok');
okB(($blocked['blocked'] ?? 0) >= 1, 'referenced image blocked in bulk');
okB(($blocked['succeeded'] ?? 0) >= 1, 'unused still trashed in bulk');

$tooMany = range(1, MediaLibrary::BULK_ACTION_MAX_IDS + 1);
$limit = MediaLibrary::bulkSoftDelete($tooMany);
okB(empty($limit['ok']), 'batch size limit enforced');

$dup = MediaLibrary::bulkRestore([$ids[0], $ids[0], $ids[0]]);
okB(!empty($dup['ok']), 'duplicate ids deduped restore');
okB(($dup['total_selected'] ?? 0) === 1, 'duplicate ids collapsed to one');

MediaLibrary::softDelete($ids[1]);
MediaLibrary::softDelete($ids[2]);
$purge = MediaLibrary::bulkPurgeDeleted([$ids[1], $ids[2], 999999]);
okB(!empty($purge['ok']), 'bulk purge partial');
okB(($purge['succeeded'] ?? 0) === 2, 'bulk purge two assets');
okB(($purge['failed'] ?? 0) >= 1, 'invalid id reported failed');

$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/media-library.php');
okB(str_contains($ajax, 'bulk_soft_delete') && str_contains($ajax, 'bulk_purge'), 'static: bulk ajax actions');
okB(str_contains($ajax, 'bulk_restore'), 'static: bulk restore ajax');

$pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$pid]);
$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
$pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cat]);

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
