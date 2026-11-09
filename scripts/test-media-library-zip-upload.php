<?php

declare(strict_types=1);

/**
 * Media library ZIP bulk upload (disposable DB).
 * Run: php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-media-library-zip-upload.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/scripts/lib/test_script_safety.php';

$fail = 0;
function okZ(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

if (!MediaLibrary::isEnabled()) {
    echo "SKIP: media_assets table not present — apply migration 1.37.0\n";
    exit(0);
}

TestScriptSafety::assertDisposableDatabaseOrAbort();
Install::ensureConnection();
$pdo = Database::pdo();
$tok = bin2hex(random_bytes(3));

/**
 * @return string absolute path to 1x1 PNG unique by suffix
 */
function zip_test_png(string $suffix): string
{
    $path = sys_get_temp_dir() . '/ml-zip-' . $suffix . '.png';
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(2, 2);
        imagepng($im, $path);
        imagedestroy($im);
        file_put_contents($path, (string) file_get_contents($path) . $suffix, FILE_APPEND);
    } else {
        file_put_contents(
            $path,
            (base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true
            ) ?: '') . $suffix
        );
    }

    return $path;
}

function zip_test_build(string $destZip, array $entries): bool
{
    $zip = new ZipArchive();
    if ($zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    foreach ($entries as $entry => $sourcePath) {
        if (!$zip->addFile($sourcePath, $entry)) {
            $zip->close();

            return false;
        }
    }
    $zip->close();

    return is_file($destZip);
}

$before = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();

$work = sys_get_temp_dir() . '/ml-zip-suite-' . $tok;
@mkdir($work, 0755, true);

$uniqueA = zip_test_png('a-' . $tok);
$uniqueB = zip_test_png('b-' . $tok);
$bulkZip = $work . '/bulk-100.zip';
$entries = [];
for ($i = 0; $i < 98; $i++) {
    $p = zip_test_png('n' . $i . '-' . $tok);
    $entries['images/img-' . $i . '.png'] = $p;
}
$entries['images/unique-a.png'] = $uniqueA;
$entries['images/dup-a.png'] = $uniqueA;
okZ(zip_test_build($bulkZip, $entries), 'build 100-entry image ZIP');

$bulkRes = MediaLibraryZipImport::processZipUpload([
    'name' => 'bulk-100.zip',
    'tmp_name' => $bulkZip,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($bulkZip),
    'trusted_internal' => true,
], null);
okZ(!empty($bulkRes['ok']), 'process 100-image ZIP');
$sum = $bulkRes['summary'] ?? [];
okZ(($sum['total_found'] ?? 0) === 100, 'total_found 100');
okZ(($sum['uploaded'] ?? 0) + ($sum['duplicates'] ?? 0) >= 99, 'uploaded + duplicates for valid images');
okZ(($sum['duplicates'] ?? 0) >= 1, 'duplicate image detected in ZIP');

$afterBulk = (int) $pdo->query('SELECT COUNT(*) FROM media_assets WHERE deleted_at IS NULL')->fetchColumn();
okZ($afterBulk - $before >= 99, 'media_assets increased (~99 unique)');

$mixedZip = $work . '/mixed.zip';
$badTxt = $work . '/bad.txt';
$badSvg = $work . '/bad.svg';
$badPhp = $work . '/bad.php';
file_put_contents($badTxt, 'not an image');
file_put_contents($badSvg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
file_put_contents($badPhp, '<?php echo 1;');
$nestedInner = $work . '/inner.zip';
zip_test_build($nestedInner, ['x.png' => $uniqueB]);
okZ(zip_test_build($mixedZip, [
    'ok/photo.png' => $uniqueB,
    'reject/notes.txt' => $badTxt,
    'reject/icon.svg' => $badSvg,
    'reject/evil.php' => $badPhp,
    'reject/nested.zip' => $nestedInner,
    '../traversal.png' => $uniqueA,
]), 'build mixed invalid ZIP');
$mixedRes = MediaLibraryZipImport::processZipUpload([
    'name' => 'mixed.zip',
    'tmp_name' => $mixedZip,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($mixedZip),
    'trusted_internal' => true,
], null);
okZ(!empty($mixedRes['ok']), 'mixed ZIP processed');
$sum2 = $mixedRes['summary'] ?? [];
okZ(($sum2['invalid'] ?? 0) >= 4, 'invalid entries rejected');
okZ(($mixedRes['errors'] ?? []) !== [], 'error rows recorded');
okZ(!empty($mixedRes['error_report_csv_base64']), 'CSV error report generated');

$usage = (int) $pdo->query('SELECT COUNT(*) FROM product_images WHERE media_id IS NOT NULL')->fetchColumn();
okZ($usage === 0, 'ZIP import does not attach images to products');

$page = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/media/index.php');
okZ(str_contains($page, 'data-at-media-zip-input'), 'static: media page ZIP upload UI');
okZ(str_contains($page, 'data-at-media-zip-result-panel'), 'static: ZIP result panel markup');
okZ(str_contains($page, 'data-at-media-zip-dismiss'), 'static: ZIP dismiss button');
okZ(str_contains($page, 'data-at-media-zip-download-errors'), 'static: ZIP CSV download button');
$ajax = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/ajax/media-library.php');
okZ(str_contains($ajax, 'upload_zip'), 'static: upload_zip AJAX action');
okZ(str_contains($ajax, 'denyIfCannot') && str_contains($ajax, 'Csrf::requireValid'), 'static: auth + CSRF');

$adminJs = (string) file_get_contents(APNI_TOKRI_ROOT . '/assets/js/admin.js');
$zipPos = strpos($adminJs, '[data-at-media-zip-panel]');
okZ($zipPos !== false, 'static: ZIP UI block in admin.js');
$zipBlock = $zipPos !== false ? substr($adminJs, $zipPos, 14000) : '';
okZ(
    $zipBlock !== '' && !preg_match('/summary\.(uploaded|duplicates)[\s\S]{0,200}?setTimeout[\s\S]{0,150}?location\.reload/s', $zipBlock),
    'ZIP success path does not auto-reload after 1s'
);
okZ(str_contains($zipBlock, '$zipRefresh.on'), 'manual Refresh library list is optional');
okZ(str_contains($zipBlock, 'clearZipResultPanel'), 'ZIP manual dismiss clears panel');
okZ(str_contains($zipBlock, 'ZIP_RESULT_MIN_VISIBLE_MS'), 'ZIP visibility policy constant (20s minimum, no auto-hide)');
okZ(str_contains($zipBlock, 'uploadZip(selectedZip)'), 'new ZIP import replaces prior result on confirm');
okZ(str_contains($zipBlock, 'data-at-media-zip-download-errors'), 'CSV download wired in ZIP block');
okZ(!preg_match('/setTimeout\s*\([^)]*location\.reload/s', $zipBlock), 'no timed page reload in ZIP block');
okZ(
    !preg_match('/setTimeout[\s\S]{0,600}(\$zipResults\.empty|clearZipResultPanel)/s', $zipBlock),
    'ZIP results remain visible after 1s/10s/20s (no auto-clear timer)'
);
$individualUpload = strpos($adminJs, 'function uploadFiles');
okZ($individualUpload !== false && $individualUpload < ($zipPos ?: PHP_INT_MAX), 'individual media upload block unchanged before ZIP');

foreach (glob($work . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($work);
@unlink($uniqueA);
@unlink($uniqueB);

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
