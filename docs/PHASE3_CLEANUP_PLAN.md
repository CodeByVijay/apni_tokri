# Phase 3 — Database cleanup plan (read-only)

**Status:** Planning only — **no DELETE/UPDATE/TRUNCATE has been executed.**  
**Evidence base:** [DEMO_TEST_DATA_AUDIT.md](../DEMO_TEST_DATA_AUDIT.md), [storage/audit-demo-test-data.json](../storage/audit-demo-test-data.json), [storage/phase3-cleanup-manifest.json](../storage/phase3-cleanup-manifest.json)  
**Preview tool:** `php scripts/preview-phase3-cleanup.php` (SELECT-only)

**Database snapshot:** `app.env=local`, schema `1.20.0-invoices`, connected DSN unchanged.

---

## 1. Objectives and constraints

| Rule | Application |
|------|-------------|
| Fixture-scoped cleanup first | Tier A markers from `scripts/includes/*_fixture.php` and documented test prefixes |
| No customer-wide DELETE unless verified | `customers` → `ON DELETE CASCADE` to `customer_subscriptions` |
| Protect finance | No delete of **razorpay/cashfree** captures without explicit approval; do not reset `invoice_sequences` |
| No catalogue row-delete with live order lines | Demo products tied to `order_items` via `variant_id` |
| Staging before production | Bulk Tier B only on a **clone** after full backup |

---

## 2. Classification summary

Counts are **planning indicators** (Tier B totals overlap; Tier A roots are listed per scope in the manifest).

| Classification | Approx. row signal (this DB) | Action in future cleanup phase |
|----------------|------------------------------:|--------------------------------|
| **Confirmed QA/test** | **149** root rows across Tier A scopes | DELETE by marker after backup; reuse fixture teardown order |
| **Confirmed demo/seed** | **9** demo product slugs (+ categories/variants) | Content migration / re-seed — not blind DELETE |
| **Likely QA — verify** | **22** storefront orders w/ simulate capture; **1** `at-%` product | Per-record review |
| **QA/debug bulk** | **~9,679** (5,671 subs + 487 testimonials + 544 tickets + 2,977 webhook events + …) | Engineering + finance signoff; staging only |
| **Genuine / protect** | **95** settings + **1** admin (+ RBAC config) | Never bulk-delete |
| **Unknown / manual** | **~53** storefront orders without simulate heuristic; **4** invoices | Case-by-case |

Regenerate counts:

```bash
php scripts/audit-demo-test-data-readonly.php | tee storage/audit-demo-test-data.json
php scripts/preview-phase3-cleanup.php
```

---

## 3. Tier A — fixture-scoped cleanup manifest

Each row maps to a **scope** in `phase3-cleanup-manifest.json` → `tier_a_fixture_scopes[]`.

### 3.1 High-confidence markers (present in this DB)

| Scope ID | Root table | PK / identifier | Rows | Classification | Fixture / script | Proposed operation | Approval |
|----------|------------|-----------------|-----:|----------------|------------------|--------------------|----------|
| `stage12_c1_orders` | `orders` | `id`, `order_number` `AT-C1FIX-%` | **14** | Confirmed QA | `stage12_phase_c1_fixture.php` → `stage12_phase_c1_teardown` | Child-first delete per teardown | Automatic |
| `stage12_c1_refunds` | `refund_requests` | `id`, `request_number` `RF-C1%` | **14** | Confirmed QA | Same | Delete `payment_refunds` → children → request | Automatic |
| `stage12_c1_subscriptions` | `customer_subscriptions` | `subscription_number` `SUB-C1FIX-%` | **14** | Confirmed QA | Same (`subpay_c1fix_*`) | Delete sub payments → sub | Automatic |
| `stage12_c1_customers` | `customers` | `mobile` `987C1%` | **28** | Confirmed QA | Same | DELETE only after C1 orders/subs gone; **0** non-C1 orders on these customers (verified) | Auto + dependency check |
| `account_pagination_sub_pg` | `customer_subscriptions` | `subscription_number` `SUB-PG-%` | **75** | Confirmed QA | `test-account-list-pagination.php` | Purge subs (no delivery days in DB — low child count) | Automatic |
| `sub_fulfil_sub_h` | `customer_subscriptions` | `SUB-H-%` | **1** | Confirmed QA | `SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix` | Use fixture purge order (deliveries → orders → sub) | Automatic |
| `admin_delete_zt_sub` | `customer_subscriptions` | `ZT-SUB-%` | **1** | Confirmed QA | `test-subscription-admin-delete.php` | Same as SUB-H pattern | Automatic |
| `customer_account_at_acct` | `orders` | `order_number` `AT-ACCT%` | **2** | Confirmed QA | `test-customer-account.php` | Order subgraph delete | Automatic |
| `stage13_product_at_prefix` | `products` | `slug` `at-%` | **1** | Likely QA | Stage 13/14 fixtures | Delete variants/images first **if** no `order_items` reference | **Manual** |

### 3.2 Documented markers — zero rows (clean or never run)

| Scope ID | Marker | Fixture |
|----------|--------|---------|
| `stage12_c3_orders` | `AT-C3-%` | `stage12_phase_c3_fixture.php` |
| `d2h_orders` | `D2H-%` | `d2h_test_fixture.php` |
| `payment_init_at_pay` | `AT-PAY-%` | `test-payment-initiation.php` |
| `address_map_at_map` | `AT-MAP-%` | `test-address-map-pin.php` |
| `reports_at_r145` | `AT-R145-%` | `test-reports-stage14-phase5.php` |
| `stage13_cms_slugs` | `at-cms13-%` | `stage13_phase1_fixture.php` |
| `rbac14_test_roles` | `at-rbac14-%` | `stage14_phase1_fixture.php` |

### 3.3 Tier A — expected dependent rows (this DB, from preview)

**Stage 12 C1 graph (overlapping — execute once via teardown, do not double-count scopes):**

| Table | Expected rows | Parent / FK |
|-------|--------------:|-------------|
| `refund_request_history` | 42 | `refund_request_id` → `refund_requests` |
| `refund_request_items` | 14 | → `refund_requests` |
| `refund_requests` | 14 | → `orders` |
| `payments` | 14 | → `orders` (`pay_c1fix_*`) |
| `order_items` | 14 | → `orders` |
| `orders` | 14 | root `AT-C1FIX-%` |
| `subscription_payments` | 14 | → `customer_subscriptions` |
| `customer_subscriptions` | 14 | `SUB-C1FIX-%` |
| `customers` | 28 | `987C1%` (after subs/orders) |

**`SUB-H-` subscription (1 sub):**

| Table | Rows |
|-------|-----:|
| `subscription_delivery_days` | 4 |
| `subscription_delivery_baskets` / `_items` | 2 / 2 |
| `subscription_stock_reservations` | 2 |
| `subscription_fulfilment_events` | 1 |
| `orders` (fulfilment) | 1 |
| `deliveries` | 1 |

**`SUB-PG-` (75 subs):** no `subscription_delivery_days` rows — **75** `customer_subscriptions` only (verify each run of pagination test).

**`AT-ACCT*` (2 orders):** run `order_dependent_counts` in preview for line-level totals before execution.

### 3.4 Tier A — suggested execution order (per scope)

Align with existing teardown code where possible:

1. **C1 refunds:** `payment_refunds` → `refund_request_evidence` / `_items` / `_history` → `refund_requests` (`RF-C1%`).
2. **C1 orders:** `payment_refunds` (payment-linked) → `payments` (`pay_c1fix_%`) → `order_items` → `orders` (`AT-C1FIX-%`).
3. **C1 subscriptions:** `subscription_payments` (`subpay_c1fix_%`) → `customer_subscriptions` (`SUB-C1FIX-%`).
4. **C1 customers:** `customers` (`987C1%`) only if no remaining FKs (verified: no non-C1 orders).
5. **Prefix subscriptions (`SUB-PG-`, `SUB-H-`, `ZT-SUB-`):** follow `SubscriptionTestFulfilmentFixture::purgeTestSubscriptionsByNumberPrefix` (deliveries → fulfilment orders → sub).
6. **`AT-ACCT*` orders:** same order subgraph pattern as C1 orders.
7. **Stage 13 `at-%` product:** manual — check `order_items` / `product_variants` first.

---

## 4. Tier B — bulk QA (manual approval required)

| Scope ID | Table(s) | Rows (this DB) | Classification | Proposed operation | Approval |
|----------|----------|---------------:|----------------|--------------------|----------|
| `bulk_subscription_qa_graph` | Subscription subgraph + `orders` (`order_source=subscription`) | **5,671** subs; **12,460** days; **1,452** sub orders; **2,128** simulate sub payments; **684** stock reservations | QA/debug | Staging-only purge: subs with **only** `simulate` `subscription_payments` and **no** invoice on linked orders | **Engineering + finance** |
| `bulk_testimonials_pending` | `testimonials` | **487** (all pending) | QA/debug | DELETE pending after confirming no production policy | Manual |
| `bulk_support_tickets` | `support_tickets`, `support_messages` | **544** / **592** | QA/debug | Delete messages then tickets for QA subjects only | Manual |
| `bulk_payment_webhook_events` | `payment_webhook_events` | **2,977** | QA/debug | Archive or truncate on staging | Manual |
| `bulk_subscription_menu_templates` | `subscription_menu_templates`, `_items` | **1,581** / **1,580** | QA/debug | Exclude plans **1–3** templates tied to business | Manual |
| `bulk_storefront_simulate_orders` | `orders` + children | **21** of **70** storefront | Likely QA | Per-order delete; **never** bulk `AT-%` | **Per order** |

**Subscription bulk eligibility (this DB):**

- Subscriptions with **only** simulate subscription payments: **5,671** (all sub payments are simulate).
- Subscriptions with **no** subscription_payment row: additional bucket — see manifest `subs_with_no_subscription_payment_row`.
- **Do not** include subscriptions whose fulfilment orders have **invoices** until finance maps each invoice.

---

## 5. Protected, excluded, and manual-review records

### 5.1 Do not delete (future phase)

| Item | Evidence | Rows |
|------|----------|-----:|
| `payments` razorpay/cashfree | Audit + preview `protect_real_gateway_payments` | **15** (order payments) |
| `settings`, `admin_users`, RBAC | Production config | **95** / **1** |
| `invoice_sequences` | Legal numbering | **2** |
| `invoices` | `test-invoices.php`; linked orders below | **4** |

**Invoice ↔ order links (manual):**

| Invoice id | Number | Order | Gateway |
|------------|--------|-------|---------|
| 143 | AT/2026-27/00001 | `UI146-a7af02-ORD` | simulate |
| 144 | ATCN/2026-27/00001 | `UI146-a7af02-ORD` | simulate |
| 163 | AT/2026-27/00003 | `UI-T-f808c9` | simulate |
| 183 | AT/2026-27/00006 | `AT-261103-4F2FA3` | simulate |

Deleting these orders requires **finance approval** and a documented invoice void/credit-note policy — **not** Tier A.

### 5.2 Demo catalogue (seed — not transactional QA)

| Table | Marker | Rows | Proposed operation |
|-------|--------|-----:|--------------------|
| `products` | slugs from `scripts/seed-catalogue.php` | **9** demo + **1** `at-%` | Replace via admin/import |
| `categories` | vegetables, fruits, cut, pickles | **4** demo | Same |
| `coupons` | `WELCOME10` | **1** | Keep or deactivate — merchant choice |

**Dependency exclusion:** demo products with **`order_items`** references — **do not DELETE** product rows until referencing orders are removed (Tier B). Preview: `protect_demo_catalogue_with_order_refs`.

### 5.3 Manual approval list (explicit)

| Record | Why |
|--------|-----|
| Order **`AT-261102-5DB0C9`** (id **3179**, customer **80**) | Hardcoded in `test-cart-order-post-payment.php`, `test-payment-cashfree-sync.php` |
| **49** storefront orders without simulate-captured heuristic | Normal `AT-{ymd}-{token}` format |
| **4** invoices + **`invoice_sequences`** | Tax/legal continuity |
| **15** non-simulate **payments** | Possible real gateway UAT |
| **Plans 1–3** (`subscription_plans.is_default_template`) | May be production templates vs **ZT *755294** test plans |
| **8** `cms_pages` | Legal/copy |
| **1** `seo_redirects` | SEO config |
| **Second coupon** (non-`WELCOME10`) | Unknown promotion intent |
| **`admin_audit_logs`** (**2,996**) | Compliance — archive policy, not ad-hoc DELETE |

---

## 6. Foreign-key–aware cleanup order (global)

Derived from `information_schema` (see manifest `foreign_key_graph`) and Phase 2.3 dependency map. **Do not assume CASCADE is safe** for merchant data — prefer marker-scoped DELETE.

### 6.1 Order-centric chain (storefront / C1 / ACCT)

```
payment_refunds → payments → refund_request_* → refund_requests
delivery_status_history → deliveries
order_items, order_status_history, delivery_slot_reservations
invoices (block order delete until approved)
orders
```

### 6.2 Subscription-centric chain

```
delivery_status_history → deliveries → orders (subscription_delivery_day_id)
subscription_stock_reservations, subscription_fulfilment_events
subscription_basket_substitutions, subscription_substitution_payments
subscription_delivery_basket_items → subscription_delivery_baskets
subscription_delivery_days
subscription_payments, subscription_skips, subscription_pauses, …
customer_subscriptions
customers (last; CASCADE risk)
```

### 6.3 Records excluded until dependencies cleared

| Blocked action | Reason |
|----------------|--------|
| DELETE demo **products** | `order_items.variant_id` → `product_variants.product_id` |
| DELETE **orders** with **invoices** | FK + legal |
| DELETE **subscription_stock_reservations** alone | Inventory integrity |
| DELETE **customers** with active non-test subs/orders | CASCADE |
| TRUNCATE **payment_webhook_events** on prod | May be audit-relevant — staging first |

---

## 7. Backup and recovery plan (before any future cleanup)

### 7.1 Full backup

```bash
mysqldump --single-transaction --routines --triggers \
  -u USER -p apni_tokri > backups/apni_tokri_pre_phase3_$(date +%Y%m%d_%H%M).sql
```

### 7.2 Table-level exports (finance minimum)

`orders`, `order_items`, `payments`, `payment_refunds`, `refund_requests`, `refund_request_items`, `refund_request_history`, `invoices`, `invoice_sequences`, `subscription_payments`, `customer_subscriptions`, `subscription_delivery_days`, `subscription_stock_reservations`.

### 7.3 Verify backup

1. Create empty DB `apni_tokri_restore_test`.
2. Import dump; run `php scripts/audit-demo-test-data-readonly.php` and compare table counts to pre-cleanup JSON.
3. Spot-check row counts for Tier A markers (e.g. `AT-C1FIX-%` should match pre-cleanup manifest).

### 7.4 Rollback

1. Stop application writes.
2. `DROP DATABASE apni_tokri; CREATE DATABASE …;` import full dump **or** restore from PITR.
3. Re-run audit + preview scripts; confirm marker counts restored.
4. Do **not** partial-restore single tables without FK analysis.

---

## 8. Dry-run tooling

| Script | Purpose |
|--------|---------|
| `scripts/preview-phase3-cleanup.php` | SELECT-only scopes, dependent counts, writes `storage/phase3-cleanup-manifest.json` |
| `scripts/audit-demo-test-data-readonly.php` | Baseline table counts and markers |

```bash
php scripts/preview-phase3-cleanup.php
php scripts/preview-phase3-cleanup.php --json   # manifest to stdout
```

---

## 9. Ambiguities and missing evidence

| Topic | Gap | Recommendation |
|-------|-----|----------------|
| Storefront **`AT-{ymd}-*`** orders | Indistinguishable from production format | Classify by payment gateway, customer, invoice link — not prefix |
| **`admin_audit_logs`** | No test marker | Retention policy / export, not DELETE in Phase 3 |
| **Notification / customer_notification** bulk (**9k+**) | No unique prefix | Tie to Tier B subscription purge or date cutover on staging |
| **Inventory movements** (**2,019**) | Mixed admin + checkout | Reconcile after order/sub cleanup |
| **Customer ids 69–80** | Invoice/UI test orders | Manual map to `test-invoices.php` / UI runs |
| **Webhook events** | May include non-test replay | Sample `event_id` before truncate |

---

## 10. Phase 4 readiness (execution — out of scope for Phase 3)

- [ ] Stakeholder sign-off on Tier B subscription strategy  
- [ ] Full + table backups verified on clone  
- [ ] Execute **Tier A only** on clone; re-run preview (expect zero roots)  
- [ ] Finance sign-off on invoice-linked orders  
- [ ] Production DSN isolation confirmed  
- [ ] Post-cleanup: `StorefrontReadiness::productionBlockers()` on target env  

**Phase 3 deliverable statement:** Planning artifacts only; database unchanged except read-only SELECTs during preview/audit.
