# Security remediation — Phase 1

**Date:** 2026-11-03 (Asia/Kolkata)  
**Scope:** AT-SEC-001, AT-SEC-002, AT-SEC-003, AT-SEC-004, AT-SEC-005, AT-SEC-011  
**Automated suite:** `php scripts/test-security-remediation-phase1.php`

---

## Final status

| Label | Meaning |
|-------|---------|
| **ALL CRITICAL/HIGH FIXES VERIFIED** | Code + disposable-DB + local HTTP smoke tests pass for in-scope findings (see counts below). |
| **BLOCKERS REMAIN** | Medium/low audit items open; **migration 1.21.0 must be applied** on each environment; Apache `.htaccess` may need `AllowOverride` for rewrite rules; passing tests ≠ full security certification. |

---

## Required migration

Apply on staging/production during a maintenance window (not run automatically by this task):

```bash
mysql -u USER -p DATABASE < database/migrations/1.21.0_security_remediation_phase1.sql
```

Rollback (emergency only): `database/migrations/rollback/1.21.0_security_remediation_phase1_rollback.sql`

Updates `Install::SCHEMA_VERSION` to `1.21.0-security-phase1`.

---

## Finding status (Phase 1)

| ID | Severity | Status | Verification |
|----|----------|--------|--------------|
| AT-SEC-001 | Critical | **Remediated** (verify on deploy) | HTTP **403** on `/scripts/*.php`; CLI scripts run; `scripts/.htaccess` + root rewrite + bootstrap CLI guard |
| AT-SEC-002 | High | **Remediated** (needs migration) | Disposable DB: 6 failures → throttle; audit `admin.login_failed` |
| AT-SEC-003 | High | **Remediated** (needs migration + SMTP) | Token hash, expiry, single-use, generic unknown email, mocked mail tests |
| AT-SEC-004 | High | **Remediated** | `install.php` → **404** when schema installed |
| AT-SEC-005 | Medium | **Remediated** | `ajax/ping.php` no `env`; minimal public payload when installed |
| AT-SEC-011 | High | **Remediated** | Guest refund denied; guest `other` allowed; UI hides sensitive categories |
| AT-SEC-012 | Medium | **Remediated** | Guest `support.php` no account nav/logout; login CTA; `test-customer-account-shell-security.php` |

Open from original audit (out of Phase 1): AT-SEC-006–010, AT-SEC-S*, host hardening, WAF, dependency CVE tracking.

---

## Test results (last run)

| Suite | Pass | Fail |
|-------|-----:|-----:|
| `scripts/test-security-remediation-phase1.php` | 26 | 0 |

**HTTP evidence (local `app.base_url`):**

| Request | Expected | Observed |
|---------|----------|----------|
| `GET /scripts/test-production-readiness.php` | 403/404 | **403** Forbidden |
| `GET /scripts/../scripts/test-production-readiness.php` | 403/404 | **403** |
| `GET /ajax/ping.php` | no `env` in JSON | `{"success":true,"data":{"status":"ok"},...}` |
| `GET /install.php` (installed) | 404 | **404** |
| `GET /health-check.php` (installed, guest) | 404 | **404** |

---

## Modified / added files

| Path | Change |
|------|--------|
| `.htaccess` | Deny `/scripts/` |
| `scripts/.htaccess` | Require all denied |
| `includes/bootstrap.php` | HTTP block for `/scripts/`; load security modules |
| `includes/install.php` | `isApplicationInstalled()`, `denyPublicInstallSurface()`, schema version |
| `includes/auth.php` | Login throttle integration, `auth_epoch` session binding, login messages |
| `includes/audit.php` | Optional acting admin user id for pre-auth events |
| `includes/admin/login_security.php` | **New** — rate limiting |
| `includes/admin/password_recovery.php` | **New** — forgot/reset flow |
| `includes/config.example.php` | `security.*` thresholds |
| `includes/trust/support.php` | AT-SEC-011 enforcement + guest rate limit |
| `support.php` | Guest category list |
| `install.php`, `health-check.php`, `ajax/ping.php` | Public surface reduction |
| `admin/login.php` | Throttle message + forgot link |
| `admin/forgot-password.php`, `admin/reset-password.php` | **New** |
| `assets/css/admin-auth.css` | Forgot/reset layout |
| `database/migrations/1.21.0_security_remediation_phase1.sql` | **New** |
| `database/migrations/rollback/1.21.0_security_remediation_phase1_rollback.sql` | **New** |
| `docs/ADMIN_PASSWORD_RECOVERY_BREAKGLASS.md` | **New** |
| `scripts/test-security-remediation-phase1.php` | **New** |
| `scripts/test-admin-login-rate-limit.php` | **New** — rate limit regression |
| `scripts/test-support-ticket-auth-audit.php` | Updated expectations |

---

## Bugfix — admin login “Too many sign-in attempts” before first try (2026-11-03)

| Item | Detail |
|------|--------|
| **Root cause** | (1) Phase 1 / test scripts left many `success=0` rows for the seed admin email on shared localhost IPs. (2) `AdminAuth::attempt()` returned **before** `password_verify` when `blockReason()` was `throttled`, so **correct passwords were rejected** until the 15‑minute window expired. (3) Successful logins did not clear prior failure rows for that email. |
| **Not a GET leak** | `admin/login.php` only sets `$error` on POST; a fresh GET does not call the limiter. The message appears on the first POST when the email/IP is already over threshold (often perceived as “on page load” with saved email). |
| **Fix** | Verify password even when throttled; do not record extra failures while throttled; `clearRecentFailuresForEmail()` on success. Brute-force protection retained (delays, email/IP caps, audit on failed attempts when not throttled). |
| **Before** | ≥5 failures → any further POST shows throttle, including correct password. |
| **After** | Throttled + wrong password → delayed, no new counter rows. Throttled + **correct** password → login OK, email failure rows cleared. |
| **Tests** | `php scripts/test-admin-login-rate-limit.php` (20/20 on disposable DB). |

---

## Remaining risks

1. **Migration not applied** — throttle, reset tokens, and guest support limits are no-ops until 1.21.0 runs.  
2. **`.htaccess` ignored** — if `AllowOverride` is off, rely on bootstrap **403** for scripts (verified) and vhost hardening.  
3. **Support ticket numbers** — `nextTicketNumber()` still uses `COUNT(*)+1` (AT-SEC-S06); can fail under concurrency.  
4. **SMTP** — password reset requires working SMTP (or mocked transport in tests only).  
5. **Medium/low findings** — CMS XSS, CSP, uploads PHP, session idle timeout, etc.  
6. **No claim of “fully secure”** — re-audit after staging deploy and migration.

---

## Operator checklist

- [ ] Apply migration 1.21.0 on staging  
- [ ] Confirm HTTP 403/404 for `/scripts/` on staging URL  
- [ ] Configure SMTP for admin reset emails  
- [ ] Read [ADMIN_PASSWORD_RECOVERY_BREAKGLASS.md](./ADMIN_PASSWORD_RECOVERY_BREAKGLASS.md)  
- [ ] Re-run `php scripts/test-security-remediation-phase1.php` against staging clone  
