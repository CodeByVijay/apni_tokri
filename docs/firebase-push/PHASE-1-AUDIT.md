# Phase 1 — Existing system audit (Apni Tokri / Veggie Tokri)

**Date:** 2026-11-10  
**Workspace:** `/var/www/html/Apni_Tokri`

## Runtime & dependencies

| Item | Finding |
|------|---------|
| PHP | **8.4.27** (CLI) |
| Composer | **Not present** — no `composer.json`; Firebase Admin SDK for PHP not available without adding Composer |
| Framework | Custom PHP + MySQL (folder-based includes, not Laravel) |
| Encryption | Existing `SecretStore` (libsodium `crypto_secretbox`) + master key file via `InvoiceProductionSecurity` |

## Existing notifications

- **Email/SMS queue:** `notification_queue`, `NotificationQueueService`, `NotificationDispatcher`, cron worker.
- **Channels today:** `email`, `sms`, `in_app` only — **no push channel**.
- **Order events:** `NotificationDispatcher::orderPaymentSuccess`, `orderStatusChanged` (email, synchronous send for status).
- **Push (FCM):** None before this implementation.

## Service workers

- Test `scripts/test-admin-http-cache.php` asserts **no service worker** existed previously.
- New root-scope worker: `firebase-messaging-sw.js` (FCM only; does not replace any prior SW).

## Admin architecture

- RBAC via `admin_permissions`, `Permission::canModule()`.
- Settings pattern: `admin/settings/*.php`, `AppSettings` + encrypted secrets.
- Audit: `AuditLog::write()`.

## Customer / device identity

- Logged-in customers: `CustomerAuth`, session + remember tokens (`customer_remember_tokens` migration 1.38.0).
- **No prior FCM token table.**

## Android

- **No Gradle project in repo** before scaffold under `android-webview/` (reference only).
- Play Store links only in `BrandingAppStores`.

## Hosting

- cPanel-style deployment; outbound HTTPS via `curl` (`PaymentHttp`).
- FCM HTTP v1 requires OAuth to `oauth2.googleapis.com` and POST to `fcm.googleapis.com` — typical shared hosting allows this if `curl` + OpenSSL enabled.

## Security risks (pre-implementation)

- Storing service account JSON in DB without encryption (mitigated: use `SecretStore`).
- Exposing API key in JS (acceptable for Firebase Web **public** config; private key must never reach browser/APK).
- Arbitrary notification click URLs (mitigated: `PushUrlValidator` + canonical host).

## Implementation architecture (delivered)

- **DB:** migration `1.39.0_firebase_push_notifications.sql`
- **PHP:** `includes/push/*` — FCM HTTP v1 via service-account JWT (no legacy server key)
- **Admin:** `admin/firebase/*`, `admin/push/*`
- **Web:** `assets/js/push-notifications.js`, `ajax/push-*.php`, my-account UI
- **Android:** scaffold `android-webview/` (not build-verified in this environment)

## Files/modules touched (summary)

| Area | Action |
|------|--------|
| `includes/bootstrap.php` | Load push services |
| `includes/notifications/dispatcher.php` | Hook `PushEventBridge` after email enqueue |
| `includes/permissions.php` | Module `firebase` → `firebase.manage` |
| `includes/layout/admin.php` | Nav item |
| `my-account.php`, `footer-public.php` | Web push UI/scripts |
| New | `includes/push/*`, `admin/firebase/*`, `admin/push/*`, `ajax/push-*`, `firebase-messaging-sw.js` |

## External actions required

1. Firebase Console: create project(s), Web app, Android app, VAPID key, service account JSON.
2. Apply migration **1.39.0** on staging (not production per task rules).
3. Android Studio build + device/emulator for native FCM verification.
4. Real FCM send test only with explicit credentials and non-production dry-run disabled intentionally.

## Official documentation referenced

- FCM overview & HTTP v1: https://firebase.google.com/docs/cloud-messaging, https://firebase.google.com/docs/cloud-messaging/send/v1-api
- Web client: https://firebase.google.com/docs/cloud-messaging/js/client
- Android client: https://firebase.google.com/docs/cloud-messaging/android/client
- API key security: https://firebase.google.com/docs/projects/api-keys
- Android POST_NOTIFICATIONS: https://developer.android.com/develop/ui/views/notifications/notification-permission
- **Firebase JS SDK used on web:** `11.0.2` (gstatic CDN, compat builds in page + SW)
