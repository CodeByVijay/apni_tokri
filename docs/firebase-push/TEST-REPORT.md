# Test Report — Firebase Push

**Last updated:** 2026-11-10 (Phase 3 — HTTPS staging browser verification attempt)

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
| HTTPS staging browser tests | **PARTIAL** (see §9 — HTTP dev server only; no HTTPS; no FCM delivery) |

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

Inspected with `php scripts/inspect-firebase-push-migration-status.php` (**no migrations executed** in verification runs).

| Item | Result (2026-11-10 Phase 3) |
|------|------------------------------|
| `settings.schema_version` | **`1.39.2-push-sub-templates`** |
| Four push tables | **PRESENT** |
| `device_push_registrations.target_type` | **PRESENT** (`enum('fid','fcm_token')`) |
| `firebase.manage` | **PRESENT** |
| `subscription.paused.push` / `resumed.push` | **PRESENT**, active |
| **1.39.0 / 1.39.1 / 1.39.2** | **Applied** (inferred from schema + objects) |

Earlier Phase 2 note (schema 1.15.0) is **superseded** — migrations were applied on `apni_tokri` before Phase 3.

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

## 8. 7-phase snapshot

| Phase | Status |
|-------|--------|
| 1 Audit | Complete (docs) |
| 2 Multi-Firebase admin | DB migrated; **no Firebase project rows configured yet** |
| 3 PHP engine | Disposable **PASS**; live FCM **not verified** |
| 4 Web push | SDK 13.2.0 in repo; browser E2E **blocked** (see §9) |
| 5 Android | Scaffold only |
| 6 Admin mgmt | UI reachable (login required); config incomplete |
| 7 Switching & QA | Disposable **PASS**; staging browser **partial** |

---

## 9. Phase 3 — Staging / browser verification (2026-11-10)

**Configuration context (no secrets):** `app.env` = `local`, `database.name` = `apni_tokri`, `database.role` unset (not production). Canonical `base_url` in config points at **veggietokri.com** — browser tests used **local PHP 8.4** built-in server only (`http://127.0.0.1:8765`), **not** HTTPS and **not** the public production host.

### 9.1 Staging database — **PASS**

`php scripts/inspect-firebase-push-migration-status.php` — all Phase 3 checks satisfied. No migrations applied during this pass.

### 9.2 Admin Firebase configuration — **FAIL / incomplete**

`php scripts/inspect-firebase-staging-readiness.php`:

| Check | Result |
|-------|--------|
| `push_dry_run` effective | **PASS** — dry-run **on** (non-production default; empty settings) |
| `push.enabled` | Unset (sends follow non-prod defaults) |
| Active Firebase project | **NONE** (`firebase_project_count: 0`) |
| Web / VAPID / service account | **Not configured** |
| Credential test / activation | **NOT EXECUTED** (no project) |

Browser: navigated to `/admin/firebase/` → redirected to **admin login** (`200`). Firebase admin UI **not inspected** (credentials not used in automation).

### 9.3 Website browser verification — **PARTIAL / mostly SKIPPED**

| Step | Result | Evidence |
|------|--------|----------|
| Apache `http://127.0.0.1/Apni_Tokri/` | **FAIL** | `PHP 7` parse errors (union types) — **use PHP 8.4** for web SAPI |
| PHP 8.4 built-in server homepage | **PASS** | Browser loaded `http://127.0.0.1:8765/` (`200`) |
| `GET /ajax/push-config.php` | **PASS** | JSON `{"success":true,"data":{"enabled":false},"message":"Push not configured"}` |
| `registrationModel: fid` | **SKIPPED** | Only returned when push **enabled** with active web config |
| `push-notifications.mjs` on homepage | **SKIPPED** | Script omitted when `FirebaseProjectManager::publicWebConfig()` is null |
| `assets/js/push-notifications.mjs` SDK | **PASS** | Source contains `const SDK = '13.2.0'` (direct fetch `200`) |
| Permission / `register()` / FID POST | **NOT EXECUTED** | Requires active Firebase web config + HTTPS context for real FCM |
| Admin Devices `target_type=fid` | **NOT EXECUTED** | No registrations (`active_device_registrations: 0`) |
| Unregister flow | **NOT EXECUTED** | Same blocker |

**HTTPS:** No TLS staging host was available in this environment — steps requiring **HTTPS + service worker** need a real staging vhost (PHP 8.4+).

### 9.4 Dry-run notification — **SKIPPED**

`php scripts/staging-firebase-dry-run-verify.php` → exit **3**: *no active Firebase project*. Script **refuses** to run if dry-run is off or `database.role` is production.

**Manual after admin setup:** with dry-run **ON**, run the script again or trigger `PushNotificationService::sendToCustomer` and confirm `push_notification_logs.status = dry_run`.

### 9.5 Single test device (live FCM) — **NOT EXECUTED**

Dry-run remains **ON**. **Explicit approval required** before setting `push.dry_run=0` and using Admin → Send test device.

### 9.6 Summary table

| Status | Items |
|--------|--------|
| **PASS** | DB migrations 1.39.x; dry-run default on; push-config endpoint; SDK 13.2.0 in module file; admin login gate |
| **FAIL** | No Firebase project configured; Apache PHP 7 breaks main docroot |
| **SKIPPED** | FID registration E2E, devices admin, dry-run log probe, live FCM |
| **Manual required** | (1) Fix web PHP to **8.4+**. (2) Add HTTPS staging vhost. (3) Admin → create Firebase project, upload SA, web+VAPID, **Test**, **Activate**. (4) Confirm Push settings dry-run **ON**. (5) Browser: enable notifications, verify FID in Devices. (6) Run `staging-firebase-dry-run-verify.php`. (7) Optional live test **only with approval**. |

### Helpers added (read-only / safe probes)

- `scripts/inspect-firebase-staging-readiness.php`
- `scripts/staging-firebase-dry-run-verify.php` (aborts if dry-run off or no active project)
