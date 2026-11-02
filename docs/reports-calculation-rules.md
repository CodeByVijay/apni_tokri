# Reports — source tables and calculation rules (Stage 14.5)

## Source tables

| Report area | Primary tables |
|-------------|----------------|
| Sales summary | `orders` |
| Refunds (summary) | `payment_refunds`, `refund_requests` (order report status only) |
| Order list | `orders`, `order_items`, `customers`, `payments`, `refund_requests` |
| Inventory stock | `product_variants`, `products` |
| Inventory movements | `inventory_movements`, `product_variants`, `products` |

Subscription checkout orders appear in `orders` when `payment_status = 'paid'` (same rules as storefront).

## Business date (Asia/Kolkata)

All order and movement date filters use **`ReportDateRange`** with timezone **`Asia/Kolkata`**.

Order business timestamp:

```sql
COALESCE(o.placed_at, o.created_at)
```

Filter: `>= range start` and `< range end` (exclusive end).

Inventory movement filter: `m.created_at` with the same range bounds.

## Sales summary metrics

| Metric | Rule |
|--------|------|
| **Gross order value** | `SUM(o.grand_total)` where `payment_status = 'paid'` and `status <> 'cancelled'` |
| **Discounts** | `SUM(o.discount_total)` same paid, non-cancelled set |
| **Delivery charges** | `SUM(o.delivery_fee)` same set |
| **Refund amount** | `SUM(pr.amount_inr)` from `payment_refunds` where `gateway_status IN ('processed','completed','success')` and `pr.updated_at` in range (not joined to orders — avoids double count) |
| **Net sales** | Gross order value − Refund amount |
| **Total orders** | `COUNT(*)` all orders with business timestamp in range |
| **Paid orders** | Count where `payment_status = 'paid'` and `status <> 'cancelled'` (same set as revenue) |
| **Pending payment orders** | Count where `payment_status = 'pending'` |
| **Cancelled orders** | Count where `status = 'cancelled'` |
| **Average order value** | Gross order value ÷ paid orders (paid, non-cancelled only; 0 if none) |

Failed or unpaid orders are **not** included in gross, discount, or delivery revenue totals.

Aggregates use the **`orders`** table only (no join to `order_items`) so each order is counted once.

## Order report columns

| Column | Source |
|--------|--------|
| Order number | `orders.order_number` |
| Order date | Business timestamp (IST display) |
| Customer | `customers.mobile` or `Guest` |
| Order status | `orders.status` |
| Payment status | `orders.payment_status` |
| Payment method | Latest `payments.gateway` where `status = 'captured'` |
| Item count | Subquery `COUNT(*)` on `order_items` |
| Order total | `grand_total` |
| Discount | `discount_total` |
| Delivery charge | `delivery_fee` |
| Refund status | Latest `refund_requests.status` or `—` |

## Inventory

| Metric | Rule |
|--------|------|
| **Current stock** | `product_variants.stock_qty` (live field used by `InventoryService`) |
| **Low stock** | `stock_qty > 0` AND `stock_qty <= low_stock_threshold`, active variant + published product |
| **Out of stock** | `stock_qty <= 0`, active variant + published product |
| **Movement received** | `SUM(change_qty)` where `change_qty > 0` in date range |
| **Movement deducted** | `SUM(ABS(change_qty))` where `change_qty < 0` in date range |
| **Movement adjustments** | Count/sum of rows where `admin_user_id IS NOT NULL` (manual admin adjustments via `InventoryService::adjust`) |

**Stock valuation:** not implemented — no cost fields; reports do not show inventory value.

## CSV exports

Same filters and rules as on-screen reports. See `ReportCsvExport` for escaping and formula-injection prefix handling.

---

## Stage 14.6 — Financial timestamps and report services

### Timestamp columns (schema 1.15.0+)

| Column | Table | Set when |
|--------|--------|----------|
| `captured_at` | `payments` | First successful storefront capture in `PaymentService::completePayment()` |
| `captured_at` | `subscription_payments` | First successful capture in `SubscriptionActivationService::activateFromPayment()` |
| `completed_at` | `payment_refunds` | First terminal gateway success in `RefundCompletionService` |

**Semantics:** Application-side confirmation timestamps only — not guaranteed gateway event times or bank settlement times.

**Historical rows:** Columns remain `NULL` for records created before migration 1.15.0. Reports expose separate **legacy** counts for terminal/captured rows with missing timestamps. Do not backfill.

**First-write-only:** All writers use `COALESCE(column, NOW())`. Refunds do not modify `payments.captured_at`.

**Excluded from confirmed storefront collection:** `payments.status = 'reconciliation_required'` (see `payment_completion_exceptions`).

### Payment reports (`PaymentReportService`)

| Metric | Date basis | Notes |
|--------|------------|--------|
| Payment attempts | `payments.created_at` | Storefront orders only |
| Attempt-cohort success rate | Same cohort | `captured attempts ÷ total attempts` (current row status, not historical snapshot) |
| Confirmed collections | `payments.captured_at` | `status = 'captured'`, excludes subscription `order_source` |
| Unique paid orders | Same | `COUNT(DISTINCT orders.id)` |
| Captured amount | Same | `SUM(payments.amount)` — not `orders.grand_total` |
| Reconciliation | `payment_completion_exceptions.created_at` / `resolved_at` | Separate from collections |

### Refund reports (`RefundReportService`)

| Metric | Date basis | Notes |
|--------|------------|--------|
| Refund requests | `refund_requests.created_at` | Workflow counts |
| Completed refunds | `payment_refunds.completed_at` | Terminal statuses per `RefundCompletionService::isTerminalSuccess()` |
| Pending liability | Point-in-time | Approved open requests — not cash out |
| Legacy completed | N/A | Terminal `gateway_status` with `completed_at IS NULL` |

Stage 14.5 **sales summary** net refunds use the **unified completed-refund period policy** (Phase C): prefer `payment_refunds.completed_at`; for historical terminal rows with `completed_at IS NULL`, use `updated_at` as documented legacy fallback. Implemented in `RefundReportService::sqlUnifiedCompletedPeriodWhere()` and `SalesReportService::summary()`.

### Admin report pages (Stage 14.6 Phase C)

| Page | Path | Export type |
|------|------|-------------|
| Payments | `admin/reports/payments.php` | `payments` |
| Refunds | `admin/reports/refunds.php` | `refunds` |
| Subscriptions | `admin/reports/subscriptions.php` | `subscriptions` |

Dashboard: `admin/reports/index.php` links to all report modules.

**Authorization:** `reports.view` (via `AdminDemoPerms::denyIfCannot('reports')` → `reports.view`). CSV export requires `reports.export` on `admin/reports/export.php` (POST + CSRF).

**Pagination:** 50 rows per page for payment attempts, completed refunds, and captured subscription payments. Summary cards always use full-range aggregate queries (not the current page).

**CSV exports:** UTF-8 BOM, formula injection sanitization, filename allowlist, max **5000** detail rows per export (same as orders). No `response_json` or gateway secrets. Summary + detail rows use a fixed `Record type` column layout.

### Refund date policy — unified (Phase C applied)

| Surface | Period filter |
|---------|----------------|
| **Sales summary net refunds** | Unified: `completed_at` in range, **or** legacy terminal with `completed_at IS NULL` and `updated_at` in range |
| **Refund report “completed (completed_at period)”** | `completed_at` only (new completions) |
| **Refund report detail / net-sales aligned card** | Unified (same as sales) |
| **Legacy visibility** | All-time NULL `completed_at` terminal rows; in-period legacy subset on refund page |

Do **not** backfill `completed_at` from `updated_at`.

### Refund date policy — Stage 14.5 vs 14.6 (historical note)

Superseded by **unified policy (Phase C applied)** above. Prior to Phase C, sales summary used `updated_at` only; refund completed totals used `completed_at` only.

### Subscription reports (`SubscriptionReportService`)

| Metric | Date basis | Notes |
|--------|------------|--------|
| Activations / expected amount | `customer_subscriptions.activated_at` / `price_paid` | Not catalog plan price |
| Cash collected | `subscription_payments.captured_at` | `SUM(amount)` where captured |
| Pipeline | `subscription_payments.created_at` | Pending/failed — not revenue |
| Status snapshot | Point-in-time | `pending_payment`, `payment_failed`, `active`, `paused` only |
| Scheduled / delivered by plan | `subscription_delivery_days.delivery_date` | Calendar date — not courier time |
| Courier completion | `deliveries.delivered_at` | Subscription orders only |
| Generated fulfilment orders | `orders.placed_at` | `order_source = subscription`, `grand_total = 0` |

**Storefront vs subscription:** Subscription prepaid revenue is on `subscription_payments`. Subscription fulfilment orders must not inflate storefront payment collection reports.

### Duplicate-capture integrity

Payment and subscription collection summaries flag orders/subscriptions with more than one `captured` payment row in the reporting period (or globally for subscription duplicate check).

