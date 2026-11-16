# Admin setup

## Permissions

- Slug: `firebase.manage` (module `firebase`)
- Super Admin role seeded in migration 1.39.0

## Screens

| Path | Purpose |
|------|---------|
| `/admin/firebase/index.php` | List projects, activate/deactivate/test/delete |
| `/admin/firebase/edit.php` | Web + Android fields, upload service account JSON |
| `/admin/push/templates.php` | Edit push templates |
| `/admin/push/logs.php` | Delivery log (no raw tokens) |
| `/admin/push/devices.php` | Registrations |
| `/admin/push/send.php` | Test token or customer send |

## Credential upload

1. Firebase Console → Project settings → Service accounts → Generate new private key.
2. Upload JSON on edit form (never commit to Git).
3. Use **Test** on list page — stores `last_test_status` only after OAuth succeeds.

## Settings keys (optional)

| Key | Default (non-prod) | Meaning |
|-----|---------------------|---------|
| `push.enabled` | `1` | Master enable |
| `push.dry_run` | `1` | Log only, no FCM HTTP call |

Set via SQL `settings` table or future settings UI.
