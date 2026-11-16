# StartMessaging SMS integration

## Compatibility audit

| Area | Before | StartMessaging |
|------|--------|----------------|
| Auth | Generic HTTP: `Authorization: Bearer` | `X-API-Key: YOUR_API_KEY` |
| OTP send | `POST` arbitrary URL, body `{sender, mobile, message, template_id}` | `POST {base}/otp/send`, JSON `{phoneNumber, templateId, variables}` |
| Sender ID | Required for HTTP live sends | **Not used** by StartMessaging OTP API — optional in admin |
| OTP template ID | `sms.dlt.otp` → HTTP `template_id` | Same setting → StartMessaging `templateId` |
| Success contract | Configurable HTTP JSON paths | Fixed: HTTP **201** (or 200), `success: true`, `data.messageId` |
| Delivery SMS | Generic HTTP plain-text | **Not supported** on StartMessaging OTP API; use **Generic HTTP** provider for delivery notifications |
| Simulate / test mode | Unchanged | Unchanged |

Implementation: `StartMessagingSmsService` + `StartMessagingSmsGateway` (OTP-only). Login OTP in live mode calls `StartMessagingSmsService::sendOtp()` when `sms.provider` is `startmessaging`.

## Admin configuration (Settings → SMS)

1. Set **SMS operation mode** to **Live Mode**.
2. **SMS provider**: **StartMessaging (OTP API, X-API-Key)**.
3. **API base URL**: `https://api.startmessaging.com` (default if left blank on save).
4. **API key**: your StartMessaging API key (stored encrypted; sent only as `X-API-Key` header).
5. **Sender ID**: leave empty for StartMessaging (optional).
6. Under **Message templates → otp**, set **DLT / template ID** to your StartMessaging dashboard **template ID** (required for live OTP).
7. Enable template variables to match your template:
   - **appName** — maps to app name (e.g. Veggie Tokri).
   - **expiry** — OTP validity in minutes (from OTP TTL).
8. **HTTP provider response contract** is hidden for StartMessaging (not used).

Delivery order SMS still requires **Generic HTTP** provider with sender ID and HTTP response contract configured.

## Database / fresh install

- Migration: `database/migrations/1.22.0_startmessaging_sms.sql` (settings seeds only).
- Consolidated installer: regenerate with `php scripts/build-apni-tokri-production-sql.php` (includes 1.21.0 + 1.22.0).
- Validation report: [PRODUCTION_SQL_STARTMESSAGING_VALIDATION.md](./PRODUCTION_SQL_STARTMESSAGING_VALIDATION.md).

## Tests

```bash
php scripts/test-startmessaging-sms.php
```

Uses `StartMessagingSmsService::$testRequestHandler` only — no outbound SMS.

## Security

API keys are not logged, rendered in HTML/JS, or returned in customer-facing errors. Provider error bodies are truncated in diagnostics without echoing secrets.
