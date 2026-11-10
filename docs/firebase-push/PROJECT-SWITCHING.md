# Project switching

## Admin flow

1. Add/configure Project B; upload service account; **Test**.
2. **Activate** — atomically clears other `is_active_global`, sets B active, invalidates OAuth cache.
3. Project A remains in DB (not deleted).

## Runtime effects

| Layer | Behaviour |
|-------|-----------|
| PHP send | Uses active project credentials immediately |
| `ajax/push-config.php` | Returns B's Web config |
| Web clients | Must re-register when `configVersion` changes |
| Android | Needs updated `google-services.json` + new APK if Firebase Android app changes |
| Old tokens | Stay linked to old `firebase_project_id`; inactive for sends until re-registered under B |

## Rollback

Activate Project A again after successful credential test. No automatic token migration.
