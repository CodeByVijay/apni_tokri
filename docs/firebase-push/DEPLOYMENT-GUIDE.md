# Deployment guide (staging/production checklist)

**Do not run on production without backup and maintenance window.**

## 1. Database

1. Backup DB.
2. Apply `database/migrations/1.39.0_firebase_push_notifications.sql`.
3. Verify `settings.schema_version` = `1.39.0-firebase-push`.

Rollback: `database/migrations/rollback/1.39.0_firebase_push_notifications_rollback.sql` (drops push tables).

## 2. Files

Deploy all new/modified PHP, JS, `firebase-messaging-sw.js`, and admin templates.

Ensure master encryption key file exists (same as invoices).

## 3. Firebase Console

Create project, Web + Android apps, generate VAPID key, download service account JSON.

## 4. Admin configuration

1. Admin → **Firebase push** → Add project → upload JSON → fill Web/Android fields.
2. Test → Activate.
3. On production set `push.dry_run` = `0` and `push.enabled` = `1` only when ready.

## 5. Android

Rebuild APK with matching `google-services.json`, publish through normal Play process (out of scope here).

## 6. Verification

- Register token from my-account (staging).
- Admin test device send.
- Place test order; confirm log row in **Push logs** (dry-run or live).
