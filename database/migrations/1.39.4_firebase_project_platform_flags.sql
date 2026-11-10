-- Platform intent for Firebase projects (web / android / both) — one global active project unchanged.
-- Safe to re-run: ADD COLUMN only when missing.

SET @col_web := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'firebase_projects'
    AND COLUMN_NAME = 'platform_web'
);
SET @ddl_web := IF(
  @col_web = 0,
  'ALTER TABLE `firebase_projects` ADD COLUMN `platform_web` TINYINT(1) NOT NULL DEFAULT 1 AFTER `platform_notes`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_web;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_and := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'firebase_projects'
    AND COLUMN_NAME = 'platform_android'
);
SET @ddl_and := IF(
  @col_and = 0,
  'ALTER TABLE `firebase_projects` ADD COLUMN `platform_android` TINYINT(1) NOT NULL DEFAULT 1 AFTER `platform_web`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_and;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `firebase_projects` SET
  `platform_web` = IF(
    TRIM(COALESCE(`web_app_id`, '')) <> ''
    OR TRIM(COALESCE(`web_api_key`, '')) <> ''
    OR TRIM(COALESCE(`vapid_public_key`, '')) <> '',
    1, 0
  ),
  `platform_android` = IF(
    TRIM(COALESCE(`android_app_id`, '')) <> ''
    OR TRIM(COALESCE(`android_package_name`, '')) <> '',
    1, 0
  )
WHERE `platform_web` = 1 AND `platform_android` = 1;

UPDATE `firebase_projects` SET `platform_web` = 1, `platform_android` = 1
WHERE `platform_web` = 0 AND `platform_android` = 0;

UPDATE `settings` SET `setting_value` = '1.39.4-firebase-platform-flags' WHERE `setting_key` = 'schema_version';
