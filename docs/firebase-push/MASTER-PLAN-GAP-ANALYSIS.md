# Master 7-Phase Plan — Gap Analysis

**Audit date:** 2026-11-10  
**Repository:** `/var/www/html/Apni_Tokri`  
**Method:** Code inspection + limited CLI tests (no live FCM, no browser, no APK).

Legend: **COMPLETE** | **PARTIAL** | **MISSING** | **BLOCKED** | **N/A**

---

## Phase 1 — Existing System Audit & Architecture

| Requirement | Status | Implementation / evidence | Remaining work |
|-------------|--------|---------------------------|----------------|
| Full codebase audit documented | **COMPLETE** | [`PHASE-1-AUDIT.md`](PHASE-1-AUDIT.md) | Refresh after major changes |
| Architecture doc | **COMPLETE** | [`ARCHITECTURE.md`](ARCHITECTURE.md) | — |
| PHP/Composer/Firebase prior state | **COMPLETE** | PHP 8.4, no Composer; custom FCM HTTP v1 | Composer Admin SDK optional later |
| Service worker conflict analysis | **COMPLETE** | No prior SW; `firebase-messaging-sw.js` at root | — |
| Android repo location | **COMPLETE** | `android-webview/` reference only | Full Gradle project |
| Hosting/outbound HTTPS notes | **COMPLETE** | PHASE-1-AUDIT | Verify on cPanel staging |

---

## Phase 2 — Multi-Firebase Project Management

| Requirement | Status | Files | Verification | Remaining |
|-------------|--------|-------|--------------|-----------|
| Add / edit project | **COMPLETE** | `admin/firebase/edit.php`, `FirebaseAdminService::saveProject` | UI + encrypted SA JSON | — |
| List projects | **COMPLETE** | `admin/firebase/index.php` | Manual UI | — |
| Activate / deactivate | **COMPLETE** | `FirebaseAdminService::activate/deactivate` | Txn + credential test before activate | — |
| Delete with dependency checks | **COMPLETE** | Blocks active project + active device count | Code review | — |
| Test credentials (OAuth) | **COMPLETE** | `testExisting`, `testCredentials` | Unit mock passed; live OAuth **BLOCKED** without Firebase JSON | Staging test with real SA |
| Service account encrypted (SecretStore) | **COMPLETE** | `firebase_credential_manager.php` | No secrets in API responses | — |
| Masked credentials in admin | **COMPLETE** | `edit.php` shows client_email only | — | — |
| CSRF + `firebase.manage` permission | **COMPLETE** | Admin `_init`, migration 1.39.0 | — | — |
| Audit on config changes | **COMPLETE** | `AuditLog::write` | — | — |
| Separate Web vs Android fields | **PARTIAL** | Columns on `firebase_projects`; **one active globally** | Schema supports both platforms in one row | Future split per-platform active project |
| Rollback to previous active project | **COMPLETE** | `rollbackToPrevious()`, `push.firebase.rollback_project_id` | Logic only; not live-tested | Staging activation test |
| Admin push operational settings UI | **COMPLETE** (this audit) | `admin/push/settings.php`, `PushSettings` | — | — |

---

## Phase 3 — Central PHP Notification Engine

| Requirement | Status | Files | Verification | Remaining |
|-------------|--------|-------|--------------|-----------|
| FCM HTTP v1 (no legacy server key) | **COMPLETE** | `FirebaseFcmClient`, `FirebaseOAuth` | OAuth unit test passed | Live send **BLOCKED** |
| OAuth token cache + invalidation | **COMPLETE** | `FirebaseOAuth`, invalidate on activate | Unit test | — |
| Web: `fid` target; Android: `token` | **COMPLETE** | `PushNotificationService::buildMessage` | `test-firebase-push-fid-targeting.php` **passed** | — |
| Idempotency / duplicate prevention | **COMPLETE** | `push_notification_logs.idempotency_key` | Schema test **skipped** (no disposable DB) | Disposable DB integration |
| Dry-run / enable flags | **COMPLETE** | `PushSettings`, `push.enabled`, `push.dry_run` | Defaults in code | Admin UI save on staging |
| Structured logging (no secrets) | **COMPLETE** | `push_notification_logs` redacted title | — | — |
| Invalid token → mark invalid | **COMPLETE** | `PushTokenService::markInvalid` | Not live-tested | — |
| Firebase Admin SDK (PHP) | **N/A** | No Composer in repo | Custom JWT + curl is intentional | Add Composer only if approved |

---

## Phase 4 — Website Firebase Web Push

| Requirement | Status | Files | Verification | Remaining |
|-------------|--------|-------|--------------|-----------|
| Firebase JS **13.2.0** | **COMPLETE** | `push-notifications.mjs`, SW compat 13.2.0 | Code review | Browser E2E **skipped** |
| FID `register()` / `onRegistered()` | **COMPLETE** | `push-notifications.mjs` | Code review (no `getToken`) | HTTPS staging test |
| `unregister()` / `onUnregistered()` | **COMPLETE** | `push-notifications.mjs` | Code review | Browser test |
| Dynamic config from PHP | **COMPLETE** | `ajax/push-config.php` | — | — |
| Service worker background handler | **COMPLETE** | `firebase-messaging-sw.js` | Not browser-tested | Staging |
| My account enable/disable UI | **COMPLETE** | `my-account.php` | Not browser-tested | — |
| Permission denied handling | **COMPLETE** | localStorage `at_push_denied_v2` | Code review | — |
| Account switch / logout token cleanup | **PARTIAL** | `onUnregistered` on disable; logout does not auto-revoke server-side | — | Optional `CustomerService::logout` hook |
| Config version / project switch | **COMPLETE** | `configVersion` in config + localStorage | — | Re-register after switch |

---

## Phase 5 — Android Native FCM

| Requirement | Status | Evidence | Remaining |
|-------------|--------|----------|-----------|
| Native FCM in WebView wrapper | **MISSING** (scaffold only) | [`ANDROID-BUILD-GAP-ANALYSIS.md`](ANDROID-BUILD-GAP-ANALYSIS.md) | Full Gradle project + BoM + `google-services.json` |
| POST_NOTIFICATIONS | **PARTIAL** | Manifest permission declared | Runtime request in app |
| Token sync to PHP | **MISSING** | `PushRegistrationSync` placeholder | Implement HTTP + session |
| Debug APK build | **BLOCKED** | No Gradle wrapper | Android Studio build |
| Device/emulator test | **BLOCKED** | — | Hardware/emulator |

---

## Phase 6 — Admin Notification Management

| Requirement | Status | Files | Verification | Remaining |
|-------------|--------|-------|--------------|-----------|
| Push templates CRUD | **PARTIAL** | Edit existing in `admin/push/templates.php`; no add/delete keys | UI | Optional template create |
| Manual test send | **COMPLETE** | `admin/push/send.php`, `sendAdminTest` | Dry-run default | Live FCM **BLOCKED** |
| Send to customer | **COMPLETE** | `sendToCustomer` + announcement template | Dry-run | Rate limit 20/day added |
| Bulk / segment campaigns | **MISSING** | — | — | Out of scope unless product requests |
| Notification logs | **COMPLETE** | `admin/push/logs.php` | — | Filters/pagination optional |
| Device registrations admin | **COMPLETE** | `admin/push/devices.php` + revoke | Code | — |
| Event-driven order push | **COMPLETE** | `PushEventBridge` + `NotificationDispatcher` (independent of email) | Unit + code review | Staging order |
| Payment success only when paid | **COMPLETE** | `isOrderPaidAndCaptured` | — | — |
| No duplicate confirmed push | **COMPLETE** | Skip `confirmed` in status bridge | — | — |
| Subscription activated push | **COMPLETE** | `activation.php` after txn | — | — |
| Subscription pause/resume push | **PARTIAL** | `lifecycle.php` + migration `1.39.2` templates | Immediate pause + manual resume wired | Lazy auto-resume path not wired |
| Refund status push | **COMPLETE** | `refundStatusUpdated` bridge | — | — |
| Templates: delivery update, promo campaigns | **PARTIAL** | `offer.promo`, `announcement.general` seeded; no auto promo hook | Manual admin only | Wire offers if product needs |
| Subscription delivery update template | **MISSING** | No seeded template | — | Add when delivery event exists |

---

## Phase 7 — Project Switching, Token Lifecycle & Final QA

| Requirement | Status | Evidence | Remaining |
|-------------|--------|----------|-----------|
| Atomic project activation | **COMPLETE** | DB transaction | Staging test |
| Failed activation keeps prior active | **COMPLETE** | Test before txn; rollback on failure | — |
| Rollback UI | **COMPLETE** | `rollback_previous` on firebase index | Staging test |
| OAuth cache invalidate on switch | **COMPLETE** | `FirebaseOAuth::invalidateCache` | — |
| Multi-device per customer | **COMPLETE** | `device_push_registrations` | — |
| Web FID vs Android token separation | **COMPLETE** | `target_type` column | Apply 1.39.1 if needed |
| Final QA matrix (browser/Android/live FCM) | **PARTIAL** | [`TEST-REPORT.md`](TEST-REPORT.md) | All live tests **skipped/blocked** |

---

## Preserved implementations (do not revert)

- Firebase JS **13.2.0**, FID `register`/`onRegistered`/`unregister`/`onUnregistered`
- FCM HTTP v1 **`fid`** for web, **`token`** for Android
- Push **independent** of email delivery
- SecretStore for service account + OAuth cache keys
- Migrations **1.39.0**, **1.39.1** (target_type), **1.39.2** (subscription templates)

---

## Implementation completed in this gap pass

| Item | Files |
|------|-------|
| Push settings admin | `includes/push/push_settings.php`, `admin/push/settings.php` |
| Project rollback | `FirebaseAdminService::rollbackToPrevious`, `admin/firebase/index.php` |
| Device admin revoke | `PushTokenService::adminRevokeById`, `admin/push/devices.php` |
| Manual send daily limit | `PushSettings::recordManualSendAttempt` |
| Subscription pause/resume push | `push_event_bridge.php`, `lifecycle.php`, `1.39.2` migration |
| FID targeting unit test | `scripts/test-firebase-push-fid-targeting.php` |

---

## Production blockers (external)

1. Apply migrations **1.39.0**, **1.39.1** (if applicable), **1.39.2** on staging.
2. Firebase Console: project, Web app, VAPID, Android app, service account, FCM Registration API if required.
3. Staging HTTPS: browser FID registration + dry-run off smoke test.
4. Android: complete Gradle project + debug APK build.
5. Live FCM delivery verification (test FID/device only).
