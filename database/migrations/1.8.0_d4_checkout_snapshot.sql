-- D4: Checkout delivery quote snapshot & payment revalidation (schema version bump only).
-- Rollback: database/migrations/rollback/1.8.0_d4_checkout_snapshot_rollback.sql

UPDATE `settings` SET `setting_value` = '1.8.0-d4-checkout' WHERE `setting_key` = 'schema_version';
