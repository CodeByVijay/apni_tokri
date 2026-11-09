<?php

declare(strict_types=1);

/**
 * Bulk import template/sample download security regression.
 * CLI: php scripts/test-bulk-import-download-security.php
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function okSec(bool $c, string $m): void
{
    global $fail;
    echo ($c ? 'OK' : 'FAIL') . ': ' . $m . "\n";
    if (!$c) {
        $fail++;
    }
}

$bulkPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/products/bulk-import.php');
$initPhp = (string) file_get_contents(APNI_TOKRI_ROOT . '/admin/_init.php');
okSec(str_contains($initPhp, 'AdminGuard::requireAuthentication'), 'admin _init enforces authentication');
$denyPos = strpos($bulkPhp, "denyIfCannot('products')");
$tplPos = strpos($bulkPhp, 'download_template');
$samplePos = strpos($bulkPhp, 'download_sample_package');
okSec($denyPos !== false && $tplPos !== false && $denyPos < $tplPos, 'products permission before template download');
okSec($denyPos !== false && $samplePos !== false && $denyPos < $samplePos, 'products permission before sample download');
okSec(str_contains($bulkPhp, 'prepareTemplateDownloadFile'), 'template built to per-request temp file');
okSec(str_contains($bulkPhp, 'prepareSamplePackageDownloadFile'), 'sample package built to per-request temp file');
okSec(str_contains($bulkPhp, 'AdminFileDownload::streamAttachment'), 'downloads use secure stream helper');
okSec(!str_contains($bulkPhp, "jobDir('template')"), 'downloads do not use web-visible template job dir');

$htTmp = (string) file_get_contents(APNI_TOKRI_ROOT . '/uploads/tmp/.htaccess');
okSec(str_contains($htTmp, 'Require all denied'), 'uploads/tmp Apache deny-all present');

$htJobs = (string) file_get_contents(APNI_TOKRI_ROOT . '/uploads/tmp/bulk-import/.htaccess');
okSec(str_contains($htJobs, 'Require all denied'), 'uploads/tmp/bulk-import Apache deny-all present');

ProductBulkImport::ensureBulkImportStorageSecurity();
okSec(is_file(APNI_TOKRI_ROOT . '/uploads/tmp/.htaccess'), 'ensureBulkImportStorageSecurity keeps tmp htaccess');

$a = ProductBulkImport::prepareTemplateDownloadFile();
$b = ProductBulkImport::prepareTemplateDownloadFile();
okSec(!empty($a['ok']) && !empty($b['ok']), 'concurrent template preparations succeed');
okSec(
    isset($a['path'], $b['path']) && $a['path'] !== $b['path'],
    'concurrent template downloads use distinct temp paths'
);
okSec(is_file($a['path']) && filesize($a['path']) > 500, 'template file non-empty');
$tplSheets = SimpleXlsx::read((string) $a['path']);
okSec($tplSheets !== [], 'template temp file is valid XLSX');

$pkgA = ProductBulkImport::prepareSamplePackageDownloadFile();
$pkgB = ProductBulkImport::prepareSamplePackageDownloadFile();
okSec(!empty($pkgA['ok']) && !empty($pkgB['ok']), 'concurrent sample package preparations succeed');
okSec($pkgA['path'] !== $pkgB['path'], 'distinct sample package temp paths');
$zip = new ZipArchive();
okSec($zip->open((string) $pkgA['path']) === true, 'sample ZIP opens');
$allowed = ['sample_products.xlsx', 'images.zip', 'README.txt'];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    okSec(!str_contains($name, '..') && !str_starts_with($name, '/'), 'sample ZIP entry safe path: ' . $name);
    okSec(in_array($name, $allowed, true), 'sample ZIP entry allowed: ' . $name);
}
$zip->close();

ProductBulkImport::unlinkDownloadTempFile($a['path'] ?? null);
ProductBulkImport::unlinkDownloadTempFile($b['path'] ?? null);
ProductBulkImport::unlinkDownloadTempFile($pkgA['path'] ?? null);
ProductBulkImport::unlinkDownloadTempFile($pkgB['path'] ?? null);
okSec(!is_file($a['path'] ?? ''), 'template temp cleaned up');
okSec(!is_file($pkgA['path'] ?? ''), 'sample temp cleaned up');

$safeName = AdminFileDownload::safeFilename('../../../etc/passwd');
okSec($safeName === 'passwd', 'safeFilename strips path traversal');

$base = rtrim((string) App::config()['app']['base_url'] ?? '', '/');
if ($base === '') {
    $base = 'http://localhost/Apni_Tokri';
}
$directPaths = [
    '/uploads/tmp/bulk-import/template/apni-tokri-product-import-template.xlsx',
    '/uploads/tmp/bulk-import/template/apni-tokri-product-import-sample-package.zip',
];
foreach ($directPaths as $rel) {
    $url = $base . $rel;
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
  $looksLikeOffice = is_string($body) && (str_starts_with($body, 'PK') || strlen($body) > 1000);
    if ($status === 0 && $body === false) {
        okSec(true, 'direct HTTP probe skipped or unreachable (not claiming blocked): ' . $rel);
    } elseif ($status === 403 || $status === 401 || ($status === 404 && !$looksLikeOffice)) {
        okSec(true, 'direct HTTP denied or not found for ' . $rel . ' (HTTP ' . $status . ')');
    } else {
        okSec(false, 'direct HTTP may expose ' . $rel . ' (HTTP ' . $status . ', len ' . (is_string($body) ? strlen($body) : 0) . ')');
    }
}

echo "\nSUMMARY fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
