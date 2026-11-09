<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');
if (!Install::isSchemaReady()) {
    Ajax::error('Database not available.', 503);
}

$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

if ($action === 'download_template') {
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Ajax::error('Method not allowed.', 405);
  }
  $tmp = ProductBulkImport::jobDir('template') . '/template.xlsx';
  $gen = ProductBulkImport::writeTemplate($tmp);
  if (empty($gen['ok'])) {
    Ajax::error((string) ($gen['message'] ?? 'Template failed.'));
  }
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="apni-tokri-product-import-template.xlsx"');
  header('Content-Length: ' . (string) filesize($tmp));
  readfile($tmp);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  Ajax::error('Method not allowed.', 405);
}
Csrf::requireValid();

if ($action === 'upload') {
  $jobId = ProductBulkImport::newJobId();
  $dir = ProductBulkImport::jobDir($jobId);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    Ajax::error('Could not create upload workspace.');
  }
  if (!isset($_FILES['workbook']) || !is_array($_FILES['workbook'])) {
    Ajax::error('Workbook file is required.');
  }
  $wb = $_FILES['workbook'];
  if (($wb['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Ajax::error('Workbook upload failed.');
  }
  if ((int) ($wb['size'] ?? 0) > ProductBulkImport::MAX_XLSX_BYTES) {
    Ajax::error('Workbook is too large.');
  }
  $destWb = $dir . '/workbook.xlsx';
  if (!move_uploaded_file((string) $wb['tmp_name'], $destWb)) {
    Ajax::error('Could not store workbook.');
  }
  $hasZip = isset($_FILES['images_zip']) && is_array($_FILES['images_zip'])
    && (int) ($_FILES['images_zip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
  $imageIndex = null;
  $extractDir = null;
  if ($hasZip) {
    $zip = $_FILES['images_zip'];
    if ((int) ($zip['size'] ?? 0) > ProductBulkImport::MAX_ZIP_BYTES) {
      Ajax::error('Images ZIP is too large.');
    }
    $destZip = $dir . '/images.zip';
    if (!move_uploaded_file((string) $zip['tmp_name'], $destZip)) {
      Ajax::error('Could not store images ZIP.');
    }
    $ex = ProductBulkImport::extractImageZip($destZip);
    if (empty($ex['ok'])) {
      ProductBulkImport::cleanupJobExtractDir(isset($ex['dir']) && is_string($ex['dir']) ? $ex['dir'] : null);
      Ajax::error((string) ($ex['message'] ?? 'Could not extract images ZIP.'));
    }
    $imageIndex = $ex['index'] ?? null;
    $extractDir = $ex['dir'] ?? null;
  }
  try {
    $parsed = ProductBulkImport::parseWorkbook($destWb);
  } catch (Throwable $e) {
    ProductBulkImport::cleanupJobExtractDir($extractDir);
    Ajax::error('Could not read workbook: ' . $e->getMessage());
  }
  $validation = ProductBulkImport::validate($parsed, is_array($imageIndex) ? $imageIndex : null);
  $bundles = ProductBulkImport::bundleMapFromParsed($parsed, []);
  ProductBulkImport::saveJobState($jobId, [
    'created_at' => time(),
    'workbook' => $destWb,
    'images_zip' => $hasZip ? ($dir . '/images.zip') : null,
    'images_dir' => $extractDir,
    'image_index' => $imageIndex,
    'validation' => $validation,
    'bundles' => $bundles,
  ]);
  Ajax::success([
    'job_id' => $jobId,
    'validation' => $validation,
  ], $validation['ok'] ? 'Validation passed.' : 'Validation completed with errors.');
}

if ($action === 'import') {
  $jobId = trim((string) ($_POST['job_id'] ?? ''));
  if ($jobId === '') {
    Ajax::error('Missing job_id.');
  }
  $state = ProductBulkImport::loadJobState($jobId);
  if ($state === null) {
    Ajax::error('Import job expired or not found. Upload again.');
  }
  $workbookPath = (string) ($state['workbook'] ?? '');
  if ($workbookPath === '' || !is_file($workbookPath)) {
    Ajax::error('Workbook missing. Upload and validate again.');
  }
  try {
    $parsed = ProductBulkImport::parseWorkbook($workbookPath);
  } catch (Throwable $e) {
    Ajax::error('Could not re-read workbook for import.');
  }
  $imageIndex = is_array($state['image_index'] ?? null) ? $state['image_index'] : null;
  $validation = ProductBulkImport::validate($parsed, $imageIndex);
  $errors = is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
  $bundles = ProductBulkImport::bundleMapFromParsed($parsed, []);
  if ($bundles === []) {
    Ajax::error('No products to import.');
  }
  $importable = ProductBulkImport::filterImportableBundles($errors, $bundles);
  $state['validation'] = $validation;
  $state['bundles'] = $bundles;
  if ($importable === []) {
    Ajax::error('No valid products to import. Fix validation errors first.');
  }
  $adminId = AdminAuth::check() ? AdminAuth::id() : null;
  $result = ProductBulkImport::importProducts($importable, $imageIndex, $adminId > 0 ? $adminId : null);
  $reportPath = ProductBulkImport::jobDir($jobId) . '/import-report.csv';
  ProductBulkImport::writeReportCsv($reportPath, $result['report_rows']);
  $state['import_result'] = $result;
  $state['report_path'] = $reportPath;
  ProductBulkImport::saveJobState($jobId, $state);
  ProductBulkImport::cleanupJobAfterImport($jobId, $state);
  $msg = 'Imported ' . $result['imported'] . ' product(s).';
  if ($result['failed'] > 0) {
    $msg .= ' ' . $result['failed'] . ' failed.';
  }
  Ajax::success([
    'job_id' => $jobId,
    'result' => $result,
    'report_url' => url_base() . '/admin/products/bulk-import.php?download_report=1&job_id=' . rawurlencode($jobId),
  ], $msg);
}

Ajax::error('Unknown action.');
