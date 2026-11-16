# Product sharing (storefront)

Customer-facing **Share** control on `product.php` (product detail). Every action uses the **canonical product URL**:

`/product.php?slug={slug}` (absolute, via `SeoUrl::absolute` / `ProductShare::canonicalUrl`).

## UI behaviour

- **Supported:** `navigator.share()` with product name, share text (short description, optional starting price, site name), and URL. No image is attached via Web Share API.
- **Fallback:** Share menu with WhatsApp, Telegram, and **Copy link** (toast on success).
- If native share fails (other than user cancel), the menu opens.

## Open Graph / Twitter (server-rendered)

Rendered in `includes/layout/head-public.php` from `SeoMetadata::headTagLines()` on product pages:

- `og:title` — `{product name} | {site name}`
- `og:description` — short description (fallback: meta / long description)
- `og:image` — primary product image (absolute URL), else default branding image
- `og:url` — same canonical product URL as share actions
- `og:type` — `product`
- `twitter:card` — `summary_large_image`
- Matching `twitter:title`, `twitter:description`, `twitter:image`

Draft and archived products are not public PDPs (`ProductRepository::findBySlug(..., true)`); they do not receive product share metadata on the live storefront.

## Refreshing cached social previews

WhatsApp, Telegram, Facebook, and X cache link previews. After changing product name, description, or images:

1. Confirm the live PDP HTML shows updated `og:*` / `twitter:*` tags (view source).
2. Use each platform’s debugger / cache refresh where available (e.g. Meta Sharing Debugger, X Card Validator).
3. Re-share the **same canonical URL**; short links or query-string variants may cache separately.

## Testing

```powershell
& 'd:\xampp\php\php.exe' scripts/test-product-share.php
```

Disposable DB tests (product rows):

```powershell
& 'd:\xampp\php\php.exe' scripts/run-in-disposable-db.php scripts/test-product-share.php
```
