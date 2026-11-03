# Phase 4 — Production deployment readiness audit

**Date:** 2026-11-03 (Asia/Kolkata)  
**Scope:** Read-only review of `database/production/apni_tokri_production.sql` and application production guards.  
**Schema target:** `1.20.0-invoices` (`Install::SCHEMA_VERSION`)  
**Production / dev databases:** Not connected or modified for this audit.

---

## Executive recommendation

| Question | Verdict |
|----------|---------|
| Is the **consolidated SQL** structurally correct for a fresh empty database? | **Yes** — validation PASS (89 tables, parity with migration-built schema). |
| Is the **stack ready to import on staging and operate as production** without further steps? | **No** |

### Final recommendation: **NOT READY FOR STAGING (go-live)**

The SQL installer is suitable for **import onto an empty staging database**, but staging/production **operation** remains blocked until merchant/hosting configuration, catalogue, payments, invoice storage, and admin hardening are completed (see §6).

---

## 1. SQL completeness

### 1.1 Baseline validation (existing)

Command: `php scripts/validate-apni-tokri-production-sql.php`  
**Result: PASS** (2026-11-03)

| Check | Result |
|-------|--------|
| Table count | 89 = 89 |
| Column definitions hash | Match |
| Index entries (`information_schema.statistics`) | 335 = 335 |
| FK references | 90 = 90 |
| `schema_version` | `1.20.0-invoices` |
| Required tables | Present |
| Transactional seed | 0 customers/orders/payments/subs/invoices/etc. |

### 1.2 Deep schema audit (new)

Command: `php scripts/audit-production-sql-schema-deep.php`  
**Result: PASS** (structural); **informational:** `auto_increment_hash` may differ.

| Element | Consolidated vs migration-built | Notes |
|---------|----------------------------------|-------|
| Tables (engine/collation) | Match | All **InnoDB**, **utf8mb4_unicode_ci** |
| Columns (types, nullability, keys, extras) | Match | |
| Indexes (statistics) | Match | |
| Foreign keys | Match | |
| CHECK constraints | Match (0) | None in schema |
| Views | Match (0) | Not used |
| Triggers | Match (0) | Not used |
| Stored routines | Match (0) | Not used |
| AUTO_INCREMENT counters | May differ | Explicit `INSERT` ids in seed dump; **not** a structural drift |

**Gap vs old validator:** Original script did not compare engines/collations, full statistics hash, CHECK constraints, or views/triggers/routines. Deep audit covers these; consider running both before each release.

### 1.3 File-level inspection

| Item | Finding |
|------|---------|
| `DROP TABLE` | Not present (`--skip-add-drop-table`) |
| `FOREIGN_KEY_CHECKS` | Set `0` during import, restored to `1` at end |
| Time zone in header | File sets `+05:30`; mysqldump section may reset to `+00:00` — MySQL session semantics only |
| Secrets in SQL | No gateway/SMTP/encryption key material in dump |
| Catalogue | **No products** — intentional (merchant must add catalogue) |

---

## 2. Default admin security

| Topic | Finding | Risk |
|-------|---------|------|
| Default user | `admin@apnitokri.local` seeded in SQL | Documented in README and SQL header |
| Password | **bcrypt** (`$2y$12$…`); verifies as documented default | **Critical** if unchanged after import |
| First-login force change | **Not implemented** — no `must_change_password` column or redirect | **Blocking for production** |
| Exposure | Default email/password in `database/README.md`, `database/production/README.md`, SQL comments | Operational secrecy relies on process |
| RBAC | Super Admin role + **26** permissions, **51** role-permission links (fresh import) | Appropriate for bootstrap |
| Login | `AdminAuth::attempt()` uses `password_verify`; updates `last_login_at` | Standard |

**Required before go-live (manual):**

1. Import SQL on staging.
2. Log in once to confirm access (disposable/staging only).
3. **Immediately** set a strong password via **Admin → Users** (or direct DB only in emergency with audit trail).
4. Change admin email to merchant domain; disable/delete unused admin accounts.
5. Consider removing default password from public docs after first deploy (doc hygiene).

**Application gap (non-SQL):** Add forced password change on first login if continuing to ship a known default hash in the installer.

---

## 3. Seed data audit

### 3.1 Safe production defaults (keep, configure in Admin)

| Area | Content | Safe default? | Merchant action |
|------|---------|---------------|-------------------|
| `settings.schema_version` | `1.20.0-invoices` | Yes | None |
| `settings.app_installed_at` | Timestamp | Yes | Informational |
| RBAC roles/permissions | System roles + super admin assignment | Yes | Add staff users/roles |
| `delivery_store` / `delivery_zones` / `delivery_slots` | Baseline store + sample zones/slots | Yes | Tune geo, fees, slots |
| `subscription_plans` | **3 active** templates (7/15/30) | Yes | Pricing/copy review |
| `faq_categories` | Seed categories | Yes | Add FAQs |
| `notification_templates` | System templates (6) | Yes | Customize copy |
| CMS `cms_pages` | **8 pages, all `draft`** | Yes | Lawyer-approved copy before publish |
| Refund/delivery/SMS template settings | Sensible defaults, SMS disabled | Yes | Enable SMS/SMTP when ready |

### 3.2 Requires merchant review before go-live

| Area | Seeded state | Risk | Action |
|------|--------------|------|--------|
| `settings.payment.active_gateway` | **`simulate`** | Checkout must not go live | Set Razorpay or Cashfree in Admin |
| `settings.payment.test_mode` | **`1`** | Blocks production payment readiness | Set **`0`** for live |
| `settings.razorpay.enabled` / `cashfree.enabled` | **`0`** | No live gateway | Enable + credentials in Admin (encrypted at rest) |
| `settings.sms.provider` | **`simulate`** | OK until SMS go-live | Configure real provider |
| **`coupons`** | **`WELCOME10`** and **`SAVE50`**, both **`is_active=1`** | Unintended promotions | Deactivate or delete if not wanted |
| **Products** | **None** | Storefront blocked in production | Import catalogue (not demo seed on prod unless intended) |

### 3.3 Intentionally excluded from SQL

- Transactional commerce data, testimonials, support tickets, webhook replay logs, QA subscriptions.

---

## 4. Production configuration audit (code + config templates)

Audited: `includes/app_environment.php`, `includes/storefront/readiness.php`, `includes/payment/config.php`, `includes/invoice/production_security.php`, `includes/config.example.php`.  
**No secret values** were read from live config.

| Area | Production expectation | Current template / local dev |
|------|------------------------|------------------------------|
| `app.env` | `production` or `prod` | Example: `local` |
| `app.debug` | **`false`** | Example: **`true`** — must disable on staging/prod |
| Database DSN | Dedicated DB; optional `database.role=production` | Separate per env |
| Payments | Not `simulate`; `payment.test_mode=0`; live keys configured | Seed DB: simulate + test mode on |
| Simulate payment endpoint | Disabled | Guard: `AppEnvironment::allowsSimulatedPaymentEndpoint()` |
| Demo catalogue/cart | Disabled | Guards PASS in `test-production-readiness.php` |
| SMTP / SMS | Configured in Admin settings | Empty/simulate in seed |
| Invoice encryption | `encryption.master_key_path` **outside docroot**, readable | Example empty → dev default under project |
| Invoice PDF storage | `storage.invoices_path` **outside docroot** | Example empty → `storage/invoices` under project |
| Session cookie | `session.cookie_secure=true` behind HTTPS | Example: **`false`** — set **true** on HTTPS |
| CLI test scripts | Blocked when `app.env` or `database.role` is production | Verified via guard probe |
| Cron | `AppEnvironment::assertCronEnvironment()` enforces invoice storage in production | See §5 |
| Webhooks | HTTPS URLs to `/webhooks/razorpay.php`, `/webhooks/cashfree.php` | Hosting + gateway dashboard |
| Error logging | No debug OTP/payment simulate in prod | Code guards present |

**Config doc drift:** `includes/config.example.php` lists `install.expected_schema_version` as `1.10.0-stage14-rbac-1` — outdated vs `1.20.0-invoices` (documentation only; runtime uses `Install::SCHEMA_VERSION`).

---

## 5. Fresh installation verification (disposable DB)

Command: `php scripts/run-phase4-fresh-install-audit.php`  
**Script result: PASS** (2026-11-03; disposable DB created, imported, dropped).

| Step | Result |
|------|--------|
| Import `apni_tokri_production.sql` | OK |
| `Install::statusReport()` / schema ready & current | OK |
| Default admin bcrypt + login with documented password | OK (staging only) |
| RBAC seed | OK |
| **3** active subscription plans | OK |
| **0** products | OK — storefront not operational in production env (expected) |
| `StorefrontReadiness::productionBlockers()` | Non-empty (catalogue + payment test mode) |
| Simulate endpoint blocked in production env | OK |
| CMS pages all **draft** | OK |
| **2** active coupons | Flagged — merchant review |
| Invoice storage under production env | **Fails closed** (expected until paths configured) |
| Forced password change | **Not implemented** (expected finding) |

Also run on target host after real config:

```bash
php scripts/verify-install.php
php scripts/test-production-readiness.php   # on a non-production DSN or with patched env in CI
```

### 5.1 Cron and webhook prerequisites (hosting)

| Job | Purpose |
|-----|---------|
| `cron/subscription_auto_confirm_baskets.php` | Subscription basket auto-confirm |
| `cron/subscription_generate_deliveries.php` | Fulfilment order generation |
| `cron/subscription_recover_reservations.php` | Reservation recovery |
| `cron/delivery_slot_release_expired.php` | Slot hold cleanup |
| `cron/process_notification_queue.php` | Email/SMS queue |
| `cron/generate_invoices.php` | Invoice generation (requires invoice storage + key) |

Webhooks must reach the app over **HTTPS** with gateway secrets configured in Admin.

---

## 6. Blocking issues (must resolve before staging go-live)

| ID | Blocker | Owner |
|----|---------|-------|
| B1 | Known default admin password; **no forced rotation** | Engineering + ops |
| B2 | Seed `payment.active_gateway=simulate` and `payment.test_mode=1` | Merchant Admin |
| B3 | Live Razorpay/Cashfree credentials and test mode off | Merchant Admin |
| B4 | **Empty catalogue** — production storefront blocked | Merchant |
| B5 | Invoice **master key** and **PDF path** outside web root on production | Hosting + ops |
| B6 | **`app.debug=false`**, **`app.env=production`**, HTTPS, **`cookie_secure=true`** | Hosting |
| B7 | Active **WELCOME10** / **SAVE50** coupons — confirm or deactivate | Merchant |
| B8 | CMS/legal pages still **draft** — publish only after approval | Merchant |

**Non-blocking for SQL import:** B1–B8 are post-import configuration, not defects in the dump file.

---

## 7. Manual hosting and merchant tasks (checklist)

- [ ] Create empty MySQL 8.x database (utf8mb4_unicode_ci).
- [ ] Import `database/production/apni_tokri_production.sql`.
- [ ] Deploy code; create **`config.local.php`** outside git with `app.env=production`, `app.debug=false`.
- [ ] Set `database.role=production` on production DSN (optional belt-and-braces).
- [ ] Configure HTTPS, PHP opcache, file permissions (`storage/` not world-writable for secrets).
- [ ] Place Sodium master key and invoice directory **outside document root**; set paths in config.
- [ ] Change admin password and email; create staff admins with least privilege.
- [ ] Configure payment gateways (live keys), disable test mode, register webhooks.
- [ ] Configure SMTP (and SMS if used).
- [ ] Import **merchant** catalogue (avoid accidental demo seed on production).
- [ ] Review/deactivate seed coupons; publish CMS when legal approves.
- [ ] Install crontab entries (Asia/Kolkata).
- [ ] Run `php scripts/verify-install.php`; confirm `StorefrontReadiness::productionBlockers()` is empty.
- [ ] Smoke-test checkout on staging with **test mode** on gateway first, then live cutover plan.

---

## 8. Validation tooling summary

| Script | Purpose |
|--------|---------|
| `scripts/validate-apni-tokri-production-sql.php` | Column/index/FK parity + seed row counts |
| `scripts/audit-production-sql-schema-deep.php` | Engines, collations, statistics, CHECK, views, triggers, routines |
| `scripts/run-phase4-fresh-install-audit.php` | Disposable DB import + install/readiness checks |
| `scripts/test-production-readiness.php` | Production env guards (no DB mutation) |

Suggested pre-release gate:

```bash
php scripts/validate-apni-tokri-production-sql.php
php scripts/audit-production-sql-schema-deep.php
php scripts/run-phase4-fresh-install-audit.php
```

---

## 9. Sign-off statement

- **SQL artifact:** Approved for **one-shot import** into an empty staging/production database schema slot.  
- **Deployment readiness:** **NOT READY FOR STAGING (go-live)** until §6 blockers are cleared on the target environment.  
- **No production database was accessed or modified** during this audit.
