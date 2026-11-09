<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');

$base = url_base();

if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $dir = ProductBulkImport::jobDir('template');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $dir . '/apni-tokri-product-import-template.xlsx';
    $gen = ProductBulkImport::writeTemplate($tmp);
    if (empty($gen['ok']) || !is_file($tmp)) {
        http_response_code(500);
        echo 'Could not build template.';
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="apni-tokri-product-import-template.xlsx"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    exit;
}

$jobDownload = isset($_GET['download_report']) && (string) $_GET['download_report'] === '1'
    ? trim((string) ($_GET['job_id'] ?? ''))
    : '';
if ($jobDownload !== '') {
    $report = ProductBulkImport::jobDir($jobDownload) . '/import-report.csv';
    if (!is_file($report)) {
        http_response_code(404);
        echo 'Report not found.';
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="product-import-report-' . preg_replace('/[^a-z0-9_-]/i', '', $jobDownload) . '.csv"');
    readfile($report);
    exit;
}

admin_layout_start(['pageTitle' => 'Bulk product import', 'activeAdminNav' => 'products']);
$pageHeading = 'Bulk product import';
$pageDescription = 'Import products using the same fields as the Add Product form (General, Variants, Attributes, Images, SEO).'
    . ' All imports are saved as Draft; promotional flags are forced off.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/admin/products/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Back to products</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$ajaxUrl = $base . '/admin/ajax/product-bulk-import.php';
$csrf = Csrf::token();
?>
<div class="at-admin-card mb-4" id="at-bulk-import-panel">
    <div class="at-admin-card__body space-y-4">
        <ol class="text-sm list-decimal pl-5 space-y-1">
            <li><a class="at-link" href="<?= Security::escape($base) ?>/admin/products/bulk-import.php?download_template=1">Download template</a> (read the <strong>Instructions</strong> sheet first).</li>
            <li>Fill Products, Variants, Attributes, Images (optional), and SEO. Use <code>product_code</code> to link rows; it is not stored in the database.</li>
            <li>If the Images sheet lists filenames, upload a ZIP containing those files (basenames must match). Products without image rows do not require a ZIP.</li>
            <li>Upload, validate, preview, then import. Partial import runs when some products have errors.</li>
        </ol>
        <p class="text-sm at-muted mb-0">Every imported product is saved as <strong>draft</strong>. Promotional flags (featured, bestseller, new arrival) are always off — they are not in the template.</p>
        <form id="at-bulk-import-form" class="space-y-3" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= Security::escape($csrf) ?>">
            <div>
                <label class="at-label" for="workbook">Workbook (XLSX)</label>
                <input type="file" id="workbook" name="workbook" class="at-input at-focusable" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>
            <div>
                <label class="at-label" for="images_zip">Images ZIP (required only when Images sheet lists files)</label>
                <input type="file" id="images_zip" name="images_zip" class="at-input at-focusable" accept=".zip,application/zip">
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="at-btn at-btn--secondary at-focusable" id="at-bulk-import-validate">Validate &amp; preview</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" id="at-bulk-import-run" disabled>Import valid products</button>
            </div>
        </form>
        <div id="at-bulk-import-status" class="text-sm" role="status" aria-live="polite"></div>
        <div id="at-bulk-import-errors" class="hidden"></div>
        <div id="at-bulk-import-preview" class="hidden space-y-2"></div>
        <div id="at-bulk-import-result" class="hidden"></div>
    </div>
</div>
<script>
(function () {
  const ajaxUrl = <?= json_encode($ajaxUrl, JSON_THROW_ON_ERROR) ?>;
  const csrf = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
  const form = document.getElementById('at-bulk-import-form');
  const statusEl = document.getElementById('at-bulk-import-status');
  const errEl = document.getElementById('at-bulk-import-errors');
  const previewEl = document.getElementById('at-bulk-import-preview');
  const resultEl = document.getElementById('at-bulk-import-result');
  const btnValidate = document.getElementById('at-bulk-import-validate');
  const btnImport = document.getElementById('at-bulk-import-run');
  let jobId = '';

  function setStatus(msg, kind) {
    statusEl.textContent = msg;
    statusEl.className = 'text-sm' + (kind === 'error' ? ' text-red-700' : kind === 'success' ? ' text-green-800' : '');
  }

  function renderErrors(errors) {
    if (!errors || !errors.length) {
      errEl.classList.add('hidden');
      errEl.innerHTML = '';
      return;
    }
    errEl.classList.remove('hidden');
    const tbl = document.createElement('table');
    tbl.className = 'at-table text-sm w-full';
    tbl.innerHTML = '<thead><tr><th>Sheet</th><th>Row</th><th>Product code</th><th>Reason</th></tr></thead>';
    const tb = document.createElement('tbody');
    errors.forEach(function (e) {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td>' + escapeHtml(e.sheet || '') + '</td><td>' + escapeHtml(e.row || '') + '</td><td>' + escapeHtml(e.product_code || '') + '</td><td>' + escapeHtml(e.reason || '') + '</td>';
      tb.appendChild(tr);
    });
    tbl.appendChild(tb);
    errEl.innerHTML = '';
    errEl.appendChild(tbl);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }

  function renderPreview(products, ready) {
    if (!products || !products.length) {
      previewEl.classList.add('hidden');
      return;
    }
    previewEl.classList.remove('hidden');
    previewEl.innerHTML = '<p class="text-sm font-medium">' + ready + ' product(s) ready to import.</p>';
    products.forEach(function (p) {
      const details = document.createElement('details');
      details.className = 'at-admin-card';
      const sum = document.createElement('summary');
      sum.className = 'at-admin-card__body cursor-pointer font-medium';
      sum.textContent = p.product_code + ' — ' + (p.general && p.general.name ? p.general.name : '');
      details.appendChild(sum);
      const body = document.createElement('div');
      body.className = 'at-admin-card__body text-sm border-t space-y-2';
      body.innerHTML = '<p><strong>General</strong>: ' + escapeHtml(JSON.stringify(p.general)) + '</p>'
        + '<p><strong>Variants</strong>: ' + escapeHtml(JSON.stringify(p.variants)) + '</p>'
        + '<p><strong>Attributes</strong>: ' + escapeHtml(JSON.stringify(p.attributes)) + '</p>'
        + '<p><strong>Images</strong>: ' + escapeHtml(JSON.stringify(p.images)) + '</p>'
        + '<p><strong>SEO</strong>: ' + escapeHtml(JSON.stringify(p.seo)) + '</p>';
      details.appendChild(body);
      previewEl.appendChild(details);
    });
  }

  btnValidate.addEventListener('click', function () {
    const wb = document.getElementById('workbook');
    if (!wb.files.length) {
      setStatus('Select a workbook first.', 'error');
      return;
    }
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('csrf', csrf);
    fd.append('workbook', wb.files[0]);
    const zipInput = document.getElementById('images_zip');
    if (zipInput.files.length) {
      fd.append('images_zip', zipInput.files[0]);
    }
    setStatus('Validating…');
    btnValidate.disabled = true;
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btnValidate.disabled = false;
        if (!data || !data.ok) {
          setStatus((data && data.message) || 'Validation failed.', 'error');
          return;
        }
        jobId = data.data && data.data.job_id ? data.data.job_id : '';
        const v = data.data && data.data.validation ? data.data.validation : {};
        setStatus(data.message || 'Validation complete.', v.ok ? 'success' : 'error');
        renderErrors(v.errors || []);
        renderPreview(v.products || [], v.ready_count || 0);
        btnImport.disabled = !jobId || !(v.ready_count > 0);
      })
      .catch(function () {
        btnValidate.disabled = false;
        setStatus('Network error during validation.', 'error');
      });
  });

  btnImport.addEventListener('click', function () {
    if (!jobId) return;
    if (!window.confirm('Import valid products? Existing products are never updated.')) return;
    const fd = new FormData();
    fd.append('action', 'import');
    fd.append('csrf', csrf);
    fd.append('job_id', jobId);
    setStatus('Importing…');
    btnImport.disabled = true;
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setStatus((data && data.message) || 'Import failed.', 'error');
          btnImport.disabled = false;
          return;
        }
        const res = data.data && data.data.result ? data.data.result : {};
        setStatus(data.message || 'Import finished.', 'success');
        resultEl.classList.remove('hidden');
        let html = '<p>Imported: ' + (res.imported || 0) + ', failed: ' + (res.failed || 0) + '.</p>';
        if (data.data.report_url) {
          html += '<p><a class="at-link" href="' + escapeHtml(data.data.report_url) + '">Download result report (CSV)</a></p>';
        }
        resultEl.innerHTML = html;
      })
      .catch(function () {
        setStatus('Network error during import.', 'error');
        btnImport.disabled = false;
      });
  });
})();
</script>
<?php
admin_layout_end();
