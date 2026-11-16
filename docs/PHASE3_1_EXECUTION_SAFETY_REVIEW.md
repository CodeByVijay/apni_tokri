# Phase 3.1 — Tier A pre-execution safety review

**Date:** 2026-11-03  
**Method:** SELECT-only validation on connected local DSN (`apni_tokri`); no DELETE/UPDATE/TRUNCATE/ALTER.  
**Inputs:** [PHASE3_CLEANUP_PLAN.md](./PHASE3_CLEANUP_PLAN.md), [storage/phase3-cleanup-manifest.json](../storage/phase3-cleanup-manifest.json), `scripts/preview-phase3-cleanup.php`, [DEMO_TEST_DATA_AUDIT.md](../DEMO_TEST_DATA_AUDIT.md)  
**Automation:** `php scripts/phase3-1-tier-a-safety-review.php` → [storage/phase3-1-safety-review.json](../storage/phase3-1-safety-review.json)

---

## Final status: **READY FOR MANUAL APPROVAL**

No automated Tier A **blockers** were found on the current database snapshot. Execution still requires **human sign-off**, **full backup**, and **staging rehearsal** before any DELETE on a shared environment.

---

## 1. Scope of this review

| In scope | Out of scope |
|----------|----------------|
| Tier A fixture markers only | Tier B bulk (5,671+ subscriptions, testimonials, tickets, webhooks) |
| FK / app relationship tracing | Actual cleanup execution |
| Protected-record overlap checks | Production DSN changes |

---

## 2. Tier A root verification (fixture ↔ primary key)

| Scope | Fixture source | Marker | Root count | PK sample verified |
|-------|----------------|--------|----------:|---------------------|
| C1 orders | `scripts/includes/stage12_phase_c1_fixture.php` | `AT-C1FIX-%` | **14** | e.g. `1794` / `AT-C1FIX-65BA51FD` |
| C1 refunds | Same + `test-trust-stage12-phase-c1.php` | `RF-C1%` | **14** | e.g. `RF-C1T-65ba51fd` → `order_id` 1794 |
| C1 subscriptions | Same | `SUB-C1FIX-%` | **14** | Linked to C1 customers |
| C1 customers | Same | `987C1%` | **28** | Mobile pattern `987C1A/B` + token |
| SUB-PG | `scripts/test-account-list-pagination.php` | `SUB-PG-%` | **75** | Pagination test subs |
| SUB-H | `SubscriptionTestFulfilmentFixture` | `SUB-H-%` | **1** | Fulfilment test sub |
| ZT-SUB | `scripts/test-subscription-admin-delete.php` | `ZT-SUB-%` | **1** | Admin delete test sub |
| AT-ACCT | `scripts/test-customer-account.php` | `AT-ACCT%` | **2** | Account UI tests |
| `at-%` product | Stage 13 SEO fixture | `at-%` slug | **1** | `id` 51 / `at-seo13-prod-b15fd0ec` |
| Zero markers | Various | C3, D2H, AT-PAY, AT-MAP, … | **0** | Idempotent no-op |

Full root rows: `storage/phase3-1-safety-review.json` → `tier_a_roots`.

---

## 3. Deduplicated affected rows (expected at execution)

Counts below are **SELECT-verified** on 2026-11-03. Overlapping scopes (C1 orders ↔ C1 refunds) must be executed **once** via unified C1 teardown — do not sum scopes blindly.

### 3.1 Stage 12 C1 graph (single teardown unit)

| Table | Expected rows | Notes |
|-------|--------------:|-------|
| `refund_request_history` | 42 | 3 history rows × 14 requests |
| `refund_request_items` | 14 | |
| `refund_requests` | 14 | All `RF-C1%` |
| `payment_refunds` | 0 | None on C1 payments in current DB |
| `payments` | 14 | **All `simulate`** |
| `order_items` | 14 | |
| `orders` | 14 | `AT-C1FIX-%` only |
| `deliveries` | 0 | |
| `invoices` | 0 | **No invoice links** |
| `subscription_payments` | 14 | `subpay_c1fix_*` pattern |
| `customer_subscriptions` | 14 | `SUB-C1FIX-%` |
| `customers` | 28 | `987C1%` after subgraph cleared |

**Customer safety:** `customers_non_c1_orders` = **0**; `customers_linked_invoices` = **0**; `987C1` customers with non-C1FIX subs = **0**.

### 3.2 `SUB-PG-%` (75 subscriptions)

| Table | Expected rows |
|-------|--------------:|
| `customer_subscriptions` | 75 |
| `subscription_payments` | 0 |
| `subscription_delivery_days` | 0 |
| Fulfilment `orders` | 0 |
| `subscription_skips` / `subscription_pauses` / `subscription_selection_audit` | 0 |

All 75 rows have **`subscription_plan_id` NULL** — **no** reference to default plans **1–3** (plans themselves are untouched).

### 3.3 `SUB-H-%` (1 subscription)

| Table | Expected rows |
|-------|--------------:|
| `subscription_delivery_days` | 4 |
| `subscription_delivery_baskets` / `_items` | 2 / 2 (per Phase 3 preview) |
| `subscription_stock_reservations` | 2 |
| `subscription_fulfilment_events` | 1 |
| `subscription_basket_substitutions` | **3** (added in 3.1 review) |
| `orders` (fulfilment) | 1 |
| `deliveries` | 1 |
| `invoices` / razorpay/cashfree on those orders | **0** |

Use **`SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix`** order: **deliveries → orders → `customer_subscriptions`**. Remaining child rows on `subscription_delivery_days` should **CASCADE** when the subscription (or days) are removed; rehearse on staging clone to confirm no FK RESTRICT (e.g. `subscription_stock_reservations` → variant is RESTRICT on variant, not on day delete).

### 3.4 `ZT-SUB-%` (1 subscription)

| Table | Expected rows |
|-------|--------------:|
| `subscription_delivery_days` | 0 |
| Fulfilment orders / deliveries | 0 |
| `customer_subscriptions` | 1 |

Deleting the subscription row is sufficient if no days exist; verify customer row is not shared before optional customer cleanup.

### 3.5 `AT-ACCT%` orders (2)

| Table | Expected rows |
|-------|--------------:|
| `orders` | 2 |
| `order_items` | 0 |
| `payments` | 0 |
| `invoices` | 0 |

### 3.6 `at-%` product (1) — **manual approval**

| Check | Result |
|-------|--------|
| `order_items` via `variant_id` | **0** |
| `inventory_movements` | **0** |

Safe to delete product + variants **after** manual sign-off; not equivalent to demo catalogue purge.

### 3.7 Tier A total (non-overlapping estimate)

| Category | Approx. rows touched |
|----------|---------------------:|
| C1 unified graph | ~168 (includes 28 customers) |
| SUB-PG | 75 |
| SUB-H subgraph | ~16+ (incl. substitutions) |
| ZT-SUB | 1 |
| AT-ACCT orders | 2 |
| `at-%` product (+ variants) | 1 + variants |
| **Order of magnitude** | **~260–280** rows across tables |

---

## 4. Mandatory exclusions — verified untouched by Tier A

| Exclusion | Verification |
|-----------|--------------|
| Razorpay/Cashfree payments | C1/ACCT/SUB-H fulfilment: **0** non-simulate in Tier A graphs; **15** total live-gateway payments remain outside markers |
| Invoices / `invoice_sequences` | **0** Tier A order links; **4** invoices + **2** sequences unchanged |
| Normal `AT-{ymd}-{token}` orders | **Not** in Tier A markers; overlap check `tier_a_touch_storefront_at_ymd` = **0** |
| `settings`, `admin_users`, `admin_audit_logs` | Counts unchanged by SELECT scope |
| Default subscription plans 1–3 | **Present**; SUB-PG does not reference them |
| Tier B bulk | Not part of Tier A execution |

---

## 5. Admin delete service vs subscription teardown

| Mechanism | Deletes `customer_subscriptions`? |
|-----------|-------------------------------------|
| `admin_delete_entity()` | **No** — only plans, menu templates, CMS, catalogue, etc. |
| `SubscriptionPlanRepository::deleteForAdmin()` | **No** — blocks if subscriptions reference plan |
| `SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix()` | **Yes** — scoped by prefix; deletes deliveries + fulfilment orders first |
| `stage12_phase_c1_teardown()` | **Yes** — scoped C1 orders/subs/customers |

Tier A must **not** use admin plan delete for SUB-PG/SUB-H/ZT; use prefix purge or C1 teardown patterns.

---

## 6. Transaction boundaries and rollback

| Recommendation | Detail |
|----------------|--------|
| Backup | Full `mysqldump --single-transaction` + finance table exports (Phase 3 §7) |
| Staging | Run identical scoped DELETE on **mysqldump clone** first |
| Transactions | Wrap each **scope batch** in `BEGIN … COMMIT`; on error `ROLLBACK` |
| C1 reference | `stage12_phase_c1_teardown()` uses scoped DELETE without a single transaction — prefer explicit transaction on staging |
| Rollback | Restore dump; re-run `phase3-1-tier-a-safety-review.php` and preview script |

---

## 7. Idempotency

| Condition | Behaviour |
|-----------|-------------|
| Re-run after successful Tier A | Scoped `DELETE … AND marker` affects **0** rows |
| Zero-marker scopes (C3, D2H, …) | Already **0** rows — no-op |
| Partial failed run | Re-run only after fixing FK error; SELECT counts before retry |

---

## 8. Disposable clone validation

| Step | Result |
|------|--------|
| Clone requirement | **Recommended:** `mysqldump apni_tokri \| mysql apni_tokri_tier_a_rehearsal` before first DELETE |
| This review | SELECT counts run on **`apni_tokri`** (local QA); matches Phase 3 manifest root counts |
| Re-verify | `php scripts/preview-phase3-cleanup.php` && `php scripts/phase3-1-tier-a-safety-review.php` |

---

## 9. Exact Tier A deletion order (combined run)

1. **`payment_refunds`** linked to Tier A payments / refund requests (C1 + ACCT if any).
2. **`refund_request_evidence`**, **`refund_request_items`**, **`refund_request_history`** → **`refund_requests`** (`RF-C1%`).
3. **`payments`** for C1 (`pay_c1fix_%` / C1 order ids) and ACCT orders if present.
4. **`delivery_status_history`** → **`deliveries`** for Tier A order ids.
5. **`delivery_slot_reservations`**, **`order_items`**, **`order_status_history`** → **`orders`** (`AT-C1FIX-%`, `AT-ACCT%`).
6. **SUB-H / ZT fulfilment:** **`deliveries`** → **`orders`** (`subscription_delivery_day_id` on scoped subs) — mirror `purgeTestSubscriptionsByNumberPrefix`.
7. **Subscription children** (CASCADE or explicit if rehearsal shows FK errors): **`subscription_substitution_payments`**, **`subscription_basket_substitutions`**, **`subscription_stock_reservations`**, **`subscription_fulfilment_events`**, basket items/baskets, **`subscription_delivery_days`**.
8. **`subscription_payments`** (C1 subs).
9. **`customer_subscriptions`** (`SUB-C1FIX-%`, `SUB-PG-%`, `SUB-H-%`, `ZT-SUB-%`).
10. **`customers`** (`987C1%`) only after step 9 and address/OTP review (counts likely low).
11. **`product_variants` / images** → **`products`** (`slug LIKE 'at-%'`) — **manual gate**.

---

## 10. Manual approval requirements (execution gate)

| # | Requirement |
|---|-------------|
| 1 | Engineering sign-off on Tier A scope only (not Tier B) |
| 2 | Full DB backup verified restorable |
| 3 | Staging clone rehearsal with row-count before/after |
| 4 | Explicit approval for **75× SUB-PG** subscription deletes |
| 5 | Explicit approval for **28× C1 customer** deletes |
| 6 | **`at-%` product** delete approved separately |
| 7 | Confirm **15** razorpay/cashfree payments and **4** invoices remain post-run |

---

## 11. Blockers

**None** for Tier A on current data.

If data drift occurs, re-run safety script; blockers trigger **`NOT READY — BLOCKERS FOUND`** when e.g. C1 orders gain invoices or live gateway payments.

---

## 12. Manifest update

No change required to `phase3-cleanup-manifest.json` root counts. **Addendum:** SUB-H scope should include **`subscription_basket_substitutions`: 3** in dependent counts (documented here; optional manifest refresh via re-run preview after Phase 3.1).

---

## 13. Related artifacts

- `storage/phase3-1-safety-review.json` — machine-readable roots + graphs  
- `scripts/phase3-1-tier-a-safety-review.php` — re-runnable SELECT audit
