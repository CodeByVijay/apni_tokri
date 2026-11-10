-- Distinguish Web FID vs Android FCM registration token (FCM HTTP v1 fid vs token fields).
-- Safe to re-run: ADD COLUMN only when missing.

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'device_push_registrations'
    AND COLUMN_NAME = 'target_type'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `device_push_registrations`
     ADD COLUMN `target_type` ENUM(''fid'',''fcm_token'') NOT NULL DEFAULT ''fcm_token'' AFTER `platform`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.39.1-push-target-type' WHERE `setting_key` = 'schema_version';
