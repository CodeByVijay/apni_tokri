-- Rollback for 1.13.0_stage14_notifications_phase4.sql (PARTIAL — read before running)
--
-- LIMITATIONS:
-- - Drops notification_templates and reverts schema_version only.
-- - Does NOT remove columns, indexes, or unique keys added to notification_queue.
-- - Does NOT delete notification_queue rows (data preserved).
-- - To fully reverse queue schema changes requires a manual DBA plan and downtime.
--
-- Recommended: restore from pre-migration backup instead of relying on this rollback alone.

UPDATE `settings` SET `setting_value` = '1.12.0-stage14-sms-3' WHERE `setting_key` = 'schema_version';

DROP TABLE IF EXISTS `notification_templates`;
