<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okUv(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$rel = 'uploads/products/_uv_' . bin2hex(random_bytes(2)) . '.png';
$abs = APNI_TOKRI_ROOT . '/' . $rel;
@mkdir(dirname($abs), 0755, true);
file_put_contents($abs, 'a');
$v1 = upload_asset_version($rel);
$url1 = upload_asset_url($rel);
okUv(str_contains($url1, '?v='), 'upload URL always versioned');
okUv($v1 === upload_asset_version($rel), 'version stable across calls');

touch($abs, 1_700_000_000);
clearstatcache(true, $abs);
$v2 = upload_asset_version($rel);
okUv($v1 !== $v2, 'version changes when file mtime changes');

file_put_contents($abs, 'longer-content');
clearstatcache(true, $abs);
$v3 = upload_asset_version($rel);
okUv($v2 !== $v3, 'version changes when file size changes');

$vMedia = upload_asset_version($rel, 'abc' . str_repeat('0', 61), 42);
okUv(str_contains($vMedia, 'm42') && str_contains($vMedia, 'abc'), 'version includes media id and content hash');

$htRoot = file_get_contents(APNI_TOKRI_ROOT . '/.htaccess') ?: '';
okUv(strpos($htRoot, 'immutable') !== false, 'root still caches built assets');
okUv(
    preg_match('/FilesMatch.*jpg.*immutable/s', $htRoot) !== 1,
    'root no longer marks all jpg/png immutable'
);

$htUp = file_get_contents(APNI_TOKRI_ROOT . '/uploads/.htaccess') ?: '';
okUv(strpos($htUp, 'must-revalidate') !== false, 'uploads htaccess requires revalidation');

$backslash = upload_asset_url('uploads\\products\\test.png');
okUv(str_contains($backslash, 'uploads/products'), 'backslash paths normalized');

@unlink($abs);

echo "\nTotal failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
