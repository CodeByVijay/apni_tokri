<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/_init.php';

AdminDemoPerms::denyIfCannot('products');

$base = url_base();

if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $built = ProductBulkImport::prepareTemplateDownloadFile();
    if (empty($built['ok']) || empty($built['path'])) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Could not build template.';
        exit;
    }
    $tmpPath = (string) $built['path'];
    register_shutdown_function(static function () use ($tmpPath): void {
        ProductBulkImport::unlinkDownloadTempFile($tmpPath);
    });
    AdminFileDownload::streamAttachment(
        $tmpPath,
        AdminFileDownload::MIME_XLSX,
        ProductBulkImport::DOWNLOAD_TEMPLATE_NAME
    );
}

if (isset($_GET['download_sample_package']) && (string) $_GET['download_sample_package'] === '1') {
    $built = ProductBulkImport::prepareSamplePackageDownloadFile();
    if (empty($built['ok']) || empty($built['path'])) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Could not build sample package.';
        exit;
    }
    $tmpPath = (string) $built['path'];
    register_shutdown_function(static function () use ($tmpPath): void {
        ProductBulkImport::unlinkDownloadTempFile($tmpPath);
    });
    AdminFileDownload::streamAttachment(
        $tmpPath,
        AdminFileDownload::MIME_ZIP,
        ProductBulkImport::DOWNLOAD_SAMPLE_PACKAGE_NAME
    );
}

$jobDownload = isset($_GET['download_report']) && (string) $_GET['download_report'] === '1'
    ? trim((string) ($_GET['job_id'] ?? ''))
    : '';
if ($jobDownload !== '') {
    $safeJob = preg_replace('/[^a-z0-9_-]/i', '', $jobDownload) ?? '';
    if ($safeJob === '' || $safeJob !== $jobDownload) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid job.';
        exit;
    }
    $report = ProductBulkImport::jobDir($safeJob) . '/import-report.csv';
    AdminFileDownload::streamAttachment(
        $report,
        AdminFileDownload::MIME_CSV,
        'product-import-report-' . $safeJob . '.csv'
    );
}

admin_layout_start(['pageTitle' => 'Bulk product import', 'activeAdminNav' => 'products']);
$pageHeading = 'Bulk product import';
$pageDescription = 'Import products using the same fields as the Add Product form (General, Variants, Attributes, Images, SEO).'
    . ' All imports are saved as Draft; promotional flags are forced off.';
$pageActionsHtml = '<a href="' . Security::escape($base) . '/admin/products/index.php" class="at-btn at-btn--secondary at-btn--sm at-focusable">Back to products</a>';
require APNI_TOKRI_ROOT . '/includes/partials/admin/page-header.php';
$ajaxUrl = $base . '/admin/ajax/product-bulk-import.php';
$csrf = Csrf::token();
$missingImagesZipMessage = ProductBulkImport::MSG_MISSING_IMAGES_ZIP;
?>
<div class="grid gap-4 md:grid-cols-2 mb-4">
    <div class="at-admin-card h-full">
        <div class="at-admin-card__body space-y-2">
            <h2 class="text-base font-semibold m-0">Filled sample package</h2>
            <p class="text-sm at-muted m-0">Ready-made Peeled Garlic and Chopped Onion demo data (2 products, 3 variants each) so you can try validate and preview before using your own catalogue.</p>
            <p class="text-sm font-medium m-0 mb-0">The downloaded sample package ZIP contains:</p>
            <ul class="text-sm list-disc pl-5 space-y-1 m-0">
                <li><code>sample_products.xlsx</code> — filled workbook to upload below</li>
                <li><code>images.zip</code> — product images referenced on the Images sheet (extract and upload in the Images ZIP field)</li>
                <li><code>README.txt</code> — short usage notes</li>
            </ul>
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
            <li>Download the <strong>sample package</strong>. Open <code>sample_products.xlsx</code> to learn the format (read the Instructions sheet), and keep <code>images.zip</code> from the same download for step 5 when you try the demo.</li>
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
            <?= Csrf::field() ?>
            <div>
                <label class="at-label" for="workbook">Workbook (XLSX)</label>
                <input type="file" id="workbook" name="workbook" class="at-input at-focusable" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>
            <div>
                <label class="at-label" for="images_zip">Images ZIP (required only when Images sheet lists files)</label>
                <p class="text-sm at-muted m-0 mt-1" id="at-bulk-import-zip-hint">For the sample workbook, upload the <code>images.zip</code> included inside the downloaded sample package.</p>
                <input type="file" id="images_zip" name="images_zip" class="at-input at-focusable mt-2" accept=".zip,application/zip">
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" class="at-btn at-btn--secondary at-focusable" id="at-bulk-import-validate">Validate &amp; preview</button>
                <button type="button" class="at-btn at-btn--primary at-focusable" id="at-bulk-import-run" disabled>Import valid products</button>
            </div>
        </form>
        <div id="at-bulk-import-status" class="text-sm" role="status" aria-live="polite"></div>
        <div id="at-bulk-import-results" class="hidden space-y-4" aria-live="polite"></div>
        <div id="at-bulk-import-result" class="hidden"></div>
    </div>
</div>
<script>
(function () {
  const ajaxUrl = <?= json_encode($ajaxUrl, JSON_THROW_ON_ERROR) ?>;
  const csrf = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
  const form = document.getElementById('at-bulk-import-form');
  const statusEl = document.getElementById('at-bulk-import-status');
  const resultsEl = document.getElementById('at-bulk-import-results');
  const resultEl = document.getElementById('at-bulk-import-result');
  const btnValidate = document.getElementById('at-bulk-import-validate');
  const btnImport = document.getElementById('at-bulk-import-run');
  const csrfFieldName = <?= json_encode(Csrf::fieldName(), JSON_THROW_ON_ERROR) ?>;
  const missingImagesZipMessage = <?= json_encode($missingImagesZipMessage, JSON_THROW_ON_ERROR) ?>;
  const SHEET = {
    products: <?= json_encode(ProductBulkImport::SHEET_PRODUCTS, JSON_THROW_ON_ERROR) ?>,
    variants: <?= json_encode(ProductBulkImport::SHEET_VARIANTS, JSON_THROW_ON_ERROR) ?>,
    attributes: <?= json_encode(ProductBulkImport::SHEET_ATTRIBUTES, JSON_THROW_ON_ERROR) ?>,
    images: <?= json_encode(ProductBulkImport::SHEET_IMAGES, JSON_THROW_ON_ERROR) ?>,
    seo: <?= json_encode(ProductBulkImport::SHEET_SEO, JSON_THROW_ON_ERROR) ?>,
  };
  let jobId = '';
  let lastReadyCount = 0;
  let lastTotalProducts = 0;

  function validationHasMissingImagesZip(errors) {
    return Array.isArray(errors) && errors.some(function (e) {
      return e && e.reason === missingImagesZipMessage;
    });
  }

  function ajaxEnvelope(body) {
    if (!body || body.success !== true) {
      return {
        ok: false,
        message: (body && body.error && body.error.message) || (body && body.message) || 'Request failed.',
        code: body && body.error ? body.error.code : '',
      };
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

  function setStatus(msg, kind) {
    statusEl.textContent = msg;
    statusEl.className = 'text-sm' + (kind === 'error' ? ' text-red-700' : kind === 'success' ? ' text-green-800' : '');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
    });
  }

  function renderErrorMsgs(messages) {
    if (!messages || !messages.length) {
      return '';
    }
    return messages.map(function (m) {
      return '<p class="at-bulk-import-msg-error" role="alert"><span aria-hidden="true">⚠</span><span>' + escapeHtml(m) + '</span></p>';
    }).join('');
  }

  function errorsForProduct(code, allErrors, product) {
    const out = { general: [], variants: {}, attributes: {}, images: {}, seo: [], productVariants: [] };
    (allErrors || []).forEach(function (e) {
      if (!e) {
        return;
      }
      const errCode = e.product_code || '';
      const sheet = e.sheet || '';
      const row = e.row || '';
      const reason = e.reason || '';
      if (errCode === code) {
        if (sheet === SHEET.products) {
          out.general.push(reason);
        } else if (sheet === SHEET.variants) {
          if (row === '') {
            out.productVariants.push(reason);
          } else {
            if (!out.variants[row]) {
              out.variants[row] = [];
            }
            out.variants[row].push(reason);
          }
        } else if (sheet === SHEET.attributes) {
          if (!out.attributes[row]) {
            out.attributes[row] = [];
          }
          out.attributes[row].push(reason);
        } else if (sheet === SHEET.images) {
          if (row === '') {
            if (!out.images._product) {
              out.images._product = [];
            }
            out.images._product.push(reason);
          } else {
            if (!out.images[row]) {
              out.images[row] = [];
            }
            out.images[row].push(reason);
          }
        } else if (sheet === SHEET.seo) {
          out.seo.push(reason);
        }
      } else if (errCode === '' && sheet === SHEET.images && reason === missingImagesZipMessage && product && product.images && product.images.length) {
        if (!out.images._product) {
          out.images._product = [];
        }
        if (out.images._product.indexOf(reason) === -1) {
          out.images._product.push(reason);
        }
      }
    });
    return out;
  }

  function globalErrors(allErrors, products) {
    const codes = {};
    (products || []).forEach(function (p) {
      codes[p.product_code] = true;
    });
    return (allErrors || []).filter(function (e) {
      if (!e || (e.product_code || '') !== '') {
        return false;
      }
      if ((e.sheet || '') === SHEET.images && e.reason === missingImagesZipMessage) {
        return false;
      }
      return true;
    });
  }

  function updateImportButton() {
    if (lastReadyCount > 0) {
      btnImport.textContent = 'Import ' + lastReadyCount + ' ready product' + (lastReadyCount === 1 ? '' : 's');
      btnImport.disabled = !jobId;
    } else {
      btnImport.textContent = 'Import valid products';
      btnImport.disabled = true;
    }
  }

  function renderValidationResults(validation) {
    const products = validation.products || [];
    const errors = validation.errors || [];
    const summary = validation.summary || {
      total_products: products.length,
      ready_count: validation.ready_count || 0,
      products_with_errors: Math.max(0, products.length - (validation.ready_count || 0)),
      error_count: errors.length,
      variant_error_count: 0,
    };
    lastReadyCount = summary.ready_count != null ? summary.ready_count : (validation.ready_count || 0);
    lastTotalProducts = summary.total_products != null ? summary.total_products : products.length;
    if (!products.length && !errors.length) {
      resultsEl.classList.add('hidden');
      resultsEl.innerHTML = '';
      updateImportButton();
      return;
    }
    resultsEl.classList.remove('hidden');
    let html = '<div class="at-bulk-import-summary">';
    html += summaryCard('Total products', summary.total_products);
    html += summaryCard('Ready to import', summary.ready_count);
    html += summaryCard('Products with errors', summary.products_with_errors);
    html += summaryCard('Validation errors', summary.error_count);
    html += summaryCard('Variants with errors', summary.variant_error_count);
    html += '</div>';
    const skipped = Math.max(0, lastTotalProducts - lastReadyCount);
    if (skipped > 0 && lastReadyCount > 0) {
      html += '<p class="at-bulk-import-skip-note">' + skipped + ' product' + (skipped === 1 ? '' : 's') + ' will be skipped due to validation errors.</p>';
    }
    const globals = globalErrors(errors, products);
    if (globals.length) {
      html += '<div class="at-admin-card at-bulk-import-product--error"><div class="at-admin-card__body text-sm">';
      html += '<p class="font-medium m-0 mb-1">Workbook-wide issues</p>';
      html += renderErrorMsgs(globals.map(function (g) { return g.reason || ''; }));
      html += '</div></div>';
    }
    html += '<div class="space-y-3">';
    products.forEach(function (p) {
      const ready = p.import_ready === true;
      const name = (p.general && p.general.name) ? p.general.name : '(Unnamed)';
      const variantCount = (p.variants && p.variants.length) || 0;
      const errCount = p.error_count != null ? p.error_count : 0;
      const grouped = errorsForProduct(p.product_code, errors, p);
      const badge = ready
        ? '<span class="at-badge at-badge--success">Ready to import</span>'
        : '<span class="at-badge at-badge--danger">Has errors</span>';
      const cardClass = ready ? 'at-bulk-import-product--ready' : 'at-bulk-import-product--error';
      html += '<details class="at-bulk-import-product ' + cardClass + '"' + (ready ? '' : ' open') + '>';
      html += '<summary class="at-bulk-import-product__summary">';
      html += '<p class="at-bulk-import-product__title">' + escapeHtml(name) + '</p>';
      html += badge;
      html += '<p class="at-bulk-import-product__meta">Code: <code>' + escapeHtml(p.product_code) + '</code> · ' + variantCount + ' variant' + (variantCount === 1 ? '' : 's') + ' · ' + errCount + ' error' + (errCount === 1 ? '' : 's') + '</p>';
      html += '</summary>';
      html += '<div class="at-bulk-import-product__body">';
      html += sectionGeneral(p, grouped);
      html += sectionVariants(p, grouped);
      html += sectionAttributes(p, grouped);
      html += sectionImages(p, grouped);
      html += sectionSeo(p, grouped);
      html += '</div></details>';
    });
    html += '</div>';
    resultsEl.innerHTML = html;
    updateImportButton();
  }

  function summaryCard(label, value) {
    return '<div class="at-bulk-import-summary__card"><p class="at-bulk-import-summary__label">' + escapeHtml(label) + '</p>'
      + '<p class="at-bulk-import-summary__value">' + escapeHtml(String(value != null ? value : '—')) + '</p></div>';
  }

  function sectionGeneral(p, grouped) {
    const g = p.general || {};
    let html = '<div class="at-bulk-import-section"><p class="at-bulk-import-section__title">General</p>';
    html += '<dl class="at-bulk-import-dl">';
    html += dlRow('Name', g.name); html += dlRow('SKU', g.sku); html += dlRow('Slug', g.slug);
    html += dlRow('Category', g.category); html += dlRow('Type', g.product_type);
    html += dlRow('Short description', g.short_description);
    html += dlRow('Full description', g.description);
    html += '</dl>';
    html += renderErrorMsgs(grouped.general);
    html += '</div>';
    return html;
  }

  function dlRow(label, value) {
    if (value == null || value === '') {
      return '';
    }
    return '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(String(value)) + '</dd>';
  }

  function sectionVariants(p, grouped) {
    const variants = p.variants || [];
    let html = '<div class="at-bulk-import-section"><p class="at-bulk-import-section__title">Variants</p>';
    if (!variants.length) {
      html += '<p class="text-sm at-muted m-0">No variant rows.</p>';
    }
    variants.forEach(function (v) {
      const label = (v.name || 'Variant') + (v.sku ? ' — ' + v.sku : '');
      const rowKey = v.sheet_row || '';
      const errs = (rowKey && grouped.variants[rowKey]) ? grouped.variants[rowKey] : [];
      html += '<div class="at-bulk-import-variant"><strong>' + escapeHtml(label) + '</strong>';
      html += ' <span class="at-muted">(row ' + escapeHtml(rowKey || '—') + ')</span>';
      if (v.price) {
        html += '<span class="at-muted"> · ₹' + escapeHtml(v.price) + '</span>';
      }
      html += renderErrorMsgs(errs);
      html += '</div>';
    });
    html += renderErrorMsgs(grouped.productVariants);
    html += '</div>';
    return html;
  }

  function sectionAttributes(p, grouped) {
    const attrs = p.attributes || [];
    let html = '<div class="at-bulk-import-section"><p class="at-bulk-import-section__title">Attributes</p>';
    if (!attrs.length) {
      html += '<p class="text-sm at-muted m-0">No attribute rows.</p>';
    }
    attrs.forEach(function (a) {
      const rowKey = a.sheet_row || '';
      const errs = (rowKey && grouped.attributes[rowKey]) ? grouped.attributes[rowKey] : [];
      html += '<div class="at-bulk-import-variant"><span>' + escapeHtml((a.label || '') + ': ' + (a.value || '')) + '</span>';
      html += renderErrorMsgs(errs);
      html += '</div>';
    });
    html += '</div>';
    return html;
  }

  function sectionImages(p, grouped) {
    const images = p.images || [];
    let html = '<div class="at-bulk-import-section"><p class="at-bulk-import-section__title">Images</p>';
    if (!images.length) {
      html += '<p class="text-sm at-muted m-0">No image rows.</p>';
    }
    images.forEach(function (img) {
      const rowKey = img.sheet_row || '';
      const errs = [];
      if (rowKey && grouped.images[rowKey]) {
        errs.push.apply(errs, grouped.images[rowKey]);
      }
      html += '<div class="at-bulk-import-image-row"><code>' + escapeHtml(img.filename || '') + '</code>';
      if (img.is_primary) {
        html += ' <span class="at-muted">· primary=' + escapeHtml(img.is_primary) + '</span>';
      }
      html += renderErrorMsgs(errs);
      html += '</div>';
    });
    if (grouped.images._product) {
      html += renderErrorMsgs(grouped.images._product);
    }
    html += '</div>';
    return html;
  }

  function sectionSeo(p, grouped) {
    const seo = p.seo || {};
    let html = '<div class="at-bulk-import-section"><p class="at-bulk-import-section__title">SEO</p>';
    html += '<dl class="at-bulk-import-dl">';
    html += dlRow('Meta title', seo.meta_title);
    html += dlRow('Meta description', seo.meta_description);
    html += dlRow('Allow indexing', seo.allow_indexing);
    html += '</dl>';
    html += renderErrorMsgs(grouped.seo);
    html += '</div>';
    return html;
  }

  btnValidate.addEventListener('click', function () {
    const wb = document.getElementById('workbook');
    if (!wb.files.length) {
      setStatus('Select a workbook first.', 'error');
      return;
    }
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append(csrfFieldName, csrf);
    fd.append('workbook', wb.files[0]);
    const zipInput = document.getElementById('images_zip');
    if (zipInput.files.length) {
      fd.append('images_zip', zipInput.files[0]);
    }
    setStatus('Validating…');
    btnValidate.disabled = true;
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(parseJsonResponse)
      .then(function (res) {
        btnValidate.disabled = false;
        const parsed = ajaxEnvelope(res.body);
        if (!parsed.ok) {
          if (res.status === 403) {
            setStatus(parsed.message || 'Session expired or invalid CSRF token. Refresh the page and sign in again.', 'error');
          } else {
            setStatus(parsed.message || 'Validation failed.', 'error');
          }
          return;
        }
        jobId = parsed.data.job_id ? parsed.data.job_id : '';
        const v = parsed.data.validation ? parsed.data.validation : {};
        if (!v.ok && validationHasMissingImagesZip(v.errors)) {
          setStatus(missingImagesZipMessage, 'error');
        } else {
          setStatus(parsed.message || 'Validation complete.', v.ok ? 'success' : 'error');
        }
        renderValidationResults(v);
        if (!jobId || !(v.ready_count > 0)) {
          btnImport.disabled = true;
        }
      })
      .catch(function () {
        btnValidate.disabled = false;
        setStatus('Network error during validation.', 'error');
      });
  });

  btnImport.addEventListener('click', function () {
    if (!jobId) return;
    const skipped = Math.max(0, lastTotalProducts - lastReadyCount);
    let confirmMsg = 'Import ' + lastReadyCount + ' ready product' + (lastReadyCount === 1 ? '' : 's') + '?';
    if (skipped > 0) {
      confirmMsg += ' ' + skipped + ' product' + (skipped === 1 ? '' : 's') + ' with errors will be skipped.';
    }
    confirmMsg += ' Existing products are never updated.';
    if (!window.confirm(confirmMsg)) return;
    const fd = new FormData();
    fd.append('action', 'import');
    fd.append(csrfFieldName, csrf);
    fd.append('job_id', jobId);
    setStatus('Importing…');
    btnImport.disabled = true;
    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(parseJsonResponse)
      .then(function (res) {
        const parsed = ajaxEnvelope(res.body);
        if (!parsed.ok) {
          setStatus(parsed.message || 'Import failed.', 'error');
          btnImport.disabled = false;
          return;
        }
        const imp = parsed.data.result ? parsed.data.result : {};
        setStatus(parsed.message || 'Import finished.', 'success');
        resultEl.classList.remove('hidden');
        let html = '<p>Imported: ' + (imp.imported || 0) + ', failed: ' + (imp.failed || 0) + '.</p>';
        if (parsed.data.report_url) {
          html += '<p><a class="at-link" href="' + escapeHtml(parsed.data.report_url) + '">Download result report (CSV)</a></p>';
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
