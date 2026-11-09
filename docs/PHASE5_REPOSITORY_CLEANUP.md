# Phase 5 — Repository cleanup (test & development files)

**Date:** 2026-11-03  
**Scope:** Full audit of scripts, storage artifacts, docs, database tooling, cron, and root-level audit files.  
**Policy:** Conservative — only **one** high-confidence file quarantined; no migrations, no business logic, no DB changes.

---

## 1. Audit summary

| Area | Items inspected | Outcome |
|------|----------------:|---------|
| `scripts/*.php` | 112 → **111** after quarantine | 1 duplicate QA script quarantined |
| `scripts/includes/` | 18 (fixtures, SMTP peer, CLI runner) | All retained — consumed by stage tests |
| `cron/` | 6 | All retained — production operations |
| `database/migrations/` | 37 forward + rollbacks | **Excluded** (never delete) |
| `database/production/` | SQL + README | **Excluded** (never delete) |
| `storage/` | 6 files (JSON manifests, invoice dir) | Retained; JSON = regeneratable evidence |
| `docs/` | 11 + phase deliverables | Retained |
| `tests/` | — | Not present |
| Root | `DEMO_TEST_DATA_AUDIT.md`, `PROJECT_PROGRESS.md`, etc. | Retained (manual review) |

**Total files inspected (primary candidates):** ~**137** (111 + 18 scripts/includes + 6 cron + 2 production SQL tools counted in scripts).

---

## 2. Classification overview

### A — Keep essential

- Application PHP (`admin/`, `includes/`, storefront, `webhooks/`, `ajax/`, etc.)
- `database/migrations/`, `database/database.sql`, `database/production/`
- Build/validate: `build-apni-tokri-production-sql.php`, `validate-apni-tokri-production-sql.php`, `audit-production-sql-schema-deep.php`, `run-phase4-fresh-install-audit.php`
- `verify-install.php`, `seed-catalogue.php`
- Deployment/readiness docs: `PRODUCTION_DEPLOYMENT_READINESS.md`, `database/production/README.md`
- `invoice-storage-audit.php` (operational orphan PDF audit)

### B — Keep useful development / regression tests

All remaining `scripts/test-*.php` files (~85), including:

| Domain | Examples |
|--------|----------|
| Subscription | `test-subscription-stage11*.php`, `test-subscription-d5.php`, `test-subscription-admin-delete.php` |
| Delivery | `test-delivery-d1.php` … `d4`, `test-delivery-d2-hardening.php`, `test-delivery-d3-hardening.php`, `test-delivery-stage8.php` |
| Payments | `test-payment-stage9.php`, `test-payment-initiation.php`, `test-invoices.php` |
| Trust / refunds | `test-trust-stage12*.php` |
| Stage 13–14 | CMS, SEO, FAQ, SMTP, SMS, notifications, reports, RBAC |
| Production gates | `test-production-readiness.php` (consolidated demo-fallback + dev-surface + storefront UI), `test-phase22-production-blockers.php`, `test-admin-listing-audit-phase24.php` |

**Note:** Most test scripts are **not referenced** from application code; they are invoked via CLI. Lack of `grep` hits is **not** grounds for deletion.

### C — Consolidate (documented only; no merges performed)

| Pair / group | Recommendation |
|--------------|----------------|
| `test-reports-stage14-phase6.php` + `test-reports-stage14-phase6-ui.php` | Keep both — service vs admin UI coverage |
| `test-customer-order-detail.php` + `verify-order-items-display.php` | Keep both — automated vs read-only display audit |
| Phase 4 validation trio | Keep all three scripts (validate / deep / fresh install) |

### D — Remove / quarantine

| File | Action |
|------|--------|
| `scripts/qa-customer-order-detail.php` | **Quarantined** (see manifest) |

### E — Manual review

| Item | Why |
|------|-----|
| `storage/audit-demo-test-data.json`, `storage/phase3-cleanup-manifest.json` | Regeneratable; kept as audit evidence |
| `DEMO_TEST_DATA_AUDIT.md` | Phase 2.3 evidence |
| `storage/invoices/*.pdf` | Possible dev invoice; not removed |
| `verify-order-items-display.php` | Unreferenced manual diagnostic |
| Future: add `storage/*.json` to `.gitignore` if team prefers not to commit snapshots |

---

## 3. Dependency checks (quarantined file)

| Check | Result |
|-------|--------|
| `rg qa-customer-order-detail` | **No matches** after quarantine |
| PHP `require`/`include` of quarantined file | **None** |
| Cron / CI references | **None** found |
| Docs references | **None** |

---

## 4. Safe cleanup process applied

1. Inventory + reference scan (`rg`, `PROJECT_PROGRESS.md`, `database/README.md`, phase docs).
2. Manifest drafted (`PHASE5_DELETION_MANIFEST.md`).
3. **One** file moved to `/home/admin1/apni_tokri_phase5_quarantine/scripts/` (outside `/var/www/html/Apni_Tokri`).
4. Regression tests run (below).
5. `git status`: deletion of `scripts/qa-customer-order-detail.php` only from this phase (other modified files pre-existing).

**Not done:** Permanent delete, bulk `test-*` removal, storage JSON removal, database or upload cleanup.

---

## 5. Verification results (actual)

| Command | Result |
|---------|--------|
| `php scripts/test-production-readiness.php` | **PASS** |
| `php scripts/test-phase22-production-blockers.php` | **PASS** |
| `php scripts/validate-apni-tokri-production-sql.php` | **PASS** (89 tables) |
| `php scripts/run-phase4-fresh-install-audit.php` | **pass: true** |
| `php scripts/test-customer-order-detail.php` | **PASS** (16 checks) |
| `php -l` on all remaining `scripts/**/*.php` | **OK** |
| Cron scripts present | **6/6** unchanged |

Browser verification: **not performed** (not claimed).

---

## 6. Files retained / removed / manual

| Category | Count |
|----------|------:|
| Retained script files | **110** (+ 18 includes) |
| Quarantined | **1** |
| Permanently deleted | **0** |
| Consolidated (merged) | **0** |
| Manual review | **6** paths (see manifest) |

---

## 7. Remaining risks

- **Large test surface (~85 scripts):** Maintenance cost remains; consider a documented “smoke suite” in `PROJECT_PROGRESS.md` (already partially listed for Phase 2.2 / Stage 13).
- **Generated JSON in `storage/`:** May drift from DB; regenerate before cleanup phases.
- **Quarantine retention:** Restore path documented in manifest; permanent delete only after team sign-off.
- **Unreferenced tests:** Still valuable for regression; do not delete without mapping to active features.

---

## 8. Related deliverables

- [PHASE5_DELETION_MANIFEST.md](./PHASE5_DELETION_MANIFEST.md) — line-item manifest and restore instructions.
