# Phase 5.1 — Test suite organization

**Date:** 2026-11-03  
**Inputs:** [PHASE5_REPOSITORY_CLEANUP.md](./PHASE5_REPOSITORY_CLEANUP.md), [PHASE5_DELETION_MANIFEST.md](./PHASE5_DELETION_MANIFEST.md)  
**Policy:** **No file moves**, no test deletion, no behaviour changes in this phase.

**Final status:** **ORGANIZATION PLAN READY**

---

## 1. Inventory summary

| Category | Count | Location |
|----------|------:|----------|
| `test-*.php` | **83** | `scripts/` |
| `qa-*.php` | **0** in repo | `scripts/qa-customer-order-detail.php` **quarantined** (Phase 5) → `/home/admin1/apni_tokri_phase5_quarantine/scripts/` |
| Fixture / test helpers | **18** | `scripts/includes/` (+ `d3-sms-concurrent-worker.php`) |
| Non-test tooling | **12** | build/validate/audit/preview/phase3.1, `verify-install.php`, `seed-catalogue.php`, `invoice-storage-audit.php`, `verify-order-items-display.php` |
| PHPUnit / `tests/` tree | **0** | Not used — CLI regression only |

---

## 2. Classification legend

| Type | Meaning in this project |
|------|-------------------------|
| **Unit** | Single class/function, minimal DB (rare; mostly sanitizer/static checks) |
| **Integration** | Multi-table flows, services + DB, often with fixtures |
| **Regression** | Stage/feature suite guarding released behaviour |
| **Security / production readiness** | Env guards, fail-closed, no demo paths in production |
| **Installation / schema** | Migrations, SQL parity, install verification |
| **Manual QA** | Read-only or ad-hoc diagnostics; human-driven interpretation |

**DB write tier (approximate SQL DML token counts in file — includes fixture teardown `DELETE`):**

| Tier | Meaning |
|------|---------|
| **R0** | No `INSERT`/`UPDATE`/`DELETE`/`TRUNCATE` tokens detected |
| **R1–R10** | Light fixture churn |
| **R11+** | Heavy DB mutation (reports, delivery d4, subscription d5, invoices) |

---

## 3. Recommended smoke suite (run before merge / after schema change)

Run on **non-production** DSN only (`AppEnvironment::guardCliScript` blocks prod):

```bash
php scripts/verify-install.php
php scripts/test-production-readiness.php
php scripts/test-production-readiness.php
php scripts/test-phase22-production-blockers.php
php scripts/validate-apni-tokri-production-sql.php   # disposable DB; long-running
```

Domain spot-checks (pick one per area touched): e.g. `test-subscription-stage11.php`, `test-trust-stage12-phase-c1.php`, `test-delivery-d2-hardening.php`, `test-invoices.php`.

---

## 4. Full test inventory by module

### 4.1 Production readiness & audits (Security / Regression)

| Script | Type | DB | Fixture | Network / external | Notes |
|--------|------|-----|---------|-------------------|--------|
| `test-production-readiness.php` | Security / regression | R0–R1 | — | — | Production gates, demo-fallback wiring, dev surfaces, storefront UI |
| `test-verify-otp-registration.php` | Security / regression | R2 | OTP fixtures | — | Registration + verify-otp UI + test OTP display |
| `test-phase22-production-blockers.php` | Security / regression | R2 | — | — | May call subscription checkout paths |
| `test-admin-listing-audit-phase24.php` | Regression | R0 | — | — | Static + repo checks |
| `test-cli-guard-probe.php` | Security | R0 | — | — | Invoked by production-readiness |
| `test-admin-auth-security.php` | Security | R0 | — | — | Auth/session checks |

### 4.2 Installation & schema

| Script | Type | DB | Notes |
|--------|------|-----|--------|
| `test-migration-stage14-phase6-verify.php` | Installation / schema | R2 | Disposable DB branch in script |
| `test-stock-concurrency.php` | Integration | R1 | Inventory concurrency |
| *(tooling)* `validate-apni-tokri-production-sql.php` | Schema | — | Temp DB import |
| *(tooling)* `audit-production-sql-schema-deep.php` | Schema | — | Temp DB |
| *(tooling)* `run-phase4-fresh-install-audit.php` | Schema | — | Temp DB + optional login |
| *(tooling)* `verify-install.php` | Installation | Read | Live DSN |

### 4.3 Subscription (Regression / Integration)

| Script | Type | DB | Fixture |
|--------|------|-----|---------|
| `test-subscription-stage11.php` | Regression | R0 | — |
| `test-subscription-stage11-phase-a.php` | Regression | R0 | — |
| `test-subscription-stage11-phase-b.php` | Regression | R6 | — |
| `test-subscription-stage11-phase-c.php` | Regression | R5 | — |
| `test-subscription-stage11-phase-d.php` | Regression | R5 | — |
| `test-subscription-stage11-phase-e.php` | Regression | R7 | fulfilment fixture |
| `test-subscription-stage11-hardening.php` | Integration | R16 | fulfilment fixture |
| `test-subscription-stage11-production-readiness.php` | Regression | R10 | — |
| `test-subscription-d5.php` | Integration | R29 | fulfilment + CLI runner |
| `test-subscription-admin-delete.php` | Integration | R18 | ZT prefix cleanup |
| `test-subscription-storefront-plans.php` | Regression | R0 | — |
| `test-account-list-pagination.php` | Integration | R9 | SUB-PG-* markers |

**Duplicate / overlap:** `test-subscription-stage11.php` is an umbrella; phase scripts a–e + hardening + d5 deepen coverage — **keep all** until a merged runner exists.

### 4.4 Delivery (Regression / Integration)

| Script | Type | DB | Fixture |
|--------|------|-----|---------|
| `test-delivery-stage8.php` | Regression | R0 | — |
| `test-delivery-d1.php` | Integration | R2 | CLI runner |
| `test-delivery-d2.php` | Integration | R7 | CLI runner |
| `test-delivery-d2-hardening.php` | Integration | R6 | d2h fixture |
| `test-delivery-d3.php` | Integration | R7 | — |
| `test-delivery-d3-hardening.php` | Integration | R16 | d3 worker + locks |
| `test-delivery-d4.php` | Integration | R40 | — |
| `test-delivery-slot-concurrency.php` | Integration | R3 | — |
| `test-delivery-slots-admin.php` | Integration | R3 | — |
| `test-address-map-pin.php` | Integration | R8 | AT-MAP-* orders |

**Overlap:** `test-delivery-stage8.php` vs **d1–d4** — stage8 is baseline; d* are incremental — **keep both**.

### 4.5 Payments & invoices (Integration)

| Script | Type | DB | Gateway / payment |
|--------|------|-----|-------------------|
| `test-payment-stage9.php` | Integration | R0 | Simulated / config |
| `test-payment-initiation.php` | Integration | R8 | AT-PAY-* |
| `test-payment-cashfree-sync.php` | Integration | R0 | Reads existing order |
| `test-payment-gateway-diagnostics.php` | Regression | R0 | UI/settings |
| `test-payment-gateway-credentials-ui.php` | Regression | R0 | — |
| `test-cart-order-post-payment.php` | Integration | R4 | Order AT-261102-* |
| `test-checkout-nonce.php` | Regression | R0 | — |
| `test-invoices.php` | Integration | R24 | PDF/storage; local paths |

**Env:** `test-invoices.php` uses `storage/secrets/master.key` and `storage/invoices` under project root — not production paths.

### 4.6 Trust, refunds, support (Integration)

| Script | Type | DB | Fixture |
|--------|------|-----|---------|
| `test-trust-stage12.php` | Regression | R0 | — |
| `test-trust-stage12-phase-b.php` | Integration | R0 | — |
| `test-trust-stage12-phase-c1.php` | Integration | R8 | stage12 C1 + teardown |
| `test-trust-stage12-phase-c2-1.php` | Integration | R0 | c21 fixture |
| `test-trust-stage12-phase-c2-2.php` | Integration | R0 | c21 fixture |
| `test-trust-stage12-phase-c3.php` | Integration | R1 | c3 fixture |
| `test-support-access.php` | Integration | R2 | — |
| `test-support-ticket-admin-detail.php` | Regression | R0 | — |
| `test-customer-order-detail.php` | Regression | R0 | — |
| `test-customer-order-timeline.php` | Integration | R15 | — |

### 4.7 Stage 13 — CMS, SEO, FAQ (Integration)

| Script | Fixture cleanup |
|--------|-----------------|
| `test-cms-stage13-phase1.php` | stage13_p1 |
| `test-cms-stage13-phase2.php` | stage13_p2 |
| `test-cms-sanitizer-stage13.php` | Unit-ish R0 |
| `test-faq-stage13-phase3.php` | stage13_p3 |
| `test-seo-stage13-phase4.php` | stage13_p4 |
| `test-seo-redirects-stage13-phase5.php` | stage13_p5 |
| `test-sitemap-stage13-phase6.php` | stage13_p6 |
| `test-structured-data-stage13-phase7.php` | stage13_p7 |
| `test-homepage-trust-stage13-phase8.php` | stage13_p8 |
| `test-stage13-integration-phase9.php` | stage13_p9 + p2 |

### 4.8 Stage 14 — RBAC, SMTP, SMS, notifications, reports (Integration)

| Script | DB | Special |
|--------|-----|---------|
| `test-rbac-stage14-phase1.php` | R7 | stage14_p1 fixture |
| `test-smtp-stage14-phase2.php` | R3 | **Local SMTP test peer** (socket) |
| `test-sms-stage14-phase3.php` | R15 | SMS simulate; queue |
| `test-sms-operation-mode.php` | R5 | Mode guards |
| `test-notification-stage14-phase4.php` | R19 | Queue + **smtp_test_peer** |
| `test-reports-stage14-phase5.php` | R16 | stage14_p1 |
| `test-reports-stage14-phase6.php` | R32 | Heavy aggregates |
| `test-reports-stage14-phase6-ui.php` | R12 | Admin UI strings |

**Overlap:** phase6 vs phase6-ui — **service vs UI** — keep both.

### 4.9 Catalogue, admin UI, customer (Regression / Integration)

| Script | Type | DB | Network |
|--------|------|-----|---------|
| `test-promotions-stage10.php` | Regression | R0 | — |
| `test-catalogue-product-visibility.php` | Integration | R5 | — |
| `test-category-visibility.php` | Integration | R1 | — |
| `test-category-nav-render.php` | Integration | R1 | **HTTP** `127.0.0.1/Apni_Tokri/index.php` |
| `test-admin-server-list.php` | Regression | R0 | — |
| `test-admin-slug-generation.php` | Integration | R6 | — |
| `test-admin-add-form-slugs.php` | Regression | R3 | — |
| `test-admin-delete-actions.php` | Integration | R15 | — |
| `test-admin-flash-messages.php` | Regression | R0 | — |
| `test-admin-orders-view.php` | Regression | R0 | **HTTP** admin orders |
| `test-admin-support-notifications.php` | Integration | R0 | — |
| `test-business-settings.php` | Regression | R0 | — |
| `test-branding-settings.php` | Regression | R0 | — |
| `test-settings-profile-consolidation.php` | Regression | R0 | — |
| `test-customer-account.php` | Integration | R6 | AT-ACCT* |
| `test-login-mobile-validation.php` | Integration | R0 | OTP paths |
| `test-otp-rate-limit.php` | Integration | R2 | SMS **test mode** settings |

### 4.10 Manual QA (non-`test-` prefix)

| Script | Type | DB |
|--------|------|-----|
| `verify-order-items-display.php` | Manual QA | R2 read-focused |

---

## 5. Fixture dependency map

| Fixture file | Consumed by (examples) |
|--------------|-------------------------|
| `stage12_phase_c1_fixture.php` | `test-trust-stage12-phase-c1.php` |
| `stage12_phase_c3_fixture.php` | `test-trust-stage12-phase-c3.php` |
| `stage12_phase_c21_fixture.php` | c2-1, c2-2 trust tests |
| `d2h_test_fixture.php` | `test-delivery-d2-hardening.php` |
| `subscription_test_fulfilment_fixture.php` | stage11 e, hardening, d5 |
| `stage13_phase1–9_fixture.php` | respective stage13 tests |
| `stage14_phase1_fixture.php` | rbac, smtp, sms, notifications, reports |
| `smtp_test_peer.php` | smtp phase2, notifications phase4 |
| `test_cli_runner.php` | delivery d1–d4, d5, d2 hardening |

---

## 6. Safety requirements (all scripts)

| Requirement | Application |
|-------------|-------------|
| **Production DSN** | Blocked for `test-*` and `seed-catalogue.php` unless `APNI_TOKRI_ALLOW_PROD_CLI=1` |
| **Disposable DB** | Required for: production SQL validate/deep/fresh-install, migration phase6 verify |
| **Env vars** | `BUILD_TARGET_DB` (build SQL), `CLONE_DB` (phase3.1 optional), `APNI_TOKRI_ALLOW_PROD_CLI` |
| **Email/SMS** | Prefer `simulate`, `MODE_TEST`, or **smtp_test_peer** — not live provider in tests |
| **HTTP tests** | `test-category-nav-render.php`, `test-admin-orders-view.php` need local web server |
| **Payment** | Most tests use **simulate**; cashfree sync uses existing fixture order |

Scripts with **heavy writes (R24+):** run only on dev/QA DB; never production.

---

## 7. Recommended directory structure (target)

```
scripts/
  tooling/              # build, validate, audit, preview, phase3*, verify-install, seed-catalogue
  tests/
    regression/
      subscription/
      delivery/
      payment/
      trust/
      catalog/
      admin/
      stage13/
      stage14/
    production/         # phase21, phase22, production-readiness, admin-listing-audit
    schema/             # migration-verify, stock-concurrency
    manual/             # verify-order-items-display.php
  fixtures/             # today's scripts/includes/* + smtp peer + cli runner + d3 worker
  workers/              # d3-sms-concurrent-worker.php (optional)
```

Keep **`scripts/test-*.php` paths stable** until migration — update `PROJECT_PROGRESS.md`, CI, and docs in one pass.

---

## 8. Directory migration plan (document only — **do not execute**)

| Phase | Action | Risk |
|-------|--------|------|
| **M0** | Add `scripts/run-regression-smoke.sh` wrapping smoke suite | Low |
| **M1** | Move `scripts/includes/*_fixture.php` → `scripts/fixtures/`; shim `require` one release | Medium |
| **M2** | Move tests by module into `scripts/tests/regression/**`; add thin wrappers at old paths OR update all docs | Medium |
| **M3** | Move tooling scripts; leave `verify-install.php` at `scripts/` or symlink | Low |
| **M4** | Remove shims after one release cycle | Low |

**Rollback:** Git revert; no DB migrations involved.

---

## 9. Duplicate coverage (consolidation candidates — future only)

| Group | Recommendation |
|-------|----------------|
| `test-subscription-stage11.php` + phases a–e | Keep; optional meta-runner calling phases |
| `test-trust-stage12.php` + phase-* | Keep; umbrella + focused phases |
| `test-reports-stage14-phase6.php` + `-ui.php` | Keep both |
| `test-customer-order-detail.php` + `verify-order-items-display.php` | Keep both (automated vs manual) |

---

## 10. Related deliverable

Storage JSON / PDF hygiene: [PHASE5_1_STORAGE_HYGIENE.md](./PHASE5_1_STORAGE_HYGIENE.md)
