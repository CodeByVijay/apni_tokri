# Security

## Secrets

| Asset | Storage | Exposure |
|-------|---------|----------|
| Service account private key | `firebase_projects.service_account_encrypted` via `SecretStore` | Never in API responses, logs, or Git |
| Web API key / App ID | DB columns + `push-config` JSON | Browser (expected for Firebase Web) |
| OAuth access token | Encrypted `settings` cache keys | Server only, short-lived |
| FCM device tokens | `device_push_registrations` | Admin list only; not in logs |

## Admin

- Module permission `firebase.manage`
- CSRF on all mutating admin + ajax POSTs
- `AuditLog` on create/update/activate/delete

## URLs

`PushUrlValidator` allows relative paths and absolute URLs on canonical storefront host only.

## Testing

Non-production **dry-run** prevents accidental customer notifications during development.
