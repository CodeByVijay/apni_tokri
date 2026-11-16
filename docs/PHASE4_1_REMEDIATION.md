# Phase 4.1 — Production blockers remediation

**Date:** 2026-11-03  
**Scope:** Code and fresh-install seed changes only. No development/production database mutation, no deployment, no Phase 3 cleanup.

---

## Final status: **NOT READY FOR STAGING**

Engineering blockers **B1** (forced admin password change), **B2/B3** (production payment fail-closed), **B7** (seed coupon defaults), and CMS placeholder publish guard are addressed in code and consolidated SQL.

**Staging go-live remains blocked** by merchant/hosting items unchanged by this phase:

- Empty product catalogue (**B4**)
- Invoice encryption key and PDF path outside docroot (**B5**)
- `app.env=production`, `app.debug=false`, HTTPS, `session.cookie_secure=true` (**B6**)
- Merchant must configure live Razorpay/Cashfree, disable test mode, and register webhooks after import
- CMS/legal pages still **draft** until merchant publishes approved copy (**B8**)

Import consolidated SQL on an empty staging database and complete the checklist in [PRODUCTION_DEPLOYMENT_READINESS.md](./PRODUCTION_DEPLOYMENT_READINESS.md) §7 before calling the environment staging-ready for customers.

---

## 1. Mandatory first-login admin password change

| Requirement | Implementation |
|-------------|----------------|
| Detect seeded default admin | `Install::SEED_ADMIN_PASSWORD_HASH` compared to `admin_users.password_hash` (hash-only; plain password never returned in API/HTML) |
| Force change before admin use | `AdminGuard` redirects all admin routes (including AJAX → `403` / `password_change_required`) except `change-password.php` and `logout.php` |
| Validate current password | `AdminAuth::changePasswordMandatory()` uses `password_verify` |
| Strong new password | `Validation::adminPasswordPolicy()` — min 10 chars, letter + number; rejects factory default via `password_verify` against seed hash |
| Secure hashing | `password_hash(..., PASSWORD_DEFAULT)` |
| Session regeneration | `AppSession::regenerate()` after successful change |
| Other admins unaffected | Only accounts whose hash still equals the seed hash |

**UI:** [admin/change-password.php](../admin/change-password.php) (auth layout). Login redirects to this page when required ([admin/login.php](../admin/login.php)).

**Not in Phase 4.1 scope (see §12):** self-service “Forgot password” / email recovery for admin or staff.

---

## 2. Production payment safeguards

Central gate: `PaymentGatewayConfig::assertPaymentExecutionAllowed($gateway)` — rejects `simulate` in production and delegates to `AppEnvironment::assertProductionPaymentsReady()` (simulate gateway, `payment.test_mode=1`, missing/disabled live credentials).

| Entry point | Guard |
|-------------|--------|
| Order checkout → `PaymentService::initiateForOrder` | Assert before INSERT |
| Payment retry ([payment.php](../payment.php)) | Same initiate path |
| Subscription purchase / resume | Existing `assertCheckoutPaymentsAllowed` + assert before subscription payment INSERT |
| Simulate AJAX | `allowsSimulatedPaymentEndpoint` + assert |
| Webhook / verify completion | `PaymentService::completePayment`, `SubscriptionActivationService::activateFromPayment`, substitution `completePayment` |
| Admin gateway settings | Already blocked simulate/test mode save in production ([includes/commerce/payment_gateway_settings.php](../includes/commerce/payment_gateway_settings.php)) |

**Local/staging:** Unchanged — simulate checkout and endpoint remain available when `app.env` is not production.

---

## 3. Coupon seed safety

| Coupon | Fresh install default | Existing DBs |
|--------|----------------------|--------------|
| `WELCOME10` | `is_active = 0` | Unchanged (migration uses `ON DUPLICATE KEY UPDATE code = code`) |
| `SAVE50` | `is_active = 0` | Same |

Merchants enable coupons in **Admin → Coupons**. Demo cart placeholder text still mentions codes for local UX; inactive codes do not apply at checkout.

Documented in [database/production/README.md](../database/production/README.md).

---

## 4. CMS safety

- Seeded CMS pages remain **`draft`** in SQL/migrations (verified on disposable import).
- **Publish guard:** `CmsInstallSafety::publishBlockedReason()` blocks publishing seed slugs while body still contains installer placeholder text (`CmsPageService::publish`).

Normal publishing works after merchants replace placeholder copy.

---

## 5. Migrations

| Change | Justification |
|--------|----------------|
| [database/migrations/1.2.0_commerce_checkout.sql](../database/migrations/1.2.0_commerce_checkout.sql) — coupon `is_active=0`, no-op duplicate update | Fresh installs only; does not UPDATE existing merchant rows |

No new schema version migration. [database/production/apni_tokri_production.sql](../database/production/apni_tokri_production.sql) rebuilt via `php scripts/build-apni-tokri-production-sql.php`.

---

## 6. Modified files

| File | Change |
|------|--------|
| `includes/install.php` | Seed admin email/hash constants |
| `includes/auth.php` | Mandatory password detection + change API |
| `includes/admin/guard.php` | Password-change gate + AJAX denial |
| `includes/validation.php` | `adminPasswordPolicy()` |
| `admin/login.php` | Bootstrap without full admin lock; redirect to change-password |
| `admin/change-password.php` | **New** forced change UI |
| `includes/payment/config.php` | `assertPaymentExecutionAllowed()` |
| `includes/payment/service.php` | Initiate + complete guards |
| `includes/subscription/checkout.php` | Assert before subscription payment INSERT |
| `includes/subscription/activation.php` | Assert on activate from payment |
| `includes/subscription/substitution_payment.php` | Assert on complete |
| `ajax/payment/simulate.php` | Extra assert |
| `includes/cms/install_safety.php` | **New** placeholder publish guard |
| `includes/cms/page_service.php` | Call guard on publish |
| `includes/bootstrap.php` | Load CMS install safety |
| `database/migrations/1.2.0_commerce_checkout.sql` | Inactive seed coupons |
| `database/production/apni_tokri_production.sql` | Rebuilt artifact |
| `database/production/README.md` | Coupon + password-change notes |
| `scripts/run-phase4-fresh-install-audit.php` | Expect force-change + inactive seed coupons |
| `scripts/test-phase4-1-remediation.php` | **New** disposable-DB regression |
| `scripts/test-admin-auth-security.php` | Login redirect / bypass expectations (+ AJAX slug guard) |
| `admin/ajax/slug-from-name.php` | Route through `admin/_init.php` / `AdminGuard` |
| `admin/ajax/notifications.php` | Same (password-change gate) |
| `docs/PHASE4_1_REMEDIATION.md` | This report |

---

## 7. Tests (actual results)

### 7.1 Disposable-database CLI (`apni_tokri_phase41_*` — no use of configured DSN)

| Script | Result |
|--------|--------|
| `php scripts/test-phase4-1-remediation.php` | **PASS** — 23 ok, 0 fail (1 skip: no orders on empty fresh DB) |
| `php scripts/run-phase4-fresh-install-audit.php` | **PASS** (force-change + inactive seed coupons; invoice storage expected fail) |
| `php scripts/test-production-readiness.php` | **PASS** |
| `php scripts/test-phase22-production-blockers.php` | **PASS** |

No real email, SMS, or payment API calls. These scripts create and drop isolated databases only.

### 7.2 HTTP admin auth (`app.base_url` = `http://localhost/Apni_Tokri`)

Command: `php scripts/test-admin-auth-security.php`  
**Result: PASS** — 22 assertions (2026-11-03)

| Check | Result |
|-------|--------|
| Direct admin URL bypass (unauthenticated) | **PASS** — sample modules redirect to `login.php` |
| Unauthenticated AJAX | **PASS** — `401` + `unauthenticated` |
| First-login forced password change | **PASS** — login → `change-password.php`; dashboard **302** to change-password |
| Password-change restriction bypass (dashboard AJAX) | **PASS** — `403` + `password_change_required` |
| Password-change restriction bypass (`admin/ajax/slug-from-name.php`) | **PASS** after fix (was **FAIL** when endpoint only used `AdminAuth::check()`) |
| Existing admin authentication (login → protected page) | **PASS** |
| Logout / session invalidation | **PASS** — dashboard blocked after logout |
| Session regeneration after mandatory change | **Not asserted over HTTP** — verified via CLI (hash update + `AppSession::regenerate()` in `AdminAuth::changePasswordMandatory` code path; see `test-phase4-1-remediation.php`) |

**HTTP environment note:** Requests are served by the local web stack using `includes/config.local.php` (DSN `apni_tokri`). Tests perform **read-only admin navigation** except a successful login POST, which updates `admin_users.last_login_at` for the seed admin. No password reset email, no payment calls, no schema/data cleanup scripts.

---

## 8. Security checks performed

- Seed password hash constant not exposed in JSON/ajax responses (generic error messages only).
- Admin AJAX bypass → `403` + `password_change_required` when factory password still in use (including slug helper after §7.2 fix).
- Production `simulate` payment rows blocked at initiation and completion layers.
- Production payment settings cannot select simulate or enable test mode via admin save handler.
- CMS placeholder pages cannot be published without editing body text.

**Not implemented (do not claim):** admin self-service forgot-password, email recovery tokens, admin login rate limiting (see §12).

---

## 9. Remaining manual hosting / merchant tasks

- [ ] Deploy with `app.env=production`, `app.debug=false`, HTTPS, secure session cookies
- [ ] Configure invoice master key + PDF directory outside docroot
- [ ] Import merchant catalogue (products)
- [ ] Configure Razorpay/Cashfree (live keys), set active gateway, `payment.test_mode=0`, webhooks
- [ ] Enable seed coupons in Admin only if intended
- [ ] Publish CMS pages after legal approval
- [ ] Change admin email from `admin@apnitokri.local` to merchant domain
- [ ] Crontab + SMTP/SMS as needed

---

## 10. Updated blocker matrix (from Phase 4 audit)

| ID | Blocker | Phase 4.1 status |
|----|---------|------------------|
| B1 | Default admin password / no forced rotation | **Resolved in application** — forced change on seed hash |
| B2 | Seed simulate + test mode settings | **Fail-closed in production code**; merchant must still configure live gateway in Admin |
| B3 | Live gateway credentials | **Merchant/hosting** — unchanged |
| B4 | Empty catalogue | **Merchant** — unchanged |
| B5 | Invoice storage/key | **Hosting** — unchanged |
| B6 | Production app/debug/cookies/HTTPS | **Hosting** — unchanged |
| B7 | Active WELCOME10/SAVE50 | **Resolved for fresh SQL** — seeded inactive; existing DBs manual |
| B8 | CMS draft / approval | **Guard + draft seed**; publish is merchant action |

---

## 11. Sign-off

| Role | Ready for staging import + config? |
|------|----------------------------------|
| Engineering | SQL + code guards ready for staging **import** |
| Go-live / customers | **NOT READY FOR STAGING** until §9 complete |

---

## 12. Requirements review — admin forgot password / recovery

Phase 4.1 **original** engineering scope was mandatory **first-login** password change, production payment fail-closed, coupon/CMS seed safety — **not** self-service password recovery. The items below were **explicitly verified in code** on 2026-11-03:

| # | Requirement | Status | Evidence |
|---|-------------|--------|----------|
| 1 | Forgot Password for admin and admin employees | **NOT IMPLEMENTED** | No `admin/forgot-password.php`, reset routes, or UI links; no recovery flow beyond login + mandatory change |
| 2 | Super Admin email-based recovery | **NOT IMPLEMENTED** | No outbound reset email, no token mailer, no recovery entry point |
| 3 | Reset tokens (secure, hashed, expiring, single-use) | **NOT IMPLEMENTED** | `admin_users` has no reset-token columns; no `admin_password_resets` (or similar) table in schema |
| 4 | Sessions invalidated after successful **email** password reset | **NOT IMPLEMENTED** (N/A) | Mandatory **logged-in** change calls `AppSession::regenerate()`; no global session revocation on reset |
| 5 | Rate limiting + generic responses for **forgot password** | **NOT IMPLEMENTED** | No forgot-password endpoint |
| — | Admin login brute-force rate limiting | **NOT IMPLEMENTED** | `AdminAuth::attempt()` has no throttle (distinct from customer OTP limits) |

**What exists instead (in scope for Phase 4.1):**

- **Mandatory change** when `password_hash` equals `Install::SEED_ADMIN_PASSWORD_HASH` ([includes/auth.php](../includes/auth.php), [includes/admin/guard.php](../includes/admin/guard.php)).
- **Super Admin / privileged reset of other users:** an administrator with `users` access can set a new password in **Admin → Users** ([includes/admin/rbac.php](../includes/admin/rbac.php) — `admin_user.password_reset` audit when password field submitted on edit). This is **manual**, not email self-service, and does not invalidate other sessions for that user.

**Follow-up (future phase):** forgot-password UX, hashed expiring tokens, SMTP-backed mail, generic “if an account exists” responses, rate limits, and optional session invalidation for the affected `admin_user_id`.
