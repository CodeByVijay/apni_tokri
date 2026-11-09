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

if (isset($_GET['download_sample_package']) && (string) $_GET['download_sample_package'] === '1') {
    $dir = ProductBulkImport::jobDir('template');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $pkg = $dir . '/apni-tokri-product-import-sample-package.zip';
    $gen = ProductBulkImport::writeSamplePackage($pkg);
    if (empty($gen['ok']) || !is_file($pkg)) {
        http_response_code(500);
        echo 'Could not build sample package.';
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="apni-tokri-product-import-sample-package.zip"');
    header('Content-Length: ' . (string) filesize($pkg));
    readfile($pkg);
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
<div class="grid gap-4 md:grid-cols-2 mb-4">
    <div class="at-admin-card h-full">
        <div class="at-admin-card__body space-y-2">
            <h2 class="text-base font-semibold m-0">Filled sample package</h2>
            <p class="text-sm at-muted m-0">ZIP with <code>sample_products.xlsx</code>, <code>images.zip</code>, and <code>README.txt</code>. Includes Peeled Garlic and Chopped Onion demo data (2 products, 3 variants each).</p>
            <a href="<?= Security::escape($base) ?>/admin/products/bulk-import.php?download_sample_package=1" class="at-btn at-btn--primary at-btn--sm at-focusable">Download sample package</a>
        </div>
    </div>
    <div class="at-admin-card h-full">
        <div class="at-admin-card__body space-y-2">
            <h2 class="text-base font-semibold m-0">Blank Excel template</h2>
            <p class="text-sm at-muted m-0">Empty workbook with Instructions, Reference, and one example row. Use this for your real catalogue data.</p>
            <a href="<?= Security::escape($base) ?>/admin/products/bulk-import.php?download_template=1" class="at-btn at-btn--secondary at-btn--sm at-focusable">Download blank template</a>
        </div>
    </div>
</div>

<div class="at-admin-card mb-4" id="at-bulk-import-panel">
    <div class="at-admin-card__body space-y-4">
        <h2 class="text-base font-semibold m-0">Steps</h2>
        <ol class="text-sm list-decimal pl-5 space-y-1 mb-0">
            <li>Download the <strong>sample package</strong> and open the workbook to understand the format (read the Instructions sheet).</li>
            <li>Download the <strong>blank template</strong> for actual data entry.</li>
            <li>Fill Products, Variants, Attributes, Images (optional), and SEO. Repeat the same <code>product_code</code> on every sheet for one product — it is not stored in the database.</li>
            <li>If the Images sheet lists filenames, add those files to a ZIP (basename must match). Skip the ZIP when there are no image rows.</li>
            <li>Upload the workbook and optional images ZIP below.</li>
            <li>Click <strong>Validate &amp; preview</strong> and fix any errors shown in the table.</li>
            <li>Click <strong>Import valid products</strong>. Partial import imports rows that passed validation.</li>
        </ol>
        <p class="text-sm at-muted mb-0">Every imported product is saved as <strong>draft</strong>. Featured, bestseller, and new arrival are always off. Existing products are never overwritten.</p>

        <details class="at-admin-card border">
            <summary class="at-admin-card__body cursor-pointer font-medium text-sm">FAQ</summary>
            <dl class="at-admin-card__body text-sm space-y-3 border-t mb-0">
                <div>
                    <dt class="font-medium">How do I add multiple variants?</dt>
                    <dd class="at-muted m-0">Add one row per variant on the Variants sheet with the same <code>product_code</code>. Each variant needs its own <code>sku</code> and <code>variant_name</code>.</dd>
                </div>
                <div>
                    <dt class="font-medium">How do I add multiple attributes?</dt>
                    <dd class="at-muted m-0">Add one row per label/value pair on the Attributes sheet with the same <code>product_code</code>. Use <code>sort_order</code> for display order.</dd>
                </div>
                <div>
                    <dt class="font-medium">How do I link images?</dt>
                    <dd class="at-muted m-0">Put the filename (basename only) on the Images sheet, then include that file in your upload ZIP. Set <code>is_primary</code> to <code>yes</code> for one image per product.</dd>
                </div>
                <div>
                    <dt class="font-medium">Is the image ZIP mandatory?</dt>
                    <dd class="at-muted m-0">Only when the Images sheet has at least one data row. If you omit images entirely, no ZIP is required.</dd>
                </div>
                <div>
                    <dt class="font-medium">Can I import products without images?</dt>
                    <dd class="at-muted m-0">Yes. Leave the Images sheet empty for that product (or remove all image rows) and do not upload a ZIP unless another product references files.</dd>
                </div>
                <div>
                    <dt class="font-medium">What happens if SKU already exists?</dt>
                    <dd class="at-muted m-0">Validation fails for that product or variant. Import does not update existing catalogue rows.</dd>
                </div>
                <div>
                    <dt class="font-medium">Are imported products published automatically?</dt>
                    <dd class="at-muted m-0">No. All imports are saved as <strong>draft</strong>. Publish from the product editor when ready.</dd>
                </div>
            </dl>
        </details>
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
