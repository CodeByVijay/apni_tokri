<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');

$base = url_base();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'export') {
        $validated = ProductBulkExport::validateAndNormalizeExportFilters([
            'scope' => (string) ($_POST['scope'] ?? ProductBulkExport::SCOPE_FILTERED),
            'query' => (string) ($_POST['query'] ?? ''),
            'category' => (string) ($_POST['category'] ?? ''),
            'status' => (string) ($_POST['status'] ?? ''),
            'product_ids' => is_array($_POST['product_ids'] ?? null) ? $_POST['product_ids'] : [],
            'include_images' => (string) ($_POST['export_format'] ?? 'xlsx') === 'package',
        ]);
        if (empty($validated['ok']) || empty($validated['filters'])) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo (string) ($validated['message'] ?? 'Invalid export request.');
            exit;
        }
        $filters = $validated['filters'];
        if ($filters['scope'] === ProductBulkExport::SCOPE_SELECTED && $filters['product_ids'] === []) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Select at least one product.';
            exit;
        }
        $built = ProductBulkExport::prepareDownloadFile($filters);
        if (empty($built['ok']) || empty($built['path'])) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo (string) ($built['message'] ?? 'Export failed.');
            exit;
        }
        $tmpPath = (string) $built['path'];
        register_shutdown_function(static function () use ($tmpPath): void {
            ProductBulkExport::unlinkDownloadTempFile($tmpPath);
        });
        AdminFileDownload::streamAttachment(
            $tmpPath,
            (string) ($built['mime'] ?? AdminFileDownload::MIME_XLSX),
            (string) ($built['filename'] ?? ProductBulkExport::DOWNLOAD_XLSX_NAME)
        );
    }
}

$scope = ProductBulkExport::SCOPE_FILTERED;
$filterQ = '';
$filterCat = '';
$filterStatus = '';
$getValidated = ProductBulkExport::validateAndNormalizeExportFilters([
    'scope' => (string) ($_GET['scope'] ?? ProductBulkExport::SCOPE_FILTERED),
    'query' => (string) ($_GET['q'] ?? ''),
    'category' => (string) ($_GET['category'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
]);
if (!empty($getValidated['ok']) && !empty($getValidated['filters'])) {
    $scope = $getValidated['filters']['scope'];
    $filterQ = $getValidated['filters']['query'];
    $filterCat = $getValidated['filters']['category'];
    $filterStatus = $getValidated['filters']['status'];
}

$prefillIds = [];
if (isset($_GET['product_ids']) && is_string($_GET['product_ids'])) {
    foreach (explode(',', $_GET['product_ids']) as $part) {
        $n = (int) trim($part);
        if ($n > 0) {
            $prefillIds[] = $n;
        }
    }
    $prefillIds = ProductRepository::filterExistingProductIds($prefillIds);
    if ($prefillIds !== []) {
        $scope = ProductBulkExport::SCOPE_SELECTED;
    }
}

admin_layout_start(['pageTitle' => 'Bulk product export', 'activeAdminNav' => 'products']);
$pageHeading = 'Bulk product export';
$pageDescription = 'Download catalogue data in the same workbook shape as Bulk Import. Export is read-only and does not change products.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/admin/products/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Back to products</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';

$useDb = Install::isSchemaReady();
$csrf = Csrf::token();
$ajaxUrl = $base . '/admin/ajax/product-bulk-export.php';
?>
<?php if (!$useDb): ?>
<div class="at-admin-card mb-4"><div class="at-admin-card__body"><p class="text-sm at-muted mb-0">Database required for export.</p></div></div>
<?php else: ?>
<div class="at-admin-card mb-4">
    <div class="at-admin-card__body space-y-4">
        <form id="at-bulk-export-form" method="post" class="space-y-4">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="export">
            <?php foreach ($prefillIds as $pid): ?>
                <input type="hidden" name="product_ids[]" value="<?= (int) $pid ?>">
            <?php endforeach; ?>

            <fieldset class="space-y-2">
                <legend class="at-label font-semibold">Products to export</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="scope" value="<?= Security::escape(ProductBulkExport::SCOPE_ALL) ?>"<?= $scope === ProductBulkExport::SCOPE_ALL ? ' checked' : '' ?>>
                    All products <span class="at-muted">(up to <?= (int) ProductBulkExport::MAX_EXPORT ?>)</span>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="scope" value="<?= Security::escape(ProductBulkExport::SCOPE_FILTERED) ?>"<?= $scope === ProductBulkExport::SCOPE_FILTERED ? ' checked' : '' ?>>
                    Match filters below
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="scope" value="<?= Security::escape(ProductBulkExport::SCOPE_SELECTED) ?>"<?= $scope === ProductBulkExport::SCOPE_SELECTED ? ' checked' : '' ?><?= $prefillIds === [] ? ' disabled' : '' ?>>
                    Selected products only <?= $prefillIds !== [] ? '(' . count($prefillIds) . ' from product list)' : '' ?>
                </label>
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-3" id="at-bulk-export-filters">
                <div>
                    <label class="at-label" for="export_q">Search</label>
                    <input id="export_q" name="query" class="at-input at-focusable" value="<?= Security::escape($filterQ) ?>" placeholder="Name, SKU, slug">
                </div>
                <div>
                    <label class="at-label" for="export_category">Category</label>
                    <select id="export_category" name="category" class="at-select at-focusable">
                        <option value="">All</option>
                        <?php foreach (CategoryRepository::allForAdmin() as $c): ?>
                            <option value="<?= Security::escape((string) $c['slug']) ?>"<?= $filterCat === ($c['slug'] ?? '') ? ' selected' : '' ?>><?= Security::escape((string) $c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="at-label" for="export_status">Status</label>
                    <select id="export_status" name="status" class="at-select at-focusable">
                        <option value="">All</option>
                        <?php foreach (['published', 'draft', 'archived'] as $st): ?>
                            <option value="<?= $st ?>"<?= $filterStatus === $st ? ' selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <fieldset class="space-y-2">
                <legend class="at-label font-semibold">Download format</legend>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="export_format" value="xlsx" checked>
                    Excel only (image filenames + metadata)
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="export_format" value="package">
                    Excel + Images ZIP (workbook, images.zip, export-summary.txt)
                </label>
            </fieldset>

            <p class="text-sm mb-0" role="status" aria-live="polite">
                <strong id="at-bulk-export-count">—</strong> product(s) will be exported
                <span id="at-bulk-export-cap" class="at-muted hidden"> (catalogue exceeds <?= (int) ProductBulkExport::MAX_EXPORT ?>; export is capped)</span>
            </p>

            <button type="submit" class="at-btn at-btn--primary at-focusable" id="at-bulk-export-submit">Download export</button>
        </form>
    </div>
</div>
<script>
(function () {
  const ajaxUrl = <?= json_encode($ajaxUrl, JSON_THROW_ON_ERROR) ?>;
  const csrf = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
  const form = document.getElementById('at-bulk-export-form');
  const countEl = document.getElementById('at-bulk-export-count');
  const capEl = document.getElementById('at-bulk-export-cap');
  const submitBtn = document.getElementById('at-bulk-export-submit');
  const csrfFieldName = <?= json_encode(Csrf::fieldName(), JSON_THROW_ON_ERROR) ?>;

  function ajaxEnvelope(body) {
    if (!body || body.success !== true) {
      return { ok: false, message: (body && body.error && body.error.message) || (body && body.message) || '' };
    }
    return { ok: true, data: body.data || {}, message: body.message || '' };
  }

  function parseJsonResponse(r) {
    return r.json().then(function (body) {
      return { httpOk: r.ok, status: r.status, body: body };
    }).catch(function () {
      return { httpOk: false, status: r.status, body: null };
    });
  }

  function refreshCount() {
    const fd = new FormData(form);
    fd.set('action', 'count');
    fd.set(csrfFieldName, csrf);
    if (fd.get('export_format') === 'package') {
      fd.set('include_images', '1');
    }
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(parseJsonResponse)
      .then(function (res) {
        const parsed = ajaxEnvelope(res.body);
        if (!parsed.ok) {
          countEl.textContent = res.status === 403 ? '—' : '0';
          submitBtn.disabled = true;
          return;
        }
        const n = parsed.data.count != null ? parsed.data.count : 0;
        countEl.textContent = String(n);
        submitBtn.disabled = n <= 0;
        if (parsed.data.capped) {
          capEl.classList.remove('hidden');
        } else {
          capEl.classList.add('hidden');
        }
      })
      .catch(function () {
        countEl.textContent = '—';
      });
  }

  form.addEventListener('change', refreshCount);
  form.addEventListener('input', function (e) {
    if (e.target && (e.target.name === 'query' || e.target.id === 'export_q')) {
      refreshCount();
    }
  });
  refreshCount();
})();
</script>
<?php endif; ?>
<?php
admin_layout_end();
