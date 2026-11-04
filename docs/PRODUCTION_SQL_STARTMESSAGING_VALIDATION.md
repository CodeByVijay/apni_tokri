# Production SQL validation — StartMessaging SMS (2026-11-04)

## Schema changes

| Migration | DDL | Data |
|-----------|-----|------|
| `1.21.0_security_remediation_phase1.sql` | Yes (admin auth / support rate-limit tables) | `schema_version` only |
| `1.22.0_startmessaging_sms.sql` | **No** | `sms.startmessaging.variable_app_name=1`, `sms.startmessaging.variable_expiry=0` |

StartMessaging provider behavior (`X-API-Key`, `POST /otp/send`, optional Sender ID) is implemented in PHP; no new columns or tables for SMS.

**Target `Install::SCHEMA_VERSION`:** `1.22.0-startmessaging-sms`

## Disposable database validation

| Step | Command | Result |
|------|---------|--------|
| Regenerate installer | `php scripts/build-apni-tokri-production-sql.php` | PASS — temp DB `apni_tokri_prod_build_*`, dropped after dump |
| Consolidated vs incremental | `php scripts/validate-apni-tokri-production-sql.php` | PASS — 92 tables, matching column hash, indexes, FKs |
| Fresh install audit | `php scripts/run-phase4-fresh-install-audit.php` | PASS (`pass: true`; invoice encryption check expected fail on local) |
| Production readiness | `php scripts/test-production-readiness.php` | PASS |
| StartMessaging unit tests | `php scripts/test-startmessaging-sms.php` | PASS (22 checks, mocked HTTP) |

Additional checks in `validate-apni-tokri-production-sql.php`:

- StartMessaging settings present on both import paths
- No plaintext `sms.api_key` in consolidated seed
- `SmsSettingsService::validateSaveFields()` allows empty Sender ID for StartMessaging

## Fresh install seed (SMS)

- `sms.provider` = `simulate` (safe default)
- `sms.enabled` = `0`
- `sms.sender_id` = empty (not required for StartMessaging)
- `sms.startmessaging.variable_*` seeded by 1.22.0 migration
- **No** API keys, webhook secrets, or live template IDs in SQL

## Upgrade path

Existing production databases must apply migrations **1.21.0** then **1.22.0** — do not replace with consolidated SQL on live data.
