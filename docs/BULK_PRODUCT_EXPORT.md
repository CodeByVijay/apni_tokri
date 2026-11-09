# Bulk product export

Admin: **Products → Bulk export** (`admin/products/bulk-export.php`). Complements [Bulk product import](BULK_PRODUCT_IMPORT.md).

Export is **read-only**. It does not update products, stock, media, or status.

## Workflow

1. Choose scope: **All products**, **Match filters** (search / category / status), or **Selected products** (from the product list → Export selected).
2. Choose format: **Excel only** or **Excel + Images ZIP**.
3. Review the live **product count** on the page (max **2000** per export).
4. Download.

## Workbook sheets

| Sheet | Notes |
|-------|--------|
| **Instructions** | Export-only guidance (`export_product_id`, `product_code`, no overwrite on import). |
| **Products** | Includes `export_product_id`, `status`, category name, descriptions. |
| **Variants** | Same columns as import. |
| **Attributes** | Same as import. |
| **Images** | Basenames only (safe names; shared files use one basename in ZIP). |
| **SEO** | `allow_indexing` yes/no from `robots_index`. |
| **Reference** | Published categories + active product types. |

`product_code` is `P-{product_id}` for export linking only (not stored in DB).

## Images ZIP (package download)

Outer ZIP contains:

- `apni-tokri-product-export.xlsx`
- `images.zip` (one entry per distinct file on disk; duplicates not repeated)
- `export-summary.txt` (counts, missing/unreadable files)

Only paths under `uploads/` are included. Internal server paths are never exposed. Missing files are listed in the summary, not as ZIP entries.

## Security

- `AdminDemoPerms::denyIfCannot('products')` on page and AJAX.
- CSRF on POST export and count preview (`admin/ajax/product-bulk-export.php`).
- Selected IDs validated against the `products` table.
- Downloads streamed via `AdminFileDownload` with `nosniff` (temp files under system temp, removed after send).

## Limits

- **2000** products per export (`ProductBulkExport::MAX_EXPORT`).
- Draft, published, and archived products are included when matched by scope/filters.
- No schema migration required.

## Testing (disposable DB only)

```powershell
& 'd:\xampp\php\php.exe' scripts/run-in-disposable-db.php scripts/test-product-bulk-export.php
```

Import regression (unchanged):

```powershell
& 'd:\xampp\php\php.exe' scripts/run-in-disposable-db.php --migrate-media-library scripts/test-product-bulk-import.php
```
