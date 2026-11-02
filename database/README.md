# Database

- **`database.sql`** — full fresh install baseline (**schema v1.1.0** in seed `settings`; does **not** include Stage 13 CMS columns by itself)
- **`migrations/`** — incremental upgrades for existing installs through **`1.9.0-stage13-cms-1`**

## Project progress

See [PROJECT_PROGRESS.md](../PROJECT_PROGRESS.md). **Current target schema:** **`1.9.0-stage13-cms-1`** (`Install::SCHEMA_VERSION`). Fresh installs: import `database.sql`, then apply migrations in version order through Stage 13 (see below). **Stage 13 Phase 9** did not re-verify a from-scratch empty DB import in this pass.

## Fresh install

```bash
mysql -u USER -p apni_tokri < database/database.sql
php scripts/verify-install.php
php scripts/seed-catalogue.php
```

## Upgrade from 1.0.0 → 1.1.0 (Stage 6)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.1.0_catalogue_attributes.sql
php scripts/seed-catalogue.php
php scripts/test-stock-concurrency.php
```

Default admin (change password after first login):

- Email: `admin@apnitokri.local`
- Password: `ChangeMe123!`

## Upgrade from 1.1.0 → 1.2.0 (Stage 7)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.2.0_commerce_checkout.sql
```

## Upgrade from 1.2.0 → 1.3.0 (Stage 8)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.3.0_delivery_engine.sql
```

When the database contains products, the storefront uses live catalogue, cart, checkout, and delivery rules.

## Upgrade from 1.3.0 → 1.4.0 (Stage 9)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.4.0_payment_gateways.sql
php scripts/test-payment-stage9.php
```

Configure gateways under **Admin → Settings → Payment gateways**. Webhooks: `/webhooks/razorpay.php` and `/webhooks/cashfree.php`.

## Upgrade from 1.4.0 → 1.5.0 (Stage 10)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.5.0_offers_coupons.sql
php scripts/test-promotions-stage10.php
```

Manage coupons and automatic offers in **Admin → Coupons** and **Admin → Offers**. Usage summary: **Admin → Reports → Coupons**.

## Upgrade from 1.5.0 → 1.6.0 (Stage 11 Phase A)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.0_subscription_plan_builder.sql
php scripts/test-subscription-stage11-phase-a.php
```

Admin **Subscriptions → Plans** for the plan builder; default 7/15/30 templates are seeded and editable.

## Upgrade from 1.6.0 → 1.6.1 (Stage 11 Phase B)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.1_subscription_checkout.sql
php scripts/test-subscription-stage11-phase-a.php
php scripts/test-subscription-stage11-phase-b.php
```

Storefront: `/subscriptions.php`, checkout via `/subscribe.php` and `/subscription-payment.php`. Reuses Stage 9 payment verification (simulate / Razorpay webhooks on `subscription_payments`).

Migration **1.6.1** uses `information_schema` checks so re-runs do not fail on existing columns (no manual error handling required).

## Upgrade from 1.6.1 → 1.6.2 (Stage 11 Phase C)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.2_subscription_menu_baskets.sql
php scripts/test-subscription-stage11-phase-c.php
```

Admin: **Subscriptions → Menu planner**, customer baskets and planned quantity reports. Customer calendar baskets on `subscription-details.php`.

## Upgrade from 1.6.2 → 1.6.3 (Stage 11 Phase D)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.3_subscription_lifecycle.sql
php scripts/test-subscription-stage11-phase-d.php
```

**Cron (CLI only, Asia/Kolkata cutoffs):**

```bash
php cron/subscription_auto_confirm_baskets.php
```

Example crontab: `*/10 * * * * php /path/to/Apni Tokri/cron/subscription_auto_confirm_baskets.php`

Features: auto-confirm after selection deadline, customer skip/carry-forward, pause/resume, admin basket override with customer notification (`customer_notifications`).

## Upgrade from 1.6.3 → 1.6.4 (Stage 11 Phase E)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.4_subscription_fulfilment.sql
php scripts/test-subscription-stage11-phase-e.php
php scripts/test-subscription-stage11.php
```

**Cron — generate delivery orders from confirmed baskets:**

```bash
php cron/subscription_generate_deliveries.php
```

Example crontab: `5,20,35,50 * * * * php /path/to/Apni Tokri/cron/subscription_generate_deliveries.php`

### Inventory reservation (basket confirm → fulfilment)

1. At fulfilment generation, `subscription_stock_reservations` rows are inserted with status **`held`** after a row-level stock check (`FOR UPDATE` on `product_variants`).
2. `InventoryService::availableQty()` subtracts all **`held`** reservations so normal checkout and other subscription days cannot oversell the same units.
3. On successful order creation, stock is decremented via existing `InventoryService::decrement()` and reservations move to **`deducted`**.
4. On failure before order commit, **`held`** reservations are **`released`** (transaction rollback also reverts decrements).

### Substitution workflow

- If stock is insufficient at fulfilment time, the system creates `subscription_basket_substitutions` with status **`pending_customer`** (never silent replace).
- Customer approves/rejects via `ajax/subscription/substitution.php`; extra charges (`price_difference_inr` > 0) cannot be approved in self-service.
- Approved substitutions update basket lines; fulfilment cron retries when `fulfilment_status` returns to **`none`**.

Orders use `order_source = subscription`, `payment_status = paid` (prepaid), and `subscription_delivery_day_id` for traceability. Delivery rows use the existing `deliveries` / admin queue — subscription days stay **`generated`** until the physical delivery workflow marks **`delivered`**.

## Upgrade from 1.6.4 → 1.6.5 (Stage 11 production hardening)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.5_subscription_hardening.sql
php scripts/test-subscription-stage11-hardening.php
php scripts/test-subscription-stage11.php
```

**Additional cron (reservation/refund recovery):**

```bash
php cron/subscription_recover_reservations.php
```

Suggested: hourly or every 30 minutes.

### Hardening highlights

- **Reservation recovery:** `SubscriptionReservationRecoveryService` + cron releases stale **`held`** rows; recovers **`deducted`** rows with no completed order; never releases holds for **`generated`** / completed fulfilment.
- **Stock deduction:** `deductHeldLines()` ties decrement to **`held`** reservations (concurrency-safe with fulfilment event locking).
- **Substitution reject:** customer chooses **`partial_delivery`** (plan flag `allow_partial_on_substitution_reject`) or **`cancel_day`** (entitlement credit via `subscription_day_credits` / `deliveries_remaining`).
- **Paid substitution:** `subscription_substitution_payments` + simulate/Razorpay verify; basket updates only after **`captured`** payment.
- **Slot preference:** `preferred_delivery_slot_slug` on subscription + per day; checkout on `subscribe.php`; unavailable preferred slot → **`slot_unavailable`** (no silent change).
- **Entitlement:** `deliveries_remaining` decrements once when admin marks delivery **`delivered`** (`subscription_delivery_entitlement_events` idempotency). Integration hook in `DeliveryAssignmentService::updateStatus`.
- **Restock:** failed/cancelled subscription deliveries restock once via `subscription_stock_restock_events`.

## Upgrade from 1.6.5 → 1.6.6 (Stage 11 production readiness)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.6.6_production_readiness.sql
php scripts/test-subscription-stage11-production-readiness.php
php scripts/test-subscription-stage11.php
```

Refund timestamp columns, recovery audit support, and production-readiness checks. **Gateway deferral (§11.10 in requirements):** Razorpay staging credentials, paid-substitution Checkout UI, substitution webhook branch, full test-mode E2E, and live refund APIs (Stage 12) remain pending after this migration.

## Upgrade from 1.6.6 → 1.7.0 (Stage 12 Phase A)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.7.0_stage12_reviews_support_refunds.sql
php scripts/test-trust-stage12.php
```

Reviews, testimonials, support tickets, refund requests, and gateway refund records. Admin **Reviews**, **Refunds**, **Support**, **Testimonials** use live tables when schema is current.

## Upgrade from 1.7.0 → 1.7.1 (Stage 12 Phase B)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.7.1_stage12_phase_b.sql
php scripts/test-trust-stage12-phase-b.php
```

Refund webhooks (Razorpay/Cashfree), support message attachments, subscription refund rows on `payment_refunds`, customer support inbox (`my-support.php`), homepage featured testimonials, admin **Refunds → Reconciliation** dashboard.

## Upgrade from 1.7.1 → 1.8.0 (Delivery D1 — zones & slot schema prep)

**Depends on:** 1.7.1  
**Rollback:** `database/migrations/rollback/1.8.0_rollback.sql` (manual; set `delivery.zone_resolution_mode` to `pincode_legacy` first)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_delivery_zones_slots.sql
php scripts/test-delivery-d1.php
php scripts/test-delivery-stage8.php
```

Adds distance/hybrid zone columns, delivery policy fields on `delivery_store`, slot columns for D2, nullable `product_variants.weight_kg`, and setting `delivery.zone_resolution_mode=pincode_legacy` (default). Admin: **Settings → Delivery → Zones / Policies**. Checkout and slot locking unchanged until D2–D4.

## Upgrade from 1.8.0 → 1.8.0-d2 (Delivery D2 — slot CRUD & reservations)

**Depends on:** 1.8.0  
**Rollback:** `database/migrations/rollback/1.8.0_d2_rollback.sql`

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d2_slot_management.sql
php scripts/test-delivery-d2.php
php scripts/test-delivery-stage8.php
```

Adds `delivery_slot_reservations` (checkout holds), `allowed_provider_slugs_json` on slots, row-locked capacity (paid orders + active holds), admin **Settings → Delivery → Slots**, and cron `delivery_slot_release_expired.php`.

## Upgrade from 1.8.0-d2 → 1.8.0-d2-hardening

**Depends on:** 1.8.0-d2  
**Pre-check:** `SELECT order_id, COUNT(*) c FROM delivery_slot_reservations WHERE order_id IS NOT NULL GROUP BY order_id HAVING c > 1;` must return no rows.

**Rollback:** `database/migrations/rollback/1.8.0_d2_hardening_rollback.sql` (drops `payment_completion_exceptions` only). Full reservation history loss only if you also run `1.8.0_d2_rollback.sql`.

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d2_hardening.sql
php scripts/test-delivery-d2-hardening.php
```

Adds `UNIQUE(order_id)` on reservations, `payment_completion_exceptions`, PDO `+05:30`, payment slot-failure reconciliation, admin **Payments → Completion exceptions**.

## Upgrade from 1.8.0-d2-hardening → 1.8.0-d3-manual (D3 manual courier)

**Depends on:** 1.8.0-d2-hardening  
**Rollback:** `database/migrations/rollback/1.8.0_d3_manual_delivery_rollback.sql`

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d3_manual_delivery.sql
php scripts/test-delivery-d3.php
php scripts/test-delivery-d2-hardening.php
```

Manual delivery statuses (`pending_dispatch` → `ready` → `dispatched` → `delivered`), courier name + booking reference, admin **Deliveries → Manage**, customer delivery timeline, `delivery_sms_logs` + simulate SMS (enable `sms.enabled` and configure provider for live sends). Legacy `queued`/`assigned` rows migrate to `pending_dispatch` with history notes preserved.

## D3 audit hardening (same schema **1.8.0-d3-manual**)

**Depends on:** 1.8.0-d3-manual  
**Rollback:** `database/migrations/rollback/1.8.0_d3_hardening_rollback.sql`

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d3_hardening.sql
php scripts/test-delivery-d3-hardening.php
```

Adds `delivery_sms_send_locks` (atomic SMS claim per delivery/event), configurable HTTP SMS success contract (`sms.http.*` settings), customer-visible history privacy (internal remarks `customer_visible=0`), admin SMS log masking. Live HTTP SMS still requires real provider credentials and staging validation — not claimed production-ready without that.

## Upgrade from 1.8.0-d3-manual → 1.8.0-d4-checkout (D4 checkout snapshot)

**Depends on:** 1.8.0-d3-manual (apply D3 hardening migration first if not already applied)  
**Rollback:** none (version bump only; `delivery_snapshot_json` column exists from earlier schema)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d4_checkout_snapshot.sql
php scripts/test-delivery-d4.php
```

Persists immutable checkout delivery quote on `orders.delivery_snapshot_json`. Payment completion revalidates slot/zone against snapshot policy.

## Upgrade from 1.8.0-d4-checkout → 1.8.0-d4-hardening (D4 audit hardening)

**Depends on:** 1.8.0-d4-checkout  
**Rollback:** `database/migrations/rollback/1.8.0_d4_hardening_rollback.sql`

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d4_hardening.sql
php scripts/test-delivery-d4.php
```

Adds `payment_completion_exceptions.failure_category` (delivery | inventory | address_drift | other), inventory/address-drift reconciliation, cart preview quote alignment, manual-fallback provider allow-list enforcement.

## Upgrade from 1.8.0-d4-hardening → 1.8.0-d5-subscription (D5 subscription fulfilment)

**Depends on:** 1.8.0-d4-hardening  
**Rollback:** `database/migrations/rollback/1.8.0_d5_subscription_fulfilment_rollback.sql` (schema label only)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_d5_subscription_fulfilment.sql
php scripts/test-subscription-d5.php
```

Subscription cron fulfilment uses `DeliveryQuoteService` + the same `delivery_snapshot_json` contract as checkout (no duplicate zone/fee logic). See `docs/subscription-fulfilment-delivery.md`.

## Upgrade to 1.8.0-stage12-c2-1 (Stage 12 Phase C.2.1 — review moderation history)

**Depends on:** 1.7.0+ (`product_reviews`); typical installs at **1.8.0-d5-subscription**.  
**Rollback:** `database/migrations/rollback/1.7.2_stage12_review_moderation_history_rollback.sql` (drops `review_moderation_history` and reverts schema label).

```bash
mysql -u USER -p apni_tokri < database/migrations/1.7.2_stage12_review_moderation_history.sql
php scripts/test-trust-stage12-phase-c2-1.php
```

Adds append-only `review_moderation_history` for admin review approve/reject/reply actions. Admin moderation reports UI is Phase C.2.2.

## Upgrade to 1.9.0-stage13-cms-1 (Stage 13 Phase 1 — CMS foundation)

**Depends on:** 1.8.0-stage12-c3-1 (or any install with `cms_pages`).  
**Rollback:** `database/migrations/rollback/1.9.0_stage13_cms_foundation_rollback.sql`

```bash
mysql -u USER -p apni_tokri < database/migrations/1.9.0_stage13_cms_foundation.sql
php scripts/test-cms-sanitizer-stage13.php
php scripts/test-cms-stage13-phase1.php
```

Adds `cms_page_versions`, CMS SEO/visibility columns, FAQ category `is_active`, FAQ fulltext/search index, and **draft** placeholder rows for legal slugs (footer-compatible: `terms`, `refund-policy`, etc.) — placeholders are **not published**.

**Stage 13 regression (Phases 1–9):**

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

Later phases (5–8) use existing tables (`seo_redirects`, `settings`, catalogue CMS columns); **no additional migrations** after 1.9.0 for Stage 13.

## Upgrade to 1.10.0-stage14-rbac-1 (Stage 14 Phase 1 — RBAC)

**Depends on:** 1.9.0-stage13-cms-1  
**Rollback:** `database/migrations/rollback/1.10.0_stage14_rbac_phase1_rollback.sql` (schema label only)

```bash
mysql -u USER -p apni_tokri < database/migrations/1.10.0_stage14_rbac_phase1.sql
php scripts/test-rbac-stage14-phase1.php
```

Idempotently seeds `admin_role_permissions` for system roles **order_manager**, **inventory_manager**, **support**, and **finance** (by slug). See `docs/rbac-permission-matrix.md`.

## Upgrade to 1.8.0-stage12-c3-1 (Stage 12 Phase C.3 Step 4 — webhook processing lease)

**Depends on:** 1.8.0-stage12-c2-1 (or any install with `payment_webhook_events`).  
**Rollback:** `database/migrations/rollback/1.8.0_stage12_c3_webhook_lease_rollback.sql`

```bash
mysql -u USER -p apni_tokri < database/migrations/1.8.0_stage12_c3_webhook_lease.sql
php scripts/test-trust-stage12-phase-c3.php
```

Adds `processing_started_at` and `worker_token` on `payment_webhook_events` for Cashfree lease fencing. Default lease TTL: 600 seconds (`payment.webhook_processing_lease_seconds` optional).

## Stage 12 Phase C.3 — Cashfree refund webhook hardening

Refund validation, merchant `order_id` for Cashfree refund API, official refund status mapping (API 2023-08-01).

Live Cashfree staging verification is still required before production sign-off (signed refund webhook end-to-end).
