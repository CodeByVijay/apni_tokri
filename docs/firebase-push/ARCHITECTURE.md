# Architecture

## Components

```
Admin UI ──► FirebaseAdminService ──► firebase_projects (encrypted SA JSON)
                    │
Storefront JS ──► ajax/push-config.php ──► FirebaseProjectManager::publicWebConfig()
       │                    │
       └── push-register.php ──► PushTokenService ──► device_push_registrations

Order/payment events ──► NotificationDispatcher ──► PushEventBridge
                              └──► PushNotificationService ──► FirebaseOAuth + FirebaseFcmClient (HTTP v1)
```

## Multi-project model

- Many rows in `firebase_projects`; exactly one `is_active_global = 1` at a time.
- Future split (web vs android project) can add `platform_scope` column without breaking current schema.

## Send path

1. Idempotency key checked in `push_notification_logs`.
2. Template render from `push_notification_templates`.
3. OAuth access token (cached in encrypted `settings` keys `firebase.oauth.cache.{id}`).
4. FCM HTTP v1 `messages:send` per device token.
5. Invalid token → `device_push_registrations.status = invalid`.

## Non-production safety

- `push.dry_run` setting defaults to dry-run off production (`PushNotificationService::dryRunOnly()`).
- `push.enabled` gates all sends.
