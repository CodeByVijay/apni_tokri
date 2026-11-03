# Apni Tokri — project progress

Authoritative stage status for implementation. Requirements detail: [Apni_Tokri_Requirements.md](./Apni_Tokri_Requirements.md). Database upgrades: [database/README.md](./database/README.md).

**Last updated:** Nov 2026 (Phase 2.2 production blocker resolution)

## Delivery enhancements (D1–D5)

| Track | Focus | Status |
|-------|--------|--------|
| D1 | Zones, hybrid/distance bands | Complete |
| D2 | Slot reservations & capacity | Complete |
| D2 hardening | Payment completion exceptions | Complete |
| D3 | Manual courier fulfilment | Complete (D3 audit hardening) |
| D4 | Checkout quote snapshot, payment revalidation & D4 hardening | Complete (schema **1.8.0-d4-hardening**) |
| D5 | Subscription fulfilment quote alignment | Complete (schema **1.8.0-d5-subscription**) |

Tests: `scripts/test-delivery-d1.php` … `test-delivery-d4.php`, `test-subscription-d5.php`, `test-delivery-d2-hardening.php`, `test-delivery-d3-hardening.php`.

## Stage summary

| Stage | Focus | Status |
|-------|--------|--------|
| 0 | Architecture, config, security | Complete |
| 1 | Brand and design system | Complete |
| 2 | Public homepage and catalogue UI | Complete |
| 3 | Cart, checkout and account UI | Complete |
| 4 | Admin panel UI | Complete |
| 5 | Database and PHP foundation | Complete |
| 6 | Catalogue and inventory backend | Complete |
| 7 | Customer, cart and checkout backend | Complete |
| 8 | Delivery engine | Complete |
| 9 | Payment gateway integration | Complete |
| 10 | Offers and coupons | Complete |
| 11 | Subscription engine | **CONDITIONALLY ACCEPTED** — core complete; Razorpay staging/E2E deferred (see below) |
| 12 | Reviews, testimonials, support and refunds | **Complete** (Phases A–C.3 per implemented migrations and tests) |
| **13** | CMS, FAQs, trust and SEO | **PASS WITH KNOWN LIMITATIONS** (Phases 1–9, schema **1.9.0-stage13-cms-1**) |
| **14** | Notifications, reports, permissions | **In progress** — **Phase 14.1 RBAC** complete (schema **1.10.0-stage14-rbac-1**) |
| 15 | Security, performance, QA and launch | Not started |

Stages **1–10** are treated as complete; do not regress their behaviour when working on later stages.

## Stage 11 — subscription engine

**Schema:** through **1.6.6** baseline; current install target **1.9.0-stage13-cms-1** via migrations.

**Implemented (core):** Plan builder, checkout and activation, menus and baskets, lifecycle, fulfilment, substitutions, reservation recovery, entitlement on delivery complete.

**Not fully complete:** Razorpay staging/production integration gaps are **deferred** until Stages **14–15** and a **final integration pass**. Do not label Stage 11 fully production-ready until that pass finishes.

### Deferred items (preserve for final pass)

1. Configure **Razorpay test credentials** on staging.
2. Connect **paid substitution customer UI** to **Razorpay Checkout** and `ajax/payment/verify.php`.
3. **Subscription substitution payment** in **Razorpay webhook** processing.
4. **Complete Razorpay test-mode E2E** (webhook-before-verification and reverse).
5. Stage 11 gateway E2E with Stage 12 refunds (order refunds via `GatewayRefundService` implemented in Stage 12).

## Stage 12 — reviews, support, refunds

**Schema:** **1.7.0** + **1.7.1** (Phase B) + **1.7.2** (review moderation history) + **1.8.0-stage12-c3-1** (webhook lease). Label in DB after full upgrade path: **1.9.0-stage13-cms-1** (Stage 13 migration updates `schema_version` only for CMS; Stage 12 tables unchanged).

**Tests:** `scripts/test-trust-stage12.php`, `test-trust-stage12-phase-b.php`, `test-trust-stage12-phase-c1.php`, `test-trust-stage12-phase-c2-1.php`, `test-trust-stage12-phase-c2-2.php`, `test-trust-stage12-phase-c3.php`.

**External verification not claimed:** Cashfree/Razorpay refund webhook behaviour in production.

## Stage 13 — CMS, FAQs, trust and SEO (Phases 1–9)

**Schema version:** **`1.10.0-stage14-rbac-1`** (`Install::SCHEMA_VERSION`, `config.local.php` → `install.expected_schema_version`).

**Migration:** `database/migrations/1.9.0_stage13_cms_foundation.sql`  
**Rollback:** `database/migrations/rollback/1.9.0_stage13_cms_foundation_rollback.sql`  
**Depends on:** Stage 12 baseline (typically **1.8.0-stage12-c3-1** or **1.8.0-d5-subscription** + Stage 12 migrations).

| Phase | Focus | Status |
|-------|--------|--------|
| 1 | CMS foundation (schema, repository, sanitizer, versions, draft/public/private) | Complete |
| 2 | CMS admin (CRUD, publish, preview, image upload, version history, legal placeholders) | Complete |
| 3 | FAQ DB admin + public `faqs.php` | Complete |
| 4 | Centralized SEO metadata (`SeoResolver`, canonical, robots) | Complete |
| 5 | Slug-change 301 redirect engine (`seo_redirects`, bootstrap hook) | Complete |
| 6 | `sitemap.xml` / `robots.txt` (chunked sitemap, indexability rules) | Complete |
| 7 | JSON-LD (Organization, WebSite, BreadcrumbList, Product, FAQPage, WebPage) | Complete |
| 8 | Homepage trust sections (`homepage.trust.config` in `settings`) | Complete |
| 9 | Integration, regression, acceptance | Complete (this document) |

### Admin permissions (Stage 13)

| Module | Permission key | Notes |
|--------|----------------|--------|
| CMS pages | `cms` (`cms.manage` for mutations where enforced) | POST + CSRF on create/edit/publish/upload |
| FAQs | `faqs` | Categories and FAQ CRUD; POST + CSRF |
| Homepage trust | `homepage` | Trust section config; POST + CSRF |

### Public routes

- **`page.php?slug=`** — published **public** CMS only; draft/unpublished → 404; **private** published → 403 unless `CmsPageService::viewerAuthorizedForPrivate()`.
- **`faqs.php`** — database-backed active FAQs/categories; search and pagination.
- **`sitemap.php` / `robots.php`** — via `.htaccess` rewrite to `sitemap.xml`, `sitemap-N.xml`, `robots.txt`; honours `url_path` / `base_url`.
- **Early redirects** — `bootstrap.php` → `SeoRedirectRequest` (GET/HEAD only; skips sitemap/robots).

### SEO / redirects / structured data (summary)

- **Canonical** and **robots** from `SeoMetadata`; draft/private/noindex excluded from sitemap and JSON-LD where specified in phase tests.
- **Redirects** — internal path identity only; UTM preserved on response; loop/chain depth capped; slug routes use canonical slug query identity.
- **JSON-LD** — no fabricated prices/reviews; Product requires published product + active variant; reviews approved + visible; FAQPage on unfiltered `faqs.php` only.

### Homepage trust

- Config key: **`homepage.trust.config`** (JSON). Defaults from `HomepageTrustService::defaultConfig()` when absent.
- **Testimonials** content still from **`TestimonialService::featuredPublic()`** (approved, featured, consent recorded); admin only toggles headings/placement.

### Known limitations (Stage 13)

1. **Homepage FAQ preview** (`includes/homepage/trust_render.php`) uses **`demo_catalogue()->faqs()`**, not `FaqRepository`. Public **`faqs.php`** is fully database-backed — behaviour is intentionally different until a future stage.
2. **No CMS SEO admin panel** — robots/canonical set via publish/update payloads and defaults, not a dedicated SEO UI (out of Stage 13 scope).
3. **No redirect management UI** — redirects created on slug change only.
4. **`database/database.sql`** remains a **1.1.0 fresh-install baseline**; new environments must apply **all migrations** through **1.9.0-stage13-cms-1** (see [database/README.md](./database/README.md)). A empty-database fresh install was **not** re-run end-to-end in Phase 9.
5. **Legal CMS placeholders** inserted by migration are **`draft`** — not publicly viewable until published.
6. **Per-product/category SEO fields in admin** — metadata helpers exist; full per-entity SEO editing UI may remain partial vs requirements wording.

### Stage 13 tests (run after CMS migration)

```bash
php scripts/test-cms-sanitizer-stage13.php
php scripts/test-cms-stage13-phase1.php
php scripts/test-cms-stage13-phase2.php
php scripts/test-faq-stage13-phase3.php
php scripts/test-seo-stage13-phase4.php
php scripts/test-seo-redirects-stage13-phase5.php
php scripts/test-sitemap-stage13-phase6.php
php scripts/test-structured-data-stage13-phase7.php
php scripts/test-homepage-trust-stage13-phase8.php
php scripts/test-stage13-integration-phase9.php
```

**Regression (Phase 9):** `test-trust-stage12.php`, `test-trust-stage12-phase-c3.php`, `test-subscription-stage11.php`, `test-subscription-d5.php`, `test-delivery-d3-hardening.php`.

**Phase 9 conclusion:** **PASS WITH KNOWN LIMITATIONS** — automated suites pass on the configured local DB; production readiness still requires manual checks below.

### Manual production-readiness checklist (Stage 13 scope)

- [ ] Apply migration **1.9.0_stage13_cms_foundation.sql** on staging/production from documented Stage 12 baseline.
- [ ] Publish lawyer-approved legal pages; remove placeholder copy.
- [ ] Verify `sitemap.xml` and `robots.txt` URLs with production `base_url` / `url_path`.
- [ ] Spot-check slug redirects after real CMS/product/category slug edits.
- [ ] Confirm homepage trust copy and images; disable empty sections.
- [ ] Rich-results / Search Console validation for Product and FAQ (optional but recommended).
- [ ] Stage 11/12 gateway and webhook checks (outside Stage 13 automated scope).

**Do not** treat passing PHP CLI tests alone as full production sign-off.

### Phase 2.2 — production blockers (code)

**Automated:** `php scripts/test-phase22-production-blockers.php` (with `test-phase21-demo-fallback-audit.php`, `test-production-readiness.php`).

| Area | Status |
|------|--------|
| SEO admin — DB redirects (CRUD, CSRF, audit) | Done |
| Marketing admin — `MarketingSettings` persistence | Done |
| Subscription checkout — payment gate before INSERT + transaction rollback | Done |
| `StorefrontReadiness::productionBlockers()` — required tables | Done |
| Production fail-closed — FAQs page, `DbCatalogue::faqs()`, login (existing) | Done |
| Admin `!$useDb` — production error vs local demo preview | Done (core lists + edit POSTs) |

**Updated production-readiness checklist (not full sign-off):**

- [ ] `app.env=production`, `payment.test_mode=0`, live Razorpay or Cashfree credentials (no `simulate` gateway).
- [ ] All tables in `Install::missingProductionRequiredTables()` present (run install/migrations through **1.20.0**).
- [ ] `StorefrontReadiness::productionBlockers()` empty on staging (published products, payments ready).
- [ ] SEO redirects and marketing settings verified in admin after save/reload.
- [ ] Subscription purchase smoke test on staging with real gateway (no `gateway=simulate` rows).
- [ ] Manual legal/CMS, webhooks, SMS/SMTP, and infra checks from Stage 11–14 lists still apply.

**Remaining non-code blockers:** live payment credentials, content/legal review, hosting/SSL/cron, webhook URLs, and operational monitoring. **Do not** go live while `productionBlockers()` reports issues or payments are in test/simulate mode.

## Stage 14 — Phase 14.1 (RBAC)

**Migration:** `database/migrations/1.10.0_stage14_rbac_phase1.sql` → **`1.10.0-stage14-rbac-1`**

**Implemented:** System role permission seeding (idempotent), DB-backed admin users (`admin/users/`) and roles (`admin/roles/`), `AdminRbacService`, payments listing permission fix, audit events for RBAC mutations.

**Matrix:** `docs/rbac-permission-matrix.md`

**Test:** `php scripts/test-rbac-stage14-phase1.php`

**Not in 14.1:** SMTP/SMS, notification queue, reports/CSV, audit log viewer, support analytics.
