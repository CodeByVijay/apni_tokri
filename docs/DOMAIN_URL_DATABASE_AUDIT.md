# Domain URL database audit (read-only guidance)

Use after changing **Admin → Settings → Website URL**. Do not run bulk replace without review.

## Settings (`settings.setting_key`)

| Key | Classification |
|-----|----------------|
| `business.website_url` | Authoritative canonical origin when set. Should be `https://veggietokri.com`. |
| `seo.home.*` | Metadata only; URLs are built at runtime via `SeoUrl`. |

## CMS (`cms_pages`, `cms_page_versions`)

| Column | Classification |
|--------|----------------|
| `canonical_url` | May contain manual absolute URLs. If host is `veggietokri.in`, runtime remap applies via `SeoUrl::rewriteLegacyStorefrontOrigin()`. Optional DB update only after legal/comms approval. |
| `og_image` | Often `/uploads/...` (relative). Absolute third-party URLs: leave unchanged. |

## Products / categories

| Column | Notes |
|--------|-------|
| `meta_*`, `robots_index` | No stored absolute storefront URLs. |
| `products.slug` | Path-only; domain from `SeoUrl`. |

## Suggested read-only queries (staging/local)

```sql
SELECT id, slug, canonical_url FROM cms_pages
WHERE canonical_url LIKE '%veggietokri.in%';

SELECT setting_key, setting_value FROM settings
WHERE setting_value LIKE '%veggietokri.in%';
```

## Cache / files

- `storage/cache/seo-sitemap/*.xml` — delete or save Website URL (invalidates automatically).
- `sitemap.xml` — run `php scripts/regenerate-sitemap-xml.php` after domain fix.

## Not modified by domain standardization

- Payment gateway credentials
- External verification URLs (e.g. Udyam gov)
- Customer email addresses
- WhatsApp `wa.me` links
