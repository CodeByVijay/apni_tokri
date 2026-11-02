-- Rollback Stage 12 Phase C.2.1 (drops moderation audit table; audit rows are lost).

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `review_moderation_history`;

UPDATE `settings` SET `setting_value` = '1.8.0-d5-subscription' WHERE `setting_key` = 'schema_version';
