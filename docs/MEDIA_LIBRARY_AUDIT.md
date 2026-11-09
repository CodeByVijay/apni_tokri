# Media Library — Phase 1 architecture audit (2026-11-09)

## `product_images` table

| Column | Role |
|--------|------|
| `id` | Surrogate PK |
| `product_id` | FK → `products`, `ON DELETE CASCADE` |
| `file_path` | **Relative web path** (e.g. `uploads/products/{id}/{random}.webp`), **not** an absolute filesystem path |
| `sort_order` | Gallery order (0+) |
| `is_primary` | Main/hero image flag |
| `created_at` | Row timestamp |

**Indexes:** `idx_product_images_product` on `product_id`.  
**No** `alt_text`, `media_id`, or soft-delete columns today.

## Storage layout

- New uploads (pre–media library): `uploads/products/{productId}/{16-byte-hex}.{ext}` via `Upload::storeProductImage()`.
- Other uploads: `uploads/{subdir}/…` (CMS, refunds, reviews, categories, support).
- Random server filenames; MIME validated with `finfo`; max **5 MB**; product gallery allows **JPEG, PNG, WebP** only (no GIF).

## Admin product images

- **UI:** `includes/partials/admin/product-images-panel.php` + gallery item partial; **JS:** `assets/js/admin.js` → `initAdminProductImages()`.
- **AJAX:** `admin/ajax/product-images.php` — upload (multi), reorder, set primary; CSRF + `AdminDemoPerms::denyIfCannot('products')`.
- **Service:** `ProductImageAdminService::uploadBatch()` → `Upload::storeProductImage()` → `ProductRepository::addImage()`.
- **Limits:** `ProductRepository::MAX_IMAGES_PER_PRODUCT` = **10**.
- **Remove:** `ProductRepository::deleteImage()` deletes DB row **and** unlinks the file when `file_path` starts with `uploads/`.

## URL rendering (unchanged consumers)

All storefront/admin order flows resolve `file_path` through `asset_url($path)`:

- `ProductRepository::toPublicArray()` → product cards / PDP gallery
- `includes/commerce/cart.php`, `checkout.php`, `admin_orders.php`, refunds, subscriptions, SEO admin, etc.

Placeholder when no images: `assets/images/logo-placeholder.svg`.

## Auth / permissions

- Product image endpoints use module **`products`** (`AdminDemoPerms::denyIfCannot('products')`).
- CSRF on POST/AJAX via `Csrf::requireValid()` / `_csrf_token`.

## Gaps addressed by media library

1. Central catalog of assets with metadata (dimensions, size, uploader, alt).
2. Reuse one file across products via `media_id` (single physical file).
3. Detach from product without deleting shared asset.
4. Block delete when assets are referenced; soft-delete unused assets.
5. Legacy rows: `media_id` NULL, existing `file_path` and URLs unchanged.

## Soft-delete policy (hardened)

- Soft-deleted assets stay in `media_assets` with `deleted_at` set; **physical files are retained** until explicit purge.
- Re-uploading identical content (same SHA-256) while a soft-deleted row exists is **rejected** — not silently restored.
- Active duplicate SHA-256 uploads reuse the existing **active** row (`duplicate` flag); orphan temp files are removed.
- `UNIQUE (file_sha256)` enforces one hash per library (NULL hashes allowed for legacy edge cases).

## Schema decision

- Add **`media_assets`** table (canonical storage metadata).
- Add nullable **`product_images.media_id`** FK + optional **`product_images.alt_text`** (per-product override).
- Keep **`product_images.file_path`** populated for all rows so existing SQL/subqueries keep working without a join.
