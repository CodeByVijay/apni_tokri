# Security endpoint inventory (pre-staging)

**Generated:** 2026-11-03  
**Method:** Static analysis (`scripts/build-security-endpoint-inventory.php`) + manual review of auth/csrf wiring  
**Machine-readable:** [storage/security-audit-results.json](../storage/security-audit-results.json) → `endpoint_inventory.endpoints`

---

## Summary

| Area | Endpoints | Default auth | CSRF on mutations |
|------|----------:|--------------|-------------------|
| Storefront (document root `*.php`) | 39 | Mixed (public / customer session) | Forms + storefront AJAX |
| Admin (`/admin/**`) | 76 | Admin session (except login) | POST admin forms |
| Admin AJAX | 2 | Admin session + RBAC | POST |
| Public AJAX (`/ajax/**`) | 13 | Guest or customer | Required (except `ping` GET) |
| Webhooks | 2 | HMAC signature | N/A |
| Cron (CLI) | 6 | CLI / env guards | N/A |
| **Total inventoried** | **129** | | |

**Not inventoried as application routes:** ~323 PHP files under `/scripts/` (CLI/tests), `/includes/` (libraries), and build tools. Several **`/scripts/*.php` URLs are reachable over HTTP** when the web server document root is the project folder — see finding **AT-SEC-001**.

---

## Authentication matrix (by area)

| Area | Unauthenticated | Customer | Admin | Other |
|------|---------------|----------|-------|-------|
| Storefront catalog | ✓ browse | ✓ account | — | — |
| Checkout / payment pages | Redirect login | ✓ | — | — |
| Customer AJAX | Cart, pin check, ping | ✓ subscription/payment/support | — | — |
| Admin UI | login, change-password (if session) | — | ✓ + RBAC | — |
| Webhooks | — | — | — | Gateway HMAC |
| Cron | — | — | — | Shell only |

---

## Authorization (server-side)

- **Admin:** `AdminGuard::requireAuthentication()` via `admin/_init.php`; module checks via `Permission::canModule()` / `AdminDemoPerms::denyIfCannot()` (DB RBAC, not UI-only).
- **Mandatory seed password change:** `AdminGuard` blocks all admin routes except `change-password.php` and `logout.php` (including AJAX → `403` / `password_change_required`).
- **Customer orders/invoices:** `OrderRepository::forCustomerByNumber`, `InvoiceService::forCustomer` — customer ID bound in SQL.
- **Support attachments:** `SupportTicketService::attachmentForCustomer($id, $customerId)`.

---

## CSRF

- **Pattern:** `Csrf::requireValid()` on POST storefront/admin forms and state-changing AJAX.
- **Exception:** `ajax/ping.php` — GET without CSRF (informational JSON only).
- **Webhooks:** No CSRF; rely on signature verification.

---

## Endpoint notes (high sensitivity)

| Path | Method | Auth | CSRF | Notes |
|------|--------|------|------|-------|
| `/admin/login.php` | GET/POST | Public | POST | No rate limit (AT-SEC-002) |
| `/admin/change-password.php` | GET/POST | Admin (seed hash) | POST | Forced first login |
| `/ajax/payment/simulate.php` | POST | Customer | Yes | Blocked in production env |
| `/ajax/payment/verify.php` | POST | Customer | Yes | Gateway signature verify |
| `/webhooks/razorpay.php` | POST | HMAC | N/A | Dedupe via `payment_webhook_events` |
| `/webhooks/cashfree.php` | POST | HMAC | N/A | Same pattern |
| `/invoice-download.php` | GET | Customer | N/A | Uniform 404 for IDOR probe |
| `/install.php`, `/health-check.php` | GET | Public | N/A | Recon / status leak (AT-SEC-004) |
| `/ajax/ping.php` | GET | Public | No | Returns `app.env` (AT-SEC-005) |
| `/scripts/*.php` | GET | **Often none** | N/A | **Must not be web-exposed** (AT-SEC-001) |

Full per-path rows: JSON inventory (129 entries).

---

## Regenerate inventory

```bash
php scripts/build-security-endpoint-inventory.php
```

Audit-first: update inventory after adding routes; do not treat this file as authorization proof without code review.
