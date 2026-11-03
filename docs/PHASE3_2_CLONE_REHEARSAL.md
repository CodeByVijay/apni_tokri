# Phase 3.2 — Tier A cleanup clone rehearsal

**Date:** 2026-11-03  
**Prerequisite:** Phase 3.1 status **READY FOR MANUAL APPROVAL** ([PHASE3_1_EXECUTION_SAFETY_REVIEW.md](./PHASE3_1_EXECUTION_SAFETY_REVIEW.md))  
**Automation:** `PHASE32_REHEARSAL=1 php scripts/phase3-2-tier-a-clone-rehearsal.php`  
**Machine report:** [storage/phase3-2-rehearsal-report.json](../storage/phase3-2-rehearsal-report.json)

---

## Final status: **REHEARSAL PASSED — READY FOR EXPLICIT CLEANUP APPROVAL**

Tier A DELETE steps completed successfully on a disposable clone. The configured application database (`apni_tokri`) was **not** modified. Protected finance, admin, settings, subscription plans 1–3, normal storefront orders, and catalogue row counts on the clone matched pre-rehearsal snapshots.

**This is not authorization to DELETE on dev or production.** The next step is explicit human approval plus a separate execution runbook (backup, maintenance window, rollback plan).

---

## 1. Scope and restrictions (observed)

| Rule | Result |
|------|--------|
| DELETE only on disposable clone | Yes — single transaction on `apni_tokri_tier_a_rehearsal_20261103150845` |
| No DELETE on configured source DSN | Yes — source Tier A markers unchanged after rehearsal |
| No `config.local.php` / `database.name` change | Yes — still `apni_tokri` |
| No invoice or razorpay/cashfree payment deletes | Yes — counts unchanged (15 gateway payments, 4 invoices) |
| No manual-review `at-%` product delete | Yes — `at_product` marker remained **1** |
| No Tier B / bulk scopes | Not executed |

---

## 2. Step 1 — Backup and restore

### 2.1 Source backup

| Item | Value |
|------|--------|
| Source database | `apni_tokri` @ `127.0.0.1` |
| Tool | `mysqldump --single-transaction --routines --triggers` |
| Output file | `storage/backups/phase3_2_source_20261103150845.sql` |
| Size | **16,082,030** bytes (~15.3 MiB) |
| Integrity checks | Valid MySQL dump header (8.0.46); footer `Dump completed on 2026-11-03 15:08:46`; size &gt; 1 KiB threshold in script |

### 2.2 Clone identity

| Item | Value |
|------|--------|
| Clone database name | `apni_tokri_tier_a_rehearsal_20261103150845` |
| Credentials | Same host/user as local config; **different database name only** |
| Application wiring | `App::config()['database']['name']` remained `apni_tokri` — no app path points at the clone |

### 2.3 Restore and parity

| Check | Source | Clone | Result |
|-------|-------:|------:|--------|
| Base table count | 89 | 89 | **Match** |
| Tier A marker precheck | See §4 | Same as Phase 3.1 expectations | **Pass** |

The clone database remains on the server for optional inspection. It can be dropped when no longer needed:

```sql
DROP DATABASE IF EXISTS `apni_tokri_tier_a_rehearsal_20261103150845`;
```

---

## 3. Step 2 — Dependency verification (pre-delete)

Rehearsal relied on Phase 3.1 SELECT-only tracing ([storage/phase3-1-safety-review.json](../storage/phase3-1-safety-review.json)) and the documented teardown order in [PHASE3_CLEANUP_PLAN.md](./PHASE3_CLEANUP_PLAN.md) / [storage/phase3-cleanup-manifest.json](../storage/phase3-cleanup-manifest.json).

Before DELETE on the clone, the script enforced marker counts against Phase 3.1 expectations:

| Marker | Expected | Clone (before) |
|--------|----------|----------------|
| `AT-C1FIX-%` orders | 14 | 14 |
| `RF-C1%` refunds | 14 | 14 |
| `SUB-C1FIX-%` subs | 14 | 14 |
| `987C1%` customers | 28 | 28 |
| `SUB-PG-%` subs | 75 | 75 |
| `SUB-H-%` subs | 1 | 1 |
| `ZT-SUB-%` subs | 1 | 1 |
| `AT-ACCT%` orders | 2 | 2 |

**FK / child order (executed on clone):** refund children → refund requests → C1 simulate payments → order lines/history → C1 and AT-ACCT orders → SUB-H fulfilment chain (deliveries, orders, substitution payments, basket substitutions, stock reservations, fulfilment events) → C1 subscription payments → subscription roots → `987C1` customers.

**Explicitly not in Tier A auto execution:** `at-%` product (manual scope), gateway captures, invoices, settings, admin audit.

---

## 4. Step 3 — Tier A execution (clone only)

- **Transaction:** One `BEGIN` … `COMMIT`; failure would roll back all steps.
- **Predicates:** Pattern-based markers aligned with manifest; no broadened WHERE clauses.
- **Total rows deleted (sum of `delete_log`):** **256**

### 4.1 Deletion log (actual row counts)

| Step | Rows deleted |
|------|-------------:|
| `payment_refunds` via RF-C1 | 0 |
| `payment_refunds` via C1 orders | 0 |
| `refund_request_history` RF-C1 | 42 |
| `refund_request_items` RF-C1 | 14 |
| `refund_request_evidence` RF-C1 | 0 |
| `refund_requests` RF-C1 | 14 |
| `payments` `pay_c1fix_%` | 14 |
| `payments` on C1 orders | 0 |
| `delivery_status_history` C1+ACCT | 0 |
| `deliveries` C1+ACCT | 0 |
| `delivery_slot_reservations` tier A orders | 0 |
| `order_items` C1+ACCT | 14 |
| `order_status_history` C1+ACCT | 0 |
| `orders` AT-C1FIX | 14 |
| `orders` AT-ACCT | 2 |
| `deliveries` SUB-H fulfilment | 1 |
| `orders` SUB-H fulfilment | 1 |
| `subscription_substitution_payments` SUB-H | 1 |
| `subscription_basket_substitutions` SUB-H | 3 |
| `subscription_stock_reservations` SUB-H | 2 |
| `subscription_fulfilment_events` SUB-H | 1 |
| `subscription_payments` C1 (`subpay_c1fix_%`) | 14 |
| `customer_subscriptions` SUB-C1FIX | 14 |
| `customer_subscriptions` SUB-PG | 75 |
| `customer_subscriptions` SUB-H | 1 |
| `customer_subscriptions` ZT-SUB | 1 |
| `customers` 987C1 | 28 |

### 4.2 Failed or skipped deletions

| Category | Detail |
|----------|--------|
| Failed steps | **None** |
| Skipped by design | `at-%` product (1 row); all razorpay/cashfree payments and invoices |
| Zero-row steps (expected) | `payment_refunds` (0 in DB), C1 delivery/reservation/history (0 rows on this snapshot), refund evidence (0) |

Counts align with Phase 3.1 expectations for the C1 graph (e.g. 42 history rows, 14 refund requests, 14 simulate payments, 14 order lines). SUB-H child deletes (3 substitutions, 2 reservations, etc.) match the updated manifest after Phase 3.1.

---

## 5. Step 4 — Post-cleanup validation

### 5.1 Tier A markers (clone, after)

| Marker | Before | After |
|--------|-------:|------:|
| C1 orders | 14 | **0** |
| C1 refunds | 14 | **0** |
| C1 subs | 14 | **0** |
| C1 customers | 28 | **0** |
| SUB-PG | 75 | **0** |
| SUB-H | 1 | **0** |
| ZT-SUB | 1 | **0** |
| AT-ACCT orders | 2 | **0** |
| `at-%` product (manual) | 1 | **1** |

### 5.2 Protected record comparison (clone)

| Metric | Before | After |
|--------|-------:|------:|
| Razorpay/Cashfree payments | 15 | 15 |
| Invoices | 4 | 4 |
| Invoice sequences | 2 | 2 |
| Settings | 95 | 95 |
| Admin users | 1 | 1 |
| Admin audit logs | 2,996 | 2,996 |
| Subscription plan id 1–3 | 1 each | 1 each |
| Storefront `AT-{ymd}-*` orders (excl. C1/ACCT) | 19 | 19 |
| Products (total) | 10 | 10 |

### 5.3 Integrity checks

| Check | Result |
|-------|--------|
| Orphan `order_items` (no parent order) | **0** |
| Full FK audit across all 89 tables | Not run as separate job; rehearsal used ordered DELETE + orphan sample |
| `scripts/preview-phase3-cleanup.php` on clone | Not re-run (preview uses configured DSN only). Equivalent check: clone **auto Tier A markers = 0** (except manual `at_product`) |

### 5.4 Source database unchanged

After rehearsal, **source** `apni_tokri` Tier A markers still match pre-rehearsal clone snapshot (14 / 14 / 14 / 28 / 75 / 1 / 1 / 2 / 1 for product). No DELETE was issued against the source connection used by the application.

---

## 6. Unexpected side effects

None observed on the clone within the validated snapshot:

- Protected payments, invoices, settings, admin, plans, and normal storefront order counts unchanged.
- Manual `at-%` product preserved.
- No transaction rollback; no script abort on row-count mismatch (precheck was against expected markers only, not per-step row caps).

---

## 7. Artifacts

| Artifact | Path |
|----------|------|
| Rehearsal script | `scripts/phase3-2-tier-a-clone-rehearsal.php` |
| JSON report | `storage/phase3-2-rehearsal-report.json` |
| QA mysqldump | `storage/backups/phase3_2_source_20261103150845.sql` |
| Phase 3.1 safety | `storage/phase3-1-safety-review.json` |
| Manifest | `storage/phase3-cleanup-manifest.json` |

---

## 8. Recommended next steps (human)

1. **Explicit approval** to run the same ordered DELETE against a chosen environment (typically staging clone first, not production).
2. **Fresh mysqldump** immediately before live execution (do not rely solely on this rehearsal backup if data drifted).
3. Optional: extend `preview-phase3-cleanup.php` or Phase 3.1 script with `PHASE32_CLONE_DB` / DSN override to re-run SELECT preview against a clone after cleanup.
4. Drop rehearsal clone when finished inspecting.
5. Tier B and `at-%` product remain **out of scope** until separate sign-off.

---

## 9. Sign-off block

| Role | Name | Date | Approve Tier A on staging? |
|------|------|------|----------------------------|
| Engineering | | | |
| Product / QA | | | |

**Automated rehearsal verdict:** **REHEARSAL PASSED — READY FOR EXPLICIT CLEANUP APPROVAL**
