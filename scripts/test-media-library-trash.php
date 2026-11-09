<?php

declare(strict_types=1);

/**
 * Media library trash / restore / permanent delete (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library-trash.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okT(bool $c, string $m): void
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
    $path = sys_get_temp_dir() . '/ml-tr-' . $tok . '-' . $suffix . '.png';
    file_put_contents(
        $path,
        (base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        ) ?: '') . $tok . '-' . $suffix
    );

    return [
        'name' => 'ml-tr-' . $tok . '-' . $suffix . '.png',
        'type' => 'image/png',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => (int) filesize($path),
    ];
};

$f1 = $fixture('a');
$ing = MediaLibrary::ingestUpload($f1, 1);
$mid = (int) ($ing['media_id'] ?? 0);
okT($mid > 0, 'ingest for trash tests');

$trashBefore = MediaLibrary::searchTrash('', 'newest', 1, 24);
okT(is_array($trashBefore['rows']), 'trash search returns rows array');

$soft = MediaLibrary::softDelete($mid);
okT(!empty($soft['ok']), 'soft delete moves to trash');
okT(MediaLibrary::findActive($mid) === null, 'not active after soft delete');
okT(MediaLibrary::findDeleted($mid) !== null, 'findDeleted after soft delete');

$trashAfter = MediaLibrary::searchTrash('ml-tr-' . $tok, 'newest', 1, 24);
okT($trashAfter['total'] >= 1, 'trash listing contains deleted asset');

$rest = MediaLibrary::restore($mid);
okT(!empty($rest['ok']), 'restore from trash');
okT(MediaLibrary::findActive($mid) !== null, 'active after restore');
okT(MediaLibrary::findDeleted($mid) === null, 'not in trash after restore');

MediaLibrary::softDelete($mid);
$sha = (string) $pdo->query('SELECT file_sha256 FROM media_assets WHERE id = ' . (int) $mid)->fetchColumn();
$ing2 = MediaLibrary::ingestUpload($fixture('dup'), 1);
$mid2 = (int) ($ing2['media_id'] ?? 0);
if ($mid2 > 0 && $sha !== '') {
    // Production schema enforces one row per SHA globally; drop the unique key on this disposable DB only
    // so we can simulate the restore-time active SHA collision guard.
    try {
        $pdo->exec('ALTER TABLE media_assets DROP INDEX uq_media_file_sha256');
    } catch (Throwable $e) {
        echo "FAIL: could not drop uq_media_file_sha256 for SHA conflict fixture: {$e->getMessage()}\n";
        $fail++;
    }
    $pdo->prepare('UPDATE media_assets SET file_sha256 = ? WHERE id = ?')->execute([$sha, $mid2]);
    $conflict = MediaLibrary::restore($mid);
    okT(empty($conflict['ok']), 'restore blocked on SHA conflict');
    okT(str_contains((string) ($conflict['message'] ?? ''), 'SHA-256'), 'restore conflict message');
    MediaLibrary::softDelete($mid2);
    MediaLibrary::purgeDeleted($mid2);
}

MediaLibrary::softDelete($mid);
$pPath = (string) $pdo->query('SELECT file_path FROM media_assets WHERE id = ' . (int) $mid)->fetchColumn();
$purge = MediaLibrary::purgeDeleted($mid);
okT(!empty($purge['ok']), 'permanent delete unused trashed asset');
okT(MediaLibrary::findDeleted($mid) === null, 'row removed after purge');
if ($pPath !== '') {
    okT(!is_file(APNI_TOKRI_ROOT . '/' . ltrim($pPath, '/')), 'physical file removed after purge');
}

$ing3 = MediaLibrary::ingestUpload($fixture('ref'), 1);
$mid3 = (int) ($ing3['media_id'] ?? 0);
$pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)')->execute(['TR Cat', 'tr-cat-' . $tok]);
$cat = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO products (category_id, name, slug, sku, status) VALUES (?, ?, ?, ?, ?)'
)->execute([$cat, 'TR Prod', 'tr-prod-' . $tok, 'TR-' . $tok, 'draft']);
$pid = (int) $pdo->lastInsertId();
MediaLibrary::attachToProduct($pid, [$mid3], true);
MediaLibrary::softDelete($mid3);
$blockPurge = MediaLibrary::purgeDeleted($mid3);
okT(empty($blockPurge['ok']), 'purge blocked while product_images references media_id');

$ing4 = MediaLibrary::ingestUpload($fixture('leg'), 1);
$mid4 = (int) ($ing4['media_id'] ?? 0);
$legPath = (string) ($pdo->query('SELECT file_path FROM media_assets WHERE id = ' . $mid4)->fetchColumn() ?: '');
MediaLibrary::softDelete($mid4);
$pdo->prepare('INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, 0, 0)')
    ->execute([$pid, $legPath]);
$blockLegacy = MediaLibrary::purgeDeleted($mid4);
okT(empty($blockLegacy['ok']), 'purge blocked for legacy file_path reference');

$ing5 = MediaLibrary::ingestUpload($fixture('share'), 1);
$mid5 = (int) ($ing5['media_id'] ?? 0);
$sharePath = (string) $pdo->query('SELECT file_path FROM media_assets WHERE id = ' . (int) $mid5)->fetchColumn();
MediaLibrary::softDelete($mid5);
$ing6 = MediaLibrary::ingestUpload($fixture('share2'), 1);
$mid6 = (int) ($ing6['media_id'] ?? 0);
if ($mid5 > 0 && $mid6 > 0 && $sharePath !== '') {
    try {
        $pdo->exec('ALTER TABLE media_assets DROP INDEX uq_media_file_path');
    } catch (Throwable $e) {
        echo "FAIL: could not drop uq_media_file_path for shared-path fixture: {$e->getMessage()}\n";
        $fail++;
    }
    $pdo->prepare('UPDATE media_assets SET file_path = ? WHERE id = ?')->execute([$sharePath, $mid6]);
    $blockShare = MediaLibrary::purgeDeleted($mid5);
    okT(empty($blockShare['ok']), 'purge blocked when another media_assets shares file_path');
    MediaLibrary::softDelete($mid6);
    MediaLibrary::purgeDeleted($mid6);
}

$ing7 = MediaLibrary::ingestUpload($fixture('miss'), 1);
$mid7 = (int) ($ing7['media_id'] ?? 0);
MediaLibrary::softDelete($mid7);
$pdo->prepare('UPDATE media_assets SET file_path = ? WHERE id = ?')->execute([
    'uploads/media/missing/tr-miss-' . $tok . '.png',
    $mid7,
]);
$purgeMiss = MediaLibrary::purgeDeleted($mid7);
okT(!empty($purgeMiss['ok']), 'purge allowed when physical file already missing');

$ing8 = MediaLibrary::ingestUpload($fixture('unlink'), 1);
$mid8 = (int) ($ing8['media_id'] ?? 0);
$full8 = '';
if ($mid8 > 0) {
    $rel8 = (string) $pdo->query('SELECT file_path FROM media_assets WHERE id = ' . $mid8)->fetchColumn();
    $full8 = $rel8 !== '' ? APNI_TOKRI_ROOT . '/' . ltrim($rel8, '/') : '';
    MediaLibrary::softDelete($mid8);
    if ($full8 !== '' && is_file($full8)) {
        @chmod($full8, 0444);
    }
    $purgeUnlink = MediaLibrary::purgeDeleted($mid8);
    okT(!empty($purgeUnlink['ok']), 'purge commits when unlink may fail');
    okT(
        !empty($purgeUnlink['file_delete_warning'])
        || (isset($purgeUnlink['file_deleted']) && $purgeUnlink['file_deleted'] === true),
        'purge reports file delete outcome'
    );
    if ($full8 !== '' && is_file($full8)) {
        @chmod($full8, 0666);
        @unlink($full8);
    }
}

$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/media-library.php');
okT(str_contains($ajax, 'restore') && str_contains($ajax, 'Csrf::requireValid'), 'static: restore + CSRF in AJAX');
okT(str_contains($ajax, 'AdminDemoPerms::denyIfCannot'), 'static: admin auth on AJAX');

$pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$pid]);
$pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$pid]);
$pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$cat]);

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
