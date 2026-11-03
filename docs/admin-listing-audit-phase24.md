# Phase 2.4 — Admin listing standardization audit

Audit date: Nov 2026. Standard: `includes/admin/server_list.php` + `admin_list_pagination()` (10/25/50/100), parameterized search, whitelisted sort, total counts, filter preservation.

Legend — **Implemented status:** ✅ Done · 🟡 Partial · ⬜ Deferred (by design) · 🔜 Recommended

| Admin page | Record source | Pagination | Search / sort | Scalability issue | Recommended action | Status |
|------------|---------------|------------|-------------|-------------------|-------------------|--------|
| `admin/orders/index.php` | `AdminOrderService::searchForAdmin` | Server GET | q + whitelist sort | Low | — | ✅ |
| `admin/payments/index.php` | `PaymentRepository::searchForAdmin` | Server GET | q + sort | Low | — | ✅ |
| `admin/customers/index.php` | `AdminDashboardService::searchCustomersForAdmin` | Server GET | q + sort | Low | — | ✅ |
| `admin/products/index.php` | `ProductRepository::searchForAdmin` | Server GET | q, category, status + sort | Low | — | ✅ |
| `admin/categories/index.php` | `CategoryRepository::searchForAdmin` | Server GET | q + sort | Low | — | ✅ |
| `admin/coupons/index.php` | `CouponRepository::searchForAdmin` | Server GET | q, active + sort | Low | — | ✅ |
| `admin/offers/index.php` | `OfferRepository::searchForAdmin` | Server GET | q + sort | Low | — | ✅ |
| `admin/deliveries/index.php` | `DeliveryAssignmentService::searchForAdmin` | Server GET | q, status tabs + sort | Was **LIMIT 200** | Paginate + search | ✅ |
| `admin/refunds/index.php` | `RefundRequestService::searchForAdmin` | Server GET | q, status + sort | Was **LIMIT 100** | Paginate + search; actions column | ✅ |
| `admin/support/index.php` | `SupportTicketService::searchForAdmin` | Server GET | q, status + sort | Was **LIMIT 100** | Paginate + search | ✅ |
| `admin/reviews/index.php` | `ReviewService::searchPendingForAdmin` | Server GET | q + sort | Was **LIMIT 100** pending | Paginate queue | ✅ |
| `admin/inventory/index.php` | `InventoryService::searchMovementsForAdmin` | Server GET | q + sort | Was **last 30** only | Paginate movements | ✅ |
| `admin/subscriptions/deliveries.php` | `SubscriptionFulfilmentService::searchUpcomingForAdmin` | Server GET | q + sort | Was **LIMIT 150** | Paginate + search | ✅ |
| `admin/subscriptions/customer-baskets.php` | `SubscriptionAdminListService::searchCustomerBaskets` | Server GET | date range, q + sort | Was **fetchAll** in window | Paginate in range | ✅ |
| `admin/subscriptions/index.php` | `SubscriptionPlanRepository::listForAdminPaginated` | Server GET | q | Low | Use `AdminServerList` per_page | ✅ |
| `admin/subscriptions/menu-planner.php` | `SubscriptionMenuRepository::listForAdminPaginated` | Server GET | q | Low | `AdminServerList` per_page | ✅ |
| `admin/seo/index.php` | `SeoRedirectRepository::searchForAdmin` | Server GET | q | Fixed 25/page | Full pager + per_page | ✅ |
| `admin/faqs/index.php` | `FaqRepository::searchForAdmin` | Server GET | q, category, active + sort | per_page fixed 20 | Align per_page options | 🟡 |
| `admin/cms/index.php` | `CmsPageService::searchForAdmin` | Server GET | q, status, visibility | per_page fixed 20 | Align per_page + sort headers | 🟡 |
| `admin/users/index.php` | `AdminRbacService::searchUsers` | Server GET | q | per_page 20; basic prev/next | `AdminServerList` + pager component | 🟡 |
| `admin/invoices/index.php` | `InvoiceQuery::adminSearch` | Server GET | Rich filters + sort | Custom (adequate) | Optional unify helpers | 🟡 |
| `admin/notifications/index.php` | `AdminNotificationService::listFilteredForCurrentAdmin` | Server GET | filter + page | Fixed 25 | Card UI; optional per_page | 🟡 |
| `admin/notifications/delivery-logs.php` | Notification delivery logs | Server GET | filters | Report-style | Keep as ops log | 🟡 |
| `admin/payments/completion_exceptions.php` | `PaymentCompletionExceptionService::listRecent` | **None** | — | **LIMIT 100** | Paginate when table grows | 🔜 |
| `admin/testimonials/index.php` | `TestimonialService::listForAdmin` | **None** | — | Limits 20 + 50 | Keep card moderation UX | ⬜ |
| `admin/roles/index.php` | `AdminRbacService::listRoles` | **None** | — | Small fixed set | No change | ⬜ |
| `admin/faqs/categories.php` | `FaqRepository` categories | Inline table | — | Small | Optional search | ⬜ |
| `admin/settings/delivery/slots.php` | `DeliverySlotAdminService::listForAdmin` | Server GET | search + page | Adequate | — | 🟡 |
| `admin/dashboard.php` | `AdminDashboardService` KPIs | N/A | N/A | Widgets | Do not table-ify | ⬜ |
| `admin/reports/*.php` | Report services | Report pagination | Date range | Analytics | Not admin CRUD lists | ⬜ |
| `admin/refunds/reconcile.php` | Reconciliation aggregates | N/A | — | Dashboard | Keep | ⬜ |
| `admin/reviews/moderation-history.php` | Moderation audit | Report | filters | History export | Keep report pattern | ⬜ |

## Subscription sub-pages (requested)

| Topic | Admin UI | Notes | Status |
|-------|----------|-------|--------|
| **Customer subscriptions** | No dedicated list page | Lifecycle via customer account + `admin/reports/subscriptions.php` snapshot | 🔜 Add `admin/subscriptions/customers.php` if ops need global list |
| **Subscription deliveries** | `admin/subscriptions/deliveries.php` | Upcoming confirmed queue | ✅ |
| **Subscription payments** | `admin/reports/subscriptions.php` (captured payments table) | Paginated via `SubscriptionReportService::capturedPayments` | 🟡 |
| **Subscription skips** | Dashboard count only | Data in `subscription_delivery_days` / pause tables | 🔜 Dedicated skip log if support volume grows |
| **Stock reservations** | No admin list | Cron/recovery in `subscription/inventory_reserve.php` | 🔜 Ops list only if needed |

## Patterns to avoid (found in audit)

- `fetchAll()` without `COUNT(*)` + `LIMIT/OFFSET` on growing tables — **fixed** on deliveries, refunds, support, baskets, movements, sub deliveries.
- Hard caps: **200** deliveries, **100** refunds/support/reviews/exceptions, **150** sub deliveries — **removed from primary admin lists** (legacy methods delegate to search with cap for callers).

## Regression tests

```bash
php scripts/test-admin-server-list.php
php scripts/test-admin-listing-audit-phase24.php
```
