# Test Report — Firebase Push

**Last updated:** 2026-11-10 (Phase 2 verification — continued with `config.local.php`)

FID + Firebase JS **13.2.0** architecture unchanged. No production DB writes, no live FCM to customers, no commit/push/deploy in this pass.

---

## Executive summary

| Area | Result |
|------|--------|
| Application DB connectivity | **PASS** (`Install::ensureConnection` via `config.local.php`) |
| Disposable MySQL privileges | **PASS** (CREATE / DDL / DROP on `apni_tokri_tmp_*` only) |
| **Configured app database** migration status | **NOT APPLIED** (see §2 — do not confuse with disposable test DB) |
| Disposable integration (`test-firebase-push-integration.php`) | **PASS** (31/31) |
| Disposable schema test | **PASS** (4/4) |
| Migration 1.39.1 idempotent re-apply | **PASS** |
| Unit / regression scripts | **PASS** |
| HTTPS staging browser tests | **NOT EXECUTED** |

---

## 1. Database connection

| Check | Status | Notes |
|-------|--------|-------|
| `config.local.php` present | **PASS** | `Install::hasLocalConfig()` true |
| PDO connect to configured database | **PASS** | Host `127.0.0.1`, database name `apni_tokri` |
| Disposable CREATE/DROP/DDL | **PASS** | `scripts/verify-disposable-mysql-permissions.php` |

Credentials are not recorded in this report.

---

## 2. Migration status (configured application database — read-only)

Inspected with `php scripts/inspect-firebase-push-migration-status.php` (**no migrations executed** on `apni_tokri`).

| Item | Result |
|------|--------|
| `settings.schema_version` | `1.15.0-stage14-reports-6` |
| `firebase_projects` | **MISSING** |
| `device_push_registrations` | **MISSING** |
| `push_notification_templates` | **MISSING** |
| `push_notification_logs` | **MISSING** |
| `device_push_registrations.target_type` | **MISSING** |
| `firebase.manage` permission | **MISSING** |
| Subscription pause/resume templates | **MISSING** (no templates table) |
| **1.39.0 / 1.39.1 / 1.39.2 on this DB** | **NOT APPLIED** |

**Interpretation:** Migrations may have been applied on **another** environment (e.g. staging). This workstation’s **`apni_tokri`** database is still on schema **1.15.0** without Firebase objects. Admin Firebase screens on **this** DB will show “migration not applied.”

**Action (local dev, not done in this task):** Apply `1.39.0` → `1.39.1` (if upgrading an old 1.39.0 without `target_type`) → `1.39.2` on `apni_tokri` when you want local admin push UI against real data — **not** required for disposable integration tests.

---

## 3. Disposable database integration

Commands run successfully; each run creates and drops `apni_tokri_tmp_*` only.

| Test | Status | Detail |
|------|--------|--------|
| `run-in-disposable-db.php --migrate-firebase-push …/test-firebase-push-integration.php` | **PASS** | 31 assertions |
| `run-in-disposable-db.php …/test-firebase-push-schema.php` | **PASS** | 4 assertions |
| `run-in-disposable-db.php …/test-firebase-push-migration-idempotent.php` | **PASS** | 1.39.1 re-apply after 1.39.0 |

### Covered in disposable integration

- Tables, `target_type`, permissions, templates (incl. pause/resume)
- Service account encrypt/decrypt (SecretStore)
- Project create, activate, failed activate, rollback pointer, rollback
- Web FID + Android `fcm_token` registration, revoke
- Dry-run customer send, idempotency, logs, URL validator
- Delete guard with active devices
- Manual send rate limit (20/day)
- OAuth cache invalidation on activate (mocked HTTP)

Fresh disposable path applies **production SQL import + 1.39.0 + 1.39.1 + 1.39.2** (not the live `apni_tokri` schema).

---

## 4. Backend / regression tests

| Test | Status |
|------|--------|
| `scripts/test-firebase-push-oauth-unit.php` | **PASS** |
| `scripts/test-firebase-push-fid-targeting.php` | **PASS** |
| `scripts/test-production-readiness.php` | **PASS** |
| `scripts/test-firebase-push-bridge-unit.php` | **PASS** (new) |

### Backend paths — evidence

| Path | Disposable integration | Unit / static |
|------|------------------------|---------------|
| FID registration | **PASS** | — |
| Android token registration | **PASS** | FID targeting unit |
| Project activation / rollback | **PASS** (mock OAuth) | — |
| OAuth cache invalidation | **PASS** | OAuth unit |
| Device registration / revoke | **PASS** | — |
| Manual send rate limit | **PASS** | — |
| Order confirmed duplicate prevention | **NOT EXECUTED** (needs paid order fixture) | **PASS** — `orderStatusChanged` early-return on `confirmed` + dispatcher `try/catch` in bridge unit |
| Subscription activation / pause / resume push | **NOT EXECUTED** (needs subscription rows) | Templates present after migration; lifecycle calls verified by code review |
| Refund notifications | **NOT EXECUTED** | Bridge unit: no throw on invalid id |
| Push isolated from email / payment | **NOT EXECUTED** E2E | **PASS** — dispatcher source check in bridge unit |

External Firebase HTTP: **mocked** in integration (`FirebaseOAuth::$testHttpHandler`); dry-run default prevents real sends in disposable runs.

---

## 5. Bugs found and fixed (this pass)

| Issue | Fix |
|-------|-----|
| Migration **1.39.1** failed under PDO (`InstallSqlImporter`) — unbuffered result sets after `EXECUTE` of dynamic SQL | `sql_importer.php`: use `query()` + drain `fetchAll()` / `nextRowset()`; `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` on connections |
| `test-firebase-push-schema.php` dry-run assertion failed (wrong settings keys, no device) | Use `PushSettings` keys, seed FID for disposable customer |
| Disposable runner overwrote `schema_version` after Firebase migrations | Skip downgrade when Firebase migrations run (prior pass) |
| Rate limit test needed admin session | `$_SESSION['admin_user_id']` from seeded admin (prior pass) |

**New regression tests**

- `scripts/test-firebase-push-migration-idempotent.php`
- `scripts/test-firebase-push-bridge-unit.php`

**Read-only ops helpers**

- `scripts/inspect-firebase-push-migration-status.php`
- `scripts/verify-disposable-mysql-permissions.php`

---

## 6. Failures / skipped / not executed

### FAIL

None in executed automated tests (after fixes).

### SKIPPED

| Item | Reason |
|------|--------|
| Apply 1.39.x on configured `apni_tokri` | Task forbids blind migration on main app DB; inspect showed not applied — operator decision |
| E2E bridge with real orders / subscriptions / refunds | No disposable fixture in integration suite yet |
| Browser HTTPS staging | Not run in this pass |
| Live FCM delivery | Dry-run / mocks only |

### NOT EXECUTED

- Full `scripts/test-*.php` catalogue
- Android APK
- Payment checkout with push logs on staging

---

## 7. Staging HTTPS browser testing — next steps

1. **Confirm DB on staging** (read-only): run the SQL checklist from Phase 2 doc or `php scripts/inspect-firebase-push-migration-status.php` against staging DSN via staging `config.local.php` — expect schema `1.39.2-push-sub-templates` and all four push tables.
2. **Firebase project in admin:** Add project, upload service account, fill web + VAPID fields, **Test** credentials, **Activate** (note rollback target).
3. **Push settings:** Enable push; keep **dry-run ON** until step 7; verify manual send counter.
4. **HTTPS storefront:** Open site over HTTPS; confirm `GET /ajax/push-config.php` returns `sdkVersion: 13.2.0`, `registrationModel: fid`, active project config (no service account).
5. **Web push:** Grant permission; confirm SW `firebase-messaging-sw.js` loads; `POST /ajax/push-register.php` with `fid` returns success; row appears in Admin → Device registrations (`target_type=fid`).
6. **Dry-run event:** Trigger a test order payment success path on staging with dry-run — confirm `push_notification_logs` has `dry_run` rows, no customer-visible notification.
7. **Controlled live test:** One admin test device only — set `push.dry_run=0`, send **Test device** from Admin → Send; verify FCM delivery; re-enable dry-run.
8. **Project switch QA:** Activate alternate project; confirm `push-config` `configVersion` changes; re-register browser; optional **Rollback** test.
9. **Regression spot-check:** Order status push does not duplicate on `confirmed`; subscription pause/resume (manual paths) with templates 1.39.2.

---

## 8. 7-phase snapshot (unchanged product view)

| Phase | Status |
|-------|--------|
| 1 Audit | Complete (docs) |
| 2 Multi-Firebase admin | Code complete; **local `apni_tokri` DB not migrated** |
| 3 PHP engine | Disposable **PASS**; live FCM unverified |
| 4 Web push | Code complete; browser **pending staging** |
| 5 Android | Scaffold only |
| 6 Admin mgmt | Code complete |
| 7 Switching & QA | Disposable **PASS**; staging E2E **pending** |
