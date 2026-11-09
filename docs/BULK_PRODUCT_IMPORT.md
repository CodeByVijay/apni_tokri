# Bulk product import

Admin path: **Products → Bulk import** (`admin/products/bulk-import.php`).

Uses the same persistence paths as **Add product** (`ProductRepository::saveGeneral`, `saveVariants`, `saveAttributes`, Media Library ingest + attach). No parallel product model.

## Workbook sheets

| Sheet | Purpose |
|-------|---------|
| **Instructions** | Import defaults, ZIP rules, `product_code` usage (read first). |
| **Products** | General tab fields (one row per product). |
| **Variants** | Variants & stock (many rows per `product_code`). |
| **Attributes** | Free-form `attribute_label` / `attribute_value` pairs (Attributes tab). |
| **Images** | Optional per product; see ZIP section below. |
| **SEO** | `meta_title`, `meta_description`, `allow_indexing` → `robots_index`. |
| **Reference** | Published categories and active product type codes (documentation only). |

### Products column mapping

| Column | Database / behaviour |
|--------|----------------------|
| `product_code` | Import key only — **not** stored |
| `product_name` | `products.name` |
| `slug` | `products.slug` (auto from name if empty) |
| `sku` | `products.sku` |
| `category` | `products.category_id` (published category **name** or **slug**) |
| `product_type` | `products.product_type` (active type **code**) |
| `short_description` | `products.short_description` |
| `full_description` | `products.description` |

**Not in template (fixed defaults):**

- `status` → always **`draft`**
- `is_featured`, `is_bestseller`, `is_new_arrival` → always **0**

### Variants column mapping

| Column | Database |
|--------|----------|
| `variant_name` | `product_variants.name` |
| `sku` | `product_variants.sku` |
| `price` / `sale_price` | `price` / `sale_price` |
| `stock_quantity` | `stock_qty` |
| `low_stock_threshold` | `low_stock_threshold` |
| `min_order_quantity` / `max_order_quantity` | `min_qty_per_order` / `max_qty_per_order` |
| `sort_order` | `sort_order` |

### Attributes

| Column | Database |
|--------|----------|
| `attribute_label` | `product_attributes.attr_key` |
| `attribute_value` | `product_attributes.attr_value` |
| `sort_order` | Insert order |

### Images

| Column | Behaviour |
|--------|-----------|
| `filename` | Basename only; must exist in ZIP when rows are present |
| `is_primary` | Primary product image |
| `sort_order` | Gallery order |
| `alt_text` | `product_images.alt_text` when Media Library is enabled |

### SEO

| Column | Database |
|--------|----------|
| `meta_title` | `products.meta_title` (≤ 70 chars in validation) |
| `meta_description` | `products.meta_description` (≤ 160 chars in validation) |
| `allow_indexing` | `products.robots_index` (yes/no) |

No `meta_keywords` — the product form does not use them.

## Images ZIP

- **Optional** when the **Images** sheet has no data rows (product can import without images).
- **Required** when any Images row lists a `filename`.
- Match by **basename** (case-insensitive); subfolders in the ZIP are flattened to basename only.
- Allowed types: **JPEG, PNG, WebP** — validated with MIME + `getimagesize()`.
- Max **5 MB** per image; max ZIP upload **120 MB**; max **600** entries; max **100 MB** total decompressed.
- Rejects `..`, absolute paths, drive prefixes, and unsafe filenames.
- Extracted files live under `uploads/tmp/bulk-import/{job_id}/` and are removed after import completes.

## Downloads (admin UI)

| Action | URL query | Contents |
|--------|-----------|----------|
| **Blank template** | `admin/products/bulk-import.php?download_template=1` | `apni-tokri-product-import-template.xlsx` — Instructions, Reference, one example row (`P-EXAMPLE`). |
| **Sample package** | `admin/products/bulk-import.php?download_sample_package=1` | ZIP: `sample_products.xlsx`, `images.zip`, `README.txt` — Peeled Garlic + Chopped Onion demo data (neutral demo images). |

Template and sample package downloads are built in the **PHP system temp directory** per request and streamed through `admin/products/bulk-import.php` (not left in a public URL).

Import **job** files still live under `uploads/tmp/bulk-import/{job_id}/` but must not be web-accessible (see Security below).

The **Instructions** sheet in both downloads documents every worksheet, required columns, example rows, ZIP rules, Reference usage, validation rules, and draft/promo defaults.

## Workflow

1. Download the **sample package** to learn the format; then download the **blank template** for real data.
2. Fill sheets; optionally build image ZIP (see Instructions).
3. Upload workbook (+ ZIP if Images sheet is used) → **Validate & preview**.
4. Fix errors (sheet, row, `product_code`, reason).
5. **Import valid products** → download CSV result report.

The bulk import page includes a step-by-step guide and collapsible FAQ for common questions (variants, attributes, images, SKU conflicts, draft status).

Permissions: admin auth + **products** (`AdminDemoPerms::denyIfCannot('products')`). CSRF on all POST actions.

## Validation rules (summary)

- Required: `product_name`, `category`, valid `product_type`.
- Unique in workbook: `product_code`, product SKU, variant SKU, slug (when set).
- No collision with existing product/variant SKUs or product slugs.
- Categories: published only. Product types: active codes only (see Reference sheet).
- Variant rows: same rules as `ProductAdminForm::validateVariantRows`.
- Images: ZIP required when referenced; files must exist and pass image validation; max 10 per product; one primary.
- SEO length limits as on the product form.

## Import behaviour

- **Never updates** existing products; SKU/slug conflicts are rejected at validation.
- Re-import of the same workbook fails on existing SKUs (safe repeat).
- **Partial import:** products with validation errors are skipped; others import.
- Per product: DB transaction for general + variants + attributes; images afterward (same pattern as “save product, then images”).
- On image failure after the product row exists: `ProductRepository::deleteOrArchiveForAdmin()` removes the product (cascades variants, attributes, `product_images` links). **Media Library assets are not deleted** (`ON DELETE RESTRICT` on `media_id` only prevents deleting media rows, not product cascade).
- Rollback failures are **logged** and surfaced in the import error message.

## Error report

CSV columns: `product_code`, `status` (`imported` / `failed`), `product_id`, `message`.

Download: `admin/products/bulk-import.php?download_report=1&job_id=…` after import.

## Temporary upload cleanup

- Job workspace: `uploads/tmp/bulk-import/{job_id}/` (workbook, ZIP, `state.json`, report).
- Extracted image directory is removed after import via `ProductBulkImport::cleanupJobAfterImport()`.
- Failed ZIP extraction cleans partial extract dirs.

## Download security

- **Authentication:** `admin/_init.php` → `AdminGuard::requireAuthentication()`; `AdminDemoPerms::denyIfCannot('products')` runs before any GET download handler.
- **CSRF:** Not required for read-only GET template/sample downloads; POST import actions remain CSRF-protected.
- **Response headers:** `Content-Disposition: attachment`, correct MIME type, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store` via `AdminFileDownload::streamAttachment()`.
- **Direct HTTP access to job data:** Block `uploads/tmp/` at the web server.

### Apache (XAMPP / cPanel)

Shipped `.htaccess` files:

- `uploads/tmp/.htaccess` — `Require all denied`
- `uploads/tmp/bulk-import/.htaccess` — `Require all denied`

Parent `uploads/.htaccess` only blocks PHP execution; it does **not** block `.xlsx` / `.zip` elsewhere under `uploads/`.

### Nginx (required operator config)

Nginx ignores `.htaccess`. Add something equivalent (adjust path prefix for your install):

```nginx
location ^~ /Apni_Tokri/uploads/tmp/ {
    deny all;
    return 404;
}
```

Do **not** deny `uploads/products/` or `uploads/media/` — those serve legitimate catalogue images.

Verify with an unauthenticated browser request to a job workbook path under `uploads/tmp/bulk-import/`.

```powershell
php scripts/test-bulk-import-download-security.php
```

## Deployment requirements

- Writable `uploads/tmp/bulk-import/`.
- PHP: `zip` extension (`ZipArchive`), `fileinfo` recommended.
- For Media Library image dedupe: migration **`1.37.0_media_library.sql`** applied.
- Max **500** products per workbook.

## Disposable testing (only)

Do **not** run against production or staging databases.

```powershell
# From project root (XAMPP — set empty root password if needed)
$env:APNI_TOKRI_DISPOSABLE_MYSQL_PASS = ''
php scripts/run-in-disposable-db.php --migrate-media-library scripts/test-product-bulk-import.php
```

Full media + bulk import gate:

```powershell
php scripts/run-phase4-media-library-verify.php
```

(Runs `test-product-bulk-import.php` with `--migrate-media-library` via the disposable runner.)

## Limitations

- `.xlsx` only (no `.xls` / CSV).
- Instructions/Reference sheets are not validated programmatically beyond data sheets.
- Orphan `media_assets` rows may remain after a failed image attach (library dedupe by SHA); they are not auto-purged.
- Job state and uploads are not auto-expired by cron; clean `uploads/tmp/bulk-import/` periodically on long-running servers.
