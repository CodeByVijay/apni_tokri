-- Menu planner readiness: persist skipped menu lines on suggested baskets (nullable, backward-compatible).

SET NAMES utf8mb4;
SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_delivery_baskets' AND COLUMN_NAME = 'generation_skipped_json');
SET @sql = IF(@col = 0,
  'ALTER TABLE `subscription_delivery_baskets`
     ADD COLUMN `generation_skipped_json` TEXT NULL DEFAULT NULL COMMENT \'JSON array of skipped menu lines at generation time\' AFTER `total_inr`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.27.0-subscription-menu-readiness' WHERE `setting_key` = 'schema_version';
