-- Stage 14.2 — SMTP settings & mailer foundation (uses existing `settings` table; no new tables)

UPDATE `settings` SET `setting_value` = '1.11.0-stage14-smtp-2' WHERE `setting_key` = 'schema_version';
