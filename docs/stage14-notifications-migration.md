# Stage 14.4 — Notification migration (1.13.0)

## Before you apply

1. **Backup** the database (full dump) and note current `settings.schema_version`.
2. Apply prior Stage 14 migrations in order:
   - `1.11.0_stage14_smtp_phase2.sql`
   - `1.12.0_stage14_sms_phase3.sql`
3. Apply **`1.13.0_stage14_notifications_phase4.sql` once** on staging, then production.

## What the migration does

- **Extends** `notification_queue` (new columns, `in_app` channel, idempotency unique key). Existing rows are kept; new columns get defaults.
- **Creates** `notification_templates` and seeds default templates (idempotent `ON DUPLICATE KEY UPDATE`).
- **Adds** admin permission `notifications.manage`.

## Re-running the migration

- `ALTER TABLE ... ADD COLUMN` will **fail** if columns already exist — do not re-run the full file on an already-migrated database.
- Template and permission inserts are idempotent; the `ALTER` blocks are not.

## Rollback

File: `database/migrations/rollback/1.13.0_stage14_notifications_phase4_rollback.sql`

- Reverts `schema_version` and drops `notification_templates`.
- **Does not** undo `notification_queue` structural changes or delete queue rows.
- For production rollback, prefer **restoring the pre-migration backup**.

## After migration

1. Confirm `settings.schema_version` = `1.13.0-stage14-notifications-4`.
2. Align `includes/config.local.php` `expected_schema_version` with your deploy process.
3. Configure cPanel cron per `docs/cpanel-notification-cron.md`.
