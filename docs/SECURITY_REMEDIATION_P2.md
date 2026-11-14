# Security Remediation — P2 (from `PENTEST_REPORT_2026-11-14.md`)

Scope: VT-2026-01 … VT-2026-04 only. No P3 items touched.

## VT-2026-01 — Public `/docs/` and root `*.md`

- **Root cause:** `.htaccess` denied only `includes/`, `storage/`, `database/`, `scripts/`; `docs/` and root markdown were served as static files.
- **Fix:**
  - `.htaccess` → `RewriteRule ^docs(/|$) - [F,L,NC]` (403 for the whole tree).
  - `.htaccess` → `<FilesMatch "\.(md|markdown|sql|sqlite|db|bak|backup|log|ini|dist|orig|sh)$">` denied for Apache 2.4 (`Require all denied`) and 2.2 (`Deny from all`). Blocks `README.md`, `Apni_Tokri_Requirements.md`, `CODING_CONVENTIONS.md`, `DEMO_TEST_DATA_AUDIT.md`, `PROJECT_PROGRESS.md`, plus dumps/backups/logs anywhere under webroot.
  - `.deployignore` (new) lists `docs/`, `*.md`, dumps, VCS/editor files as non-deployable.
- **Before → after:** `GET /docs/SECURITY_AUDIT_REPORT.md` → 200 (full audit text) → **403**. `GET /README.md` → 200 → **403**. Verified locally (XAMPP, Apache 2.4.58).

## VT-2026-02 — Live `_dbg_*`/`_tmp_*` files on production

- **Root cause:** Debug/preview scratch files were present on the production webroot although already deleted in the working tree — deletion was never deployed.
- **Fix:**
  - `.htaccess` → `RewriteRule ^_ - [F,L,NC]` — every root URL starting with `_` returns **403 regardless of whether the physical file still exists on the server**. No legitimate route begins with `_` (`admin/_init.php` is under `admin/` and unaffected).
  - `.deployignore` names `_dbg_footer.php`, `_dbg_footerpage.php`, `_tmp_footer_preview.php` as non-deployable and calls out physical removal from the server.
  - **Action required on the host:** delete the three files from the production webroot (cannot be done from the repo). The `.htaccess` deny makes them unreachable immediately after deploy; deletion removes the residual surface.
- **Before → after:** `GET /_dbg_footer` → 200 + `connect OK` → **403**. Verified locally.

## VT-2026-03 — `verify-otp.php` leaked registered name/email

- **Root cause:** Returning-customer UI rendered `uiContextForMobile($mobile)['name'/'email']` into readonly fields before OTP success; the page is reachable by anyone who submits a mobile on `login.php`.
- **Fix (`verify-otp.php` only):** added `$maskIdentifier`/`$maskEmail` closures; for `MODE_RETURNING` the name field shows `S••••` and email `l••••@d••••` instead of full values. Non-returning (new/legacy) paths unchanged — the owner still types their own name.
- **Before → after:** `value="Suresh Kumar"` → `value="S••••"`. Verified locally against a returning test customer (mobile 9876543210) and a new mobile (9000000007 → empty editable fields, `mode="new"`).
- **Residual:** `data-otp-reg-mode="returning"` still reveals account existence (not identity) — bounded by OTP rate limits (3/mobile/15 min, 10/IP/hr). Full removal would require JS changes; tracked as remaining risk, not part of this finding's scope.

## VT-2026-04 — Content-Security-Policy (Report-Only)

- **Root cause:** no CSP anywhere; CMS `body_html` renders raw HTML, so there was no browser-side containment for stored XSS.
- **Fix (`includes/security.php`):**
  - `Security::cspNonce()` / `cspNonceAttr()` — one base64 nonce per request (`random_bytes(16)`), shared by header and markup.
  - `sendSecurityHeaders()` now emits `Content-Security-Policy-Report-Only` on every page that calls it (all storefront + admin pages via `admin/_init.php`). Kill-switch: `security.csp.enabled = false` in config.
  - Policy: `default-src 'self'`; `script-src 'self' 'nonce-…' cdn.tailwindcss.com code.jquery.com sdk.cashfree.com checkout.razorpay.com www.googletagmanager.com www.gstatic.com`; `style-src 'self' 'unsafe-inline' fonts.googleapis.com` (Tailwind CDN runtime styles + ~107 `style=` attributes cannot be nonced); `img-src 'self' data: https:` (Leaflet/OSM tiles + CMS images); `connect-src 'self' *.googleapis.com *.google-analytics.com *.googletagmanager.com *.gstatic.com *.cashfree.com *.razorpay.com` (GA4, FCM, payment SDKs); `frame-src api.razorpay.com *.razorpay.com *.cashfree.com`; `font-src fonts.gstatic.com`; `worker-src 'self'` (firebase-messaging-sw.js); `object-src 'none'`; `base-uri 'self'`; `frame-ancestors 'self'`; `form-action 'self'`; `manifest-src 'self'`; `media-src 'self'`; `upgrade-insecure-requests` (HTTPS only).
  - **`script-src` deliberately has no `'unsafe-inline'`** — this is intentional. Report-Only blocks nothing, and violations will enumerate every remaining inline handler before enforcement.
- **Nonce retrofit:** 42 inline `<script>` tags across 34 files got `nonce="…"` via `Security::cspNonceAttr()` — storefront (`checkout.php`, `payment.php`, `subscription-*.php`, `verify-otp.php`, `head-public`/`footer-public` incl. GTM/GA4 + Tailwind config + `AT_BASE`/`AT_MAP_CONFIG`/`AT_ADDRESS_AUTOCOMPLETE`), admin (`orders/view`, `orders/create`, `refunds`, `cms/edit`, `dashboard`, settings, email templates, zones, etc.) and partials (`order-retry-payment-*`, `order-payment-window`, `customer-order-cancel`, `cms-rich-editor`, `seo-nav`, `report-filters`). `application/json`/`ld+json` data blobs intentionally left un-nonced (non-executable).
- **Before → after:** no CSP header → `Content-Security-Policy-Report-Only` on every HTML response; nonce in header matches nonce on inline tags (verified byte-for-byte on `/`).

## Files changed

- `.htaccess` — `^docs/` deny, `^_` deny, `FilesMatch` for md/sql/bak/log/etc.
- `.deployignore` — new; production exclusion list.
- `verify-otp.php` — masked identifiers for returning customers + nonce on inline script.
- `includes/security.php` — CSP nonce helpers + Report-Only emission.
- 33 additional files — `nonce` attribute added to inline `<script>` tags only (no logic changes): `checkout.php`, `payment.php`, `subscription-details.php`, `subscription-payment.php`, `includes/layout/{head-public,head-admin,footer-public}.php`, `includes/partials/{customer-order-cancel,order-payment-window,order-retry-payment-cta,order-retry-payment-launch,product-gallery,product-share}.php` (last two: none needed — JSON blobs), `includes/partials/admin/{cms-rich-editor,email-template-editor,report-filters,seo-nav}.php`, and 17 `admin/**` pages. See `git diff --stat`.

## Regression tests (local, XAMPP)

| Check | Result |
|---|---|
| `php -l` on all 34 modified PHP files | All clean |
| `/`, `/cart`, `/login`, `/faqs`, `/contact`, `/support`, `/admin/login` | 200 |
| `/my-orders`, `/payment` (unauth) | 302 → login (unchanged) |
| `/ajax/ping` | 200 (unchanged) |
| `/install` | 404 (production-gated locally? — returns 404 because schema ready; unchanged) |
| `/docs/SECURITY_AUDIT_REPORT.md`, `/README.md` | 403 (was 200) |
| `/_dbg_footer` | 403 (was 200 on prod) |
| login POST → `/verify-otp` | 302, page renders, masked name `S••••` for returning customer |
| verify OTP POST (returning customer) | 302 → `/my-account`, session established |
| verify-otp for new mobile | `mode="new"`, name/email editable & empty |
| Header/body nonce equality | `nonce="yYy1…"` identical in CSP header and tag attrs on same response |

## Browser verification

- Served locally via Apache; pages render normally (a preview was opened at http://localhost/Apni_Tokri).
- **No `report-uri` is configured** — intentionally; violations are observed in DevTools console rather than a public collection endpoint. To collect violations: open DevTools → Console, filter for `Content Security Policy`, then click through storefront → cart → checkout → payment (sandbox), `/admin/login` → dashboard → orders view → refunds → CMS edit. Expected violations before enforcement are documented below.

## Known CSP violations expected under the current Report-Only policy

These exist in the codebase and will appear in console reports — they are the backlog to clear before flipping to enforcing `Content-Security-Policy`:

1. **~32 inline event handlers** (`onclick`, `onchange`, `onload`, `onsubmit` in `admin/customers/view.php`, `admin/orders/view.php`, `admin/deliveries/view.php`, `admin/faqs/*`, `admin/firebase/index.php`, `admin/push/*`, `admin/roles/edit.php`, `admin/seo/sections/redirects.php`, `admin/settings/delivery/slots.php`, `admin/settings/email-templates/edit.php`, `admin/subscriptions/menu-planner.php`, `category.php`, `includes/admin/list_actions.php`, `includes/partials/admin/list-pagination.php`, `includes/partials/admin/order-detail-*.php`, and the Google-Fonts `<link … onload=` preload shim in `head-public.php`). Cannot be nonced — must be refactored to `addEventListener`/external JS before enforcement.
2. **CMS `body_html` content** — if any CMS page embeds `<script>` or event handlers, it will report (nonced rendering is impossible for DB content; sanitization is a separate, already-tracked gap).
3. **GTM-injected resources** — when a GTM container is enabled it can load arbitrary tag scripts/iframes; the policy allows `googletagmanager.com`/`google-analytics.com` but container-specific origins may report.
4. **Firebase Messaging SDK** — `import()`/`importScripts` from `gstatic.com` is allowed; FCM connect endpoints are under `*.googleapis.com`; any other Google domain the SDK calls will report.
5. **Leaflet/OSM tiles** — allowed via `img-src https:`; if a custom `tileUrl` is configured to a non-HTTPS host it will report.

## Remaining risks (unchanged by this phase)

- Report-Only **does not block anything** — stored-XSS in `body_html` is still exploitable until enforcement + sanitization; this phase only instruments and prepares.
- All P3 findings from the pentest report are intentionally out of scope (admin logout CSRF, status transition matrix, session idle timeout, order.php banner, partials 500s, uploads PHP-exec hardening).
- Physical deletion of `_dbg_*`/`_tmp_*` on the production host is still required (`.htaccess` masks them in the meantime).
- Before enforcing CSP: replace the ~32 inline event handlers, re-test checkout/Cashfree/Razorpay sandbox, GTM-enabled pages, FCM opt-in, Leaflet address picker, and at least one CMS page that embeds rich HTML.
