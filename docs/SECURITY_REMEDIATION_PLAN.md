# Security remediation plan (pre-staging)

**Date:** 2026-11-03  
**Source audit:** [SECURITY_AUDIT_REPORT.md](./SECURITY_AUDIT_REPORT.md)  
**Status:** Phase 1 complete in code — see [SECURITY_REMEDIATION_PHASE1.md](./SECURITY_REMEDIATION_PHASE1.md). **BLOCKERS REMAIN** (migration deploy, medium/low items).

Prioritize **Critical → High → Medium**; medium/low may parallelize with staging hardening from [PRODUCTION_DEPLOYMENT_READINESS.md](./PRODUCTION_DEPLOYMENT_READINESS.md).

---

## Priority 0 — Before any internet-facing staging

| Order | ID | Action | Owner | Est. |
|------:|-----|--------|-------|------|
| 1 | AT-SEC-001 | Deny HTTP to `/scripts/` (Apache `LocationMatch` or move docroot); add `.htaccess` `RewriteRule ^scripts/ - [F,L]` | Hosting / DevOps | S |
| 2 | AT-SEC-004 | Remove or restrict `install.php`, `health-check.php` on staging/prod (404 or IP allowlist) | DevOps | S |
| 3 | AT-SEC-005 | Stop returning `env` from `ajax/ping.php` or require auth; disable ping in production | Engineering | S |

**Verification**

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'https://STAGING/scripts/test-production-readiness.php'   # expect 403/404
curl -s 'https://STAGING/ajax/ping.php' | jq .data.env   # expect absent or 401
```

---

## Priority 1 — High (account security)

| Order | ID | Action | Regression tests |
|------:|-----|--------|------------------|
| 4 | AT-SEC-002 | Implement admin login throttling (IP + email), lockout audit log | New `scripts/test-admin-login-rate-limit.php`; extend `test-admin-auth-security.php` |
| 5 | AT-SEC-003 | Design **Forgot password** for admin/staff: hashed single-use tokens, expiry, SMTP, generic response, invalidate sessions on reset | New flow tests; no secret in response |
| 6 | AT-SEC-011 | Enforce authenticated-only categories in `SupportTicketService::create()`; guest `other` + rate limit/CAPTCHA; hide sensitive categories on `support.php` for guests | `scripts/test-support-ticket-auth-audit.php`; guest POST refund → denied |
| 7 | AT-SEC-012 | Guest account shell on `support.php` — nav/logout gated on `CustomerService::isLoggedIn()` | `scripts/test-customer-account-shell-security.php` |
| — | (Phase 4.1) | Keep mandatory seed hash change; verify after fresh SQL import | `run-phase4-fresh-install-audit.php` |

**AT-SEC-003 minimum ASVS-aligned requirements (when implemented)**

- Token: `random_bytes` → store **hash** only  
- Expiry: ≤ 1 hour  
- Single-use + invalidate other sessions for user  
- Rate limit forgot-password POST  
- Same response whether email exists or not  

---

## Priority 2 — Medium (defence in depth)

| Order | ID | Action | Regression tests |
|------:|-----|--------|------------------|
| 6 | AT-SEC-006 | CMS HTML sanitization (allowlist tags) on save/publish | XSS fixture rejected |
| 7 | AT-SEC-008 | Add CSP headers (start report-only, then enforce) | Header present on storefront/admin |
| 8 | AT-SEC-007 | `uploads/` — deny PHP execution; optional separate static host | Upload `.php` not served as script |
| 9 | AT-SEC-009 | Server-side admin session idle timeout | Idle > N min → re-auth |

---

## Priority 3 — Low / hardening

| ID | Action |
|----|--------|
| AT-SEC-010 | Optional constant-time login path; monitor timing |
| — | Add `Permissions-Policy` header |
| — | Dependency pinning if Composer/npm introduced later |

---

## Suspected items — verify during remediation

| ID | Verification task |
|----|-------------------|
| AT-SEC-S01 | IDOR sweep: admin `view.php?id=` with two staff roles + customer order numbers |
| AT-SEC-S02 | Load test checkout + slot reservation (disposable DB) |
| AT-SEC-S03 | Webhook dedupe replay with test payload on staging gateway |
| AT-SEC-S04 | Grep/runtime proof `admin_demo_role` query param cannot set role when authenticated |
| AT-SEC-S05 | Support upload path canonicalization audit |

---

## Security test coverage (target after remediation)

| Suite | Current | Target |
|-------|---------|--------|
| `test-admin-auth-security.php` | Unauthenticated + partial login | + rate limit + forgot-password (when built) |
| `test-production-readiness.php` | Env guards | Keep in CI CLI only |
| Payment/subscription hardening | `test-phase22-production-blockers.php` | Run on staging clone |
| HTTP smoke | Manual curl checklist above | Automated in CI against staging URL |

---

## Residual risks (accept or track)

- **CMS trusted editors** — rich HTML remains a policy/control issue even with sanitization.  
- **Webhook volume / DDoS** — rely on gateway + WAF; app-level rate limit optional.  
- **Host misconfiguration** — docroot, DB credentials, backup exposure outside app code.  
- **No Composer lockfile** — manual tracking of PHP/runtime CVEs.  

---

## Sign-off criteria (staging)

- [ ] Zero **Critical** open findings  
- [ ] Zero **High** open findings **or** documented break-glass for AT-SEC-003 with expiry date  
- [ ] AT-SEC-001 verified on staging URL  
- [ ] Phase 4 production blockers addressed per merchant/hosting checklist  
- [ ] Re-run audit spot-check documented in `SECURITY_AUDIT_REPORT.md` appendix (date TBD)

**Do not claim:** “fully secure” or “hacker-proof.”
