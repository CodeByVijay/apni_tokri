# Apni Tokri — comprehensive security audit (pre-staging)

**Date:** 2026-11-03 (Asia/Kolkata)  
**Type:** Audit-first — **no broad remediation** performed in this task  
**References:** OWASP [ASVS 5.0.0](https://owasp.org/www-project-application-security-verification-standard/), OWASP [Top 10:2025](https://owasp.org/Top10/)  
**Related:** [SECURITY_ENDPOINT_INVENTORY.md](./SECURITY_ENDPOINT_INVENTORY.md), [SECURITY_REMEDIATION_PLAN.md](./SECURITY_REMEDIATION_PLAN.md), [storage/security-audit-results.json](../storage/security-audit-results.json)

---

## Executive summary

The application implements **meaningful baseline controls**: PDO prepared statements in reviewed paths, CSRF on most mutating AJAX, RBAC-backed admin modules, customer-scoped order/invoice queries, payment webhook signature verification, production payment fail-closed (Phase 4.1), and forced change of the factory admin password hash.

**This audit found high-risk issues that must be addressed before treating staging as security-ready**, chiefly **web exposure of `/scripts/` test utilities**, **missing admin login rate limiting**, and **unauthenticated creation of order-sensitive support tickets** (refund / payment / delivery categories). Admin **Forgot Password / email recovery is NOT IMPLEMENTED** (documented gap, increases operational lockout and credential-reset risk).

**Final status (2026-11-03 audit): HIGH-RISK ISSUES FOUND**

**Phase 1 remediation (2026-11-03):** Critical/High fixes implemented — see [SECURITY_REMEDIATION_PHASE1.md](./SECURITY_REMEDIATION_PHASE1.md). **ALL CRITICAL/HIGH FIXES VERIFIED** in code + disposable DB + local HTTP smoke; **BLOCKERS REMAIN** (migration deploy, medium/low findings, operational hardening).

The application is **not** hacker-proof. Residual risk will remain after remediation (CMS rich HTML, webhook abuse at scale, host misconfiguration, dependency drift).

| Metric | Value |
|--------|------:|
| PHP files in repository | 452 |
| Web endpoints inventoried | 129 |
| Files/modules depth-reviewed | ~160 (admin, ajax, webhooks, payment, auth, upload, invoice, subscription checkout) |
| **Confirmed vulnerabilities** | 14 |
| **Suspected / context-dependent** | 6 |
| Critical | 1 |
| High | 4 |
| Medium | 6 |
| Low | 4 |
| Informational (positive controls) | 8 |

---

## Methodology

| Category | Performed | Notes |
|----------|-----------|-------|
| **A. Static review** | Yes | Full repo recon; auth/payment/upload/RBAC code paths |
| **B. Dynamic testing** | Partial | Local HTTP via `app.base_url`; **no** prod/dev DB mutation; **no** live payments/email/SMS |
| **C. Infrastructure** | Partial | `.htaccess`, storage deny rules, config templates; **no** external port scanning |

**Dynamic tests executed**

| Test | Result |
|------|--------|
| `curl` → `/scripts/test-production-readiness.php` | HTTP **200**, body exposes check output (**AT-SEC-001**) |
| `curl` → `/ajax/ping.php` | JSON includes `"env":"local"` (**AT-SEC-005**) |
| `php scripts/test-admin-auth-security.php` | Unauthenticated checks **PASS**; login flow **FAIL** (2) when seed password no longer default on configured DB |
| `php scripts/test-production-readiness.php` (CLI) | **PASS** |
| Phase 4.1 disposable DB tests (prior) | Password-change gate, payment fail-closed |
| Support ticket auth probe (`scripts/test-support-ticket-auth-audit.php`, `SUPPORT_AUDIT_ALLOW_INSERT=1`) | Guest `SupportTicketService::create(null, 'refund', …)` **succeeded**; row deleted after probe (**AT-SEC-011**) |
| `curl` guest `GET /support.php` | Form lists `refund`, `payment`, `late_delivery` without login (**AT-SEC-011**) |
| `curl` guest `POST /support.php` | Missing/invalid CSRF → **403**; valid CSRF + `category=refund` reaches service (no auth gate); on busy DB insert may fail on duplicate `ticket_number` (see AT-SEC-S06), not on auth |
| `SupportTicketService::create` invalid category | `bogus` → rejected (“Choose a valid category.”) |
| Repeated guest `create(null, 'other', …)` | No rate limit in service (5 rapid calls blocked only when DB insert failed) |

**Not executed (and why)**

| Area | Reason |
|------|--------|
| Full RBAC matrix per employee role | Time/combinatorics; static review of `Permission::canModule` only |
| Live webhook replay | No gateway secrets; signature logic reviewed statically |
| Payment amount tampering E2E | No real orders on isolated DB in this pass |
| DAST / external infrastructure scan | Out of scope per restrictions |
| Composer/npm CVE scan | **No `composer.json`** in repo; front-end assets vendored under `assets/` |

---

## Finding register (confirmed)

### AT-SEC-001 — Web-accessible CLI test scripts

| Field | Detail |
|-------|--------|
| **Severity** | **Critical** (deployment-dependent) |
| **Location** | `.htaccess` lines 8–11 block `includes/`, `storage/`, `database/` only — **not** `/scripts/` |
| **Endpoint** | e.g. `GET /scripts/test-production-readiness.php` |
| **Attacker** | Unauthenticated remote |
| **Scenario** | Attacker runs diagnostic/test scripts over HTTP; may leak environment behaviour, stress DB, or bypass intended CLI-only guards on misconfigured hosts |
| **Evidence** | HTTP 200 and plaintext `OK:` lines from local `curl` (2026-11-03) |
| **Impact** | Recon, DoS, unintended code execution paths, accidental data mutation on dev DB |
| **Fix** | Deny web access to `/scripts/` (vhost + `.htaccess`); document root above project or `Alias` only `public/` |
| **Regression** | HTTP probe returns 403/404 for `/scripts/*.php` |

### AT-SEC-002 — No admin login rate limiting

| Field | Detail |
|-------|--------|
| **Severity** | **High** |
| **Location** | `includes/auth.php` — `AdminAuth::attempt()` |
| **Endpoint** | `POST /admin/login.php` |
| **Attacker** | Unauthenticated remote |
| **Scenario** | Unlimited password guessing against known seed email `admin@apnitokri.local` or staff emails |
| **Evidence** | Static review; no lockout counters (contrast: customer OTP limits in `CustomerAuth::otpRateLimitMessage`) |
| **Impact** | Admin account compromise |
| **Fix** | Per-IP and per-email throttling, exponential backoff, optional CAPTCHA after N failures |
| **Regression** | Automated test: N failures → temporary lock or delay |

### AT-SEC-003 — Admin self-service password recovery NOT IMPLEMENTED

| Field | Detail |
|-------|--------|
| **Severity** | **High** (operational / account recovery) |
| **Location** | N/A — feature absent |
| **Endpoint** | N/A |
| **Attacker** | N/A |
| **Scenario** | No forgot-password for Super Admin or staff; recovery only via another admin or DB access |
| **Evidence** | No routes, tables, or tokens; see [PHASE4_1_REMEDIATION.md](./PHASE4_1_REMEDIATION.md) §12 |
| **Impact** | Lockout, unsafe recovery practices, no hashed expiring single-use reset tokens |
| **Fix** | Implement ASVS-aligned reset flow (out of scope for this audit) |
| **Regression** | Token hash, expiry, single-use, session invalidation, generic responses |

### AT-SEC-004 — Public installation / health reconnaissance

| Field | Detail |
|-------|--------|
| **Severity** | **Medium** |
| **Location** | `install.php`, `health-check.php` |
| **Endpoint** | `GET /install.php`, `GET /health-check.php` |
| **Attacker** | Unauthenticated |
| **Scenario** | Enumerate schema readiness, PHP version, DB connectivity on staging/production |
| **Evidence** | Static review of `Install::statusReport()` output on pages |
| **Impact** | Attack surface mapping |
| **Fix** | Disable or IP-restrict on production; or move behind admin auth |
| **Regression** | Production returns 404/403 |

### AT-SEC-005 — Unauthenticated environment disclosure (AJAX ping)

| Field | Detail |
|-------|--------|
| **Severity** | **Medium** |
| **Location** | `ajax/ping.php` |
| **Endpoint** | `GET /ajax/ping.php` |
| **Attacker** | Unauthenticated |
| **Scenario** | Read `app.env` value from JSON |
| **Evidence** | `curl` response: `"env":"local"` |
| **Impact** | Environment fingerprinting |
| **Fix** | Remove env from response or require session + strip in production |
| **Regression** | Anonymous GET does not return env |

### AT-SEC-006 — Rich CMS HTML (stored XSS when published)

| Field | Detail |
|-------|--------|
| **Severity** | **Medium** (by design trade-off) |
| **Location** | `includes/partials/cms-page-body.php` line 21; `admin/cms/preview.php` line 33 |
| **Endpoint** | `/page.php?slug=…`, admin preview |
| **Attacker** | Admin with `cms.manage` (or compromised admin session) |
| **Scenario** | Malicious `body_html` stored and rendered unescaped on publish |
| **Evidence** | `<?= $cmsBody ?>` without `Security::escape` |
| **Impact** | XSS against storefront visitors or admins viewing preview |
| **Fix** | HTML sanitizer on save; CSP `script-src`; least-privilege CMS editors |
| **Regression** | Publish rejects `<script>`; CSP blocks inline |

### AT-SEC-007 — Upload directory PHP execution not enforced in app `.htaccess`

| Field | Detail |
|-------|--------|
| **Severity** | **Medium** (host-dependent) |
| **Location** | `.htaccess` comment line 14; `uploads/` |
| **Endpoint** | Direct URL to uploaded files |
| **Attacker** | Authenticated uploader (CMS/support) |
| **Scenario** | If MIME sniffing bypass + PHP execution in uploads → RCE |
| **Evidence** | `Upload::storeImage` uses finfo + random names (good); no `FilesMatch` deny in repo |
| **Impact** | RCE on weak Apache configs |
| **Fix** | Deny PHP in uploads; serve uploads from separate domain; `Content-Disposition: attachment` for PDFs |
| **Regression** | Upload `.php` not executable via HTTP |

### AT-SEC-008 — Missing Content-Security-Policy

| Field | Detail |
|-------|--------|
| **Severity** | **Medium** |
| **Location** | `includes/security.php` — `sendSecurityHeaders()` |
| **Evidence** | X-Frame-Options, nosniff, HSTS (HTTPS only); no CSP |
| **Impact** | XSS impact amplification |
| **Fix** | Deploy nonce-based or strict CSP per layout |

### AT-SEC-009 — Session lifetime without server-side idle invalidation

| Field | Detail |
|-------|--------|
| **Severity** | **Low** |
| **Location** | `AppSession::start()` — cookie lifetime default 7200s |
| **Evidence** | No `last_activity` check in `AdminAuth::check()` |
| **Impact** | Stolen session cookie usable until expiry |
| **Fix** | Idle timeout + optional absolute timeout server-side |

### AT-SEC-010 — Admin login enumeration

| Field | Detail |
|-------|--------|
| **Severity** | **Low** |
| **Location** | `admin/login.php` — generic "Invalid email or password" |
| **Evidence** | Same message for bad email vs password (**good**); email field still allows guessing valid accounts via timing (unverified) |
| **Status** | Partially mitigated |

### AT-SEC-011 — Unauthenticated order-sensitive support ticket creation

| Field | Detail |
|-------|--------|
| **Severity** | **High** |
| **Remediation status** | **Remediated** (Phase 1) — see [SECURITY_REMEDIATION_PHASE1.md](./SECURITY_REMEDIATION_PHASE1.md) |
| **Location** | `support.php` (POST handler); `includes/trust/support.php` — `SupportTicketService::create()` |
| **Endpoints** | `POST /support.php` (sole ticket-creation entry point; **no** separate AJAX create API) |
| **Attacker** | Unauthenticated remote (browser or scripted POST with CSRF token from guest session) |
| **Required behavior (not met)** | Refund, payment, and delivery-related tickets require an authenticated customer; `customer_id` must come from session; order-linked tickets must reference orders owned by that customer; guest general enquiries may remain with spam controls |
| **Actual behavior** | Page and service accept **`customer_id = null`** for **all** categories, including `refund`, `payment`, and `late_delivery`. UI exposes every category to guests. `support.php` sets `$customerId` from `CustomerAuth::id()` only (POST cannot supply `customer_id`) — **good**. Order binding runs only when logged in via `OrderFulfillmentService::orderRowForCustomer($orderNumber, $customerId)` — guests cannot attach another customer’s order (**good**). Sensitive-category tickets still create with `customer_id` NULL and trigger admin notifications. |
| **Evidence (static)** | `support.php` lines 19–31: no login gate before `SupportTicketService::create()`. `SupportTicketService::create()` lines 31–77: category whitelist only; no auth check per category. |
| **Evidence (dynamic, 2026-11-03)** | Guest HTTP GET shows `<option value="refund">`. `SupportTicketService::create(null, 'refund', …)` returned `ok` once (audit probe, then deleted). POST without CSRF → HTTP 403. No CAPTCHA, honeypot, or IP rate limit on ticket create (contrast customer OTP limits in `CustomerAuth`). |
| **Order IDOR (authenticated)** | Intended control: foreign order number → `invalid_order`, no row (`scripts/test-support-access.php` asserts this when inserts succeed). |
| **Impact** | Anonymous refund/payment/delivery queue spam; fraudulent or misleading dispute workload; possible social-engineering of support staff; bypass of intended “logged-in customer only” trust workflows |
| **Fix** | Enforce in **`SupportTicketService::create()`** (and mirror in UI): categories `refund`, `payment`, `late_delivery`, `quality`, `subscription` require non-null authenticated `customer_id`; require `orderNumber` (or validated order) where business rules demand; allow guest **`other`** only; add per-IP / per-session rate limits and optional CAPTCHA for guest `other`; redirect unauthenticated users from sensitive categories on `support.php` |
| **Regression** | Guest POST/AJAX `refund`/`payment`/`late_delivery` → 401/403 or validation error; authenticated customer A + order B → rejected; guest `other` still works with rate limit; `scripts/test-support-ticket-auth-audit.php` extended after fix |

### AT-SEC-012 — Guest shown authenticated customer account chrome on `support.php`

| Field | Detail |
|-------|--------|
| **Severity** | **Medium** |
| **Remediation status** | **Remediated** (2026-11-03) |
| **Location** | `includes/partials/account-shell-start.php` (used by public `support.php` without login guard) |
| **Endpoints** | `GET /support.php`; sidebar links target protected pages (`/my-orders.php`, etc.) |
| **Attacker** | Unauthenticated visitor (UI confusion / social-engineering surface) |
| **Root cause** | Account shell always rendered full “My account” nav and **Logout** regardless of `CustomerAuth` / `CustomerService::isLoggedIn()`. Store header correctly showed Login while sidebar implied an active session. |
| **Authorization impact** | **No bypass** on retest: protected pages redirect to `login.php` (302); `logout.php` is POST+CSRF and only clears the caller’s session; support tickets enforced server-side (**AT-SEC-011**). Risk was misleading UX and trust in fake “logged-in” chrome. |
| **Evidence** | Screenshot (manual UI); `curl` guest `support.php` contained `at-account-nav__logout` and links to `/my-refunds.php` before fix; after fix: `scripts/test-customer-account-shell-security.php` **15/15 pass**. |
| **Fix** | `account-shell-start.php`: when not logged in, hide account nav + logout; show login/register CTA; guest breadcrumb without “My account”. |
| **Regression** | `php scripts/test-customer-account-shell-security.php` |

---

## Suspected / unverified (require targeted retest)

| ID | Topic | Notes |
|----|-------|-------|
| AT-SEC-S01 | IDOR on all admin `?id=` views | RBAC present; not every `id` fuzzed dynamically |
| AT-SEC-S02 | Concurrent checkout / inventory race | Idempotency on payments; reservation logic not load-tested |
| AT-SEC-S03 | Webhook replay at gateway edge | Dedupe table exists; no live replay test |
| AT-SEC-S04 | `admin_demo_role` session key | Legacy; production auth uses DB permissions — confirm no code path trusts query param alone |
| AT-SEC-S05 | Support attachment path if DB row poisoned | Path built from DB; admin-only write paths |
| AT-SEC-S06 | Support `ticket_number` collisions | `nextTicketNumber()` uses `COUNT(*)+1`; duplicate key can block creates on busy DB (observed during audit probes); separate reliability issue |

---

## Positive controls (informational)

1. **Payment:** Server-side totals; Razorpay/Cashfree webhook HMAC; simulate blocked in production; `assertPaymentExecutionAllowed`.
2. **Customer data:** Orders/invoices scoped by `customer_id` in SQL.
3. **Admin:** Central `AdminGuard`; seed password forced change; AJAX slug/notifications routed through `_init.php` (Phase 4.1).
4. **CSRF:** Widespread on POST AJAX and forms.
5. **SQLi:** Admin list sort whitelisting (`AdminServerList::orderBySql`).
6. **Sessions:** `session_regenerate` on admin/customer login and mandatory password change.
7. **Cookies:** HttpOnly, SameSite=Lax defaults in config example.
8. **Storage:** `storage/invoices/.htaccess` deny; rewrite blocks `/storage/` when docroot is project root.

---

## OWASP Top 10:2025 mapping (snapshot)

| Risk area | Assessment |
|-----------|------------|
| A01 Broken Access Control | **High** concern: scripts URL exposure; admin brute force; **guest sensitive support tickets (AT-SEC-011)**; otherwise RBAC + customer scoping |
| A02 Security Misconfiguration | **High**: scripts web access, install/health public |
| A03 Software Supply Chain | **Low**: no Composer lockfile; static JS in repo |
| A04 Cryptography | Invoice encryption path enforced in prod cron; keys not audited here |
| A05 Injection | **Low** in reviewed repos; CMS HTML is content injection |
| A06 Insecure design | Missing admin recovery |
| A07 Authentication failures | **High**: no admin rate limit; OTP limits for customers only |
| A08 Data integrity | Webhooks verified; CSRF on mutations |
| A09 Logging | Audit log for admin actions; no SIEM review |
| A10 Mishandling exceptions | `app.debug` must stay false in prod (config template) |

---

## Critical blockers (staging security)

1. **AT-SEC-001** — Block web access to `/scripts/`.
2. **AT-SEC-002** — Admin login rate limiting.
3. **AT-SEC-011** — Auth + rate limits on order-sensitive support tickets (service-layer enforcement).
4. **AT-SEC-003** — Plan admin recovery (or documented break-glass only).
5. **AT-SEC-004** — Remove public install/health from internet-facing staging/prod.
6. Deploy checklist: HTTPS, `cookie_secure`, secrets outside docroot, empty catalogue / payments per Phase 4.

---

## Staging security checklist (excerpt)

See [SECURITY_REMEDIATION_PLAN.md](./SECURITY_REMEDIATION_PLAN.md) for ordered fixes and tests.

- [ ] `/scripts/` not HTTP-accessible  
- [ ] Admin rate limit enabled  
- [ ] `install.php` / `health-check.php` disabled or restricted  
- [ ] `ajax/ping.php` does not leak env  
- [ ] Uploads + PHP execution denied at web server  
- [ ] Forced seed password change verified on fresh import  
- [ ] Production payment guards verified (`StorefrontReadiness::productionBlockers()` empty when ready)  
- [ ] Invoice path outside docroot  
- [ ] Guest cannot open refund/payment/delivery support tickets (**AT-SEC-011**)  

---

## Disclaimer

This report reflects the codebase and **limited** dynamic tests on **2026-11-03**. It does **not** certify compliance with OWASP ASVS level 2/3 in full. Re-audit after remediation and before production cutover.
