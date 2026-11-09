# Phase 5 — Deletion / quarantine manifest

**Generated:** 2026-11-03  
**Quarantine root (outside repo):** `/home/admin1/apni_tokri_phase5_quarantine/`

Status legend: **Quarantined** | **Proposed** | **Retained** | **Manual review**

---

## Executed (high confidence)

| Path | Class | Reason | References | Action |
|------|-------|--------|------------|--------|
| `scripts/qa-customer-order-detail.php` | D — Remove | Superseded by `scripts/test-customer-order-detail.php`; **zero** repo references; overlapping “customer order detail” QA with DB fixtures | None | **Quarantined** → `/home/admin1/apni_tokri_phase5_quarantine/scripts/qa-customer-order-detail.php` |
| `scripts/test-verify-otp-name.php` | D — Remove | UI assertions merged into `scripts/test-verify-otp-registration.php` | Docs updated | **Deleted** (2026-11-09) |
| `scripts/test-verify-otp-test-display.php` | D — Remove | OTP display gates merged into `scripts/test-verify-otp-registration.php` | — | **Deleted** (2026-11-09) |
| `scripts/test-order-details-retry-payment-cta.php` | D — Remove | Static CTA checks merged into `scripts/test-order-details-retry-payment-flow.php` | — | **Deleted** (2026-11-09) |
| `scripts/test-phase21-demo-fallback-audit.php` | D — Remove | Merged into `scripts/test-production-readiness.php` | PROJECT_PROGRESS | **Deleted** (2026-11-09) |
| `scripts/test-storefront-dev-tools-production.php` | D — Remove | Merged into `scripts/test-production-readiness.php` | — | **Deleted** (2026-11-09) |
| `scripts/test-storefront-production-ui.php` | D — Remove | Merged into `scripts/test-production-readiness.php` | — | **Deleted** (2026-11-09) |

**Restore if needed:** `cp /home/admin1/apni_tokri_phase5_quarantine/scripts/qa-customer-order-detail.php scripts/`

---

## Proposed — not executed (insufficient confidence)

| Path | Class | Notes |
|------|-------|-------|
| `scripts/verify-order-items-display.php` | E | Read-only admin/customer line-item diagnostic; unreferenced in docs but useful for manual QA |
| `scripts/test-reports-stage14-phase6-ui.php` | B | Admin UI regression for reports phase 6; unreferenced in markdown but distinct from `test-reports-stage14-phase6.php` |
| `storage/audit-demo-test-data.json` | E | Regeneratable; cited in Phase 2.3 / Phase 3 docs |
| `storage/phase3-cleanup-manifest.json` | E | Regeneratable via `preview-phase3-cleanup.php` |
| `storage/invoices/*.pdf` | E | Dev invoice PDF artifact; do not delete without finance review |
| `DEMO_TEST_DATA_AUDIT.md` (repo root) | E | Historical audit evidence |

---

## Explicit retain list (never delete in Phase 5)

| Path | Class |
|------|-------|
| `database/migrations/**` | A |
| `database/production/apni_tokri_production.sql` | A |
| `database/production/README.md` | A |
| `scripts/build-apni-tokri-production-sql.php` | A |
| `scripts/validate-apni-tokri-production-sql.php` | A |
| `scripts/verify-install.php` | A |
| `scripts/audit-production-sql-schema-deep.php` | A |
| `scripts/run-phase4-fresh-install-audit.php` | A |
| `scripts/audit-demo-test-data-readonly.php` | A |
| `scripts/preview-phase3-cleanup.php` | A |
| `scripts/seed-catalogue.php` | A |
| `scripts/test-production-readiness.php` | B (includes former phase21 + dev-surface + storefront UI checks) |
| `scripts/test-phase22-production-blockers.php` | B |
| `scripts/test-cli-guard-probe.php` | B (used by production-readiness) |
| `scripts/includes/*_fixture.php` | B |
| `scripts/d3-sms-concurrent-worker.php` | B (used by `test-delivery-d3-hardening.php`) |
| `scripts/invoice-storage-audit.php` | A (documented in `docs/invoice-production-deployment.md`) |
| `cron/*.php` (6 files) | A |

---

## Summary counts

| Metric | Count |
|--------|------:|
| Files inspected (scripts tree) | **111** PHP (+ **18** under `scripts/includes/`) |
| Cron scripts verified | **6** |
| Quarantined | **1** |
| Permanently deleted from repo | **0** (quarantine only) |
| Proposed / manual review | **6** paths |
| Retained scripts | **110** in `scripts/` + fixtures |
