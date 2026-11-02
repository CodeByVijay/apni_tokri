SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_completion_exceptions' AND COLUMN_NAME = 'failure_category');
SET @sql = IF(@col > 0,
  'ALTER TABLE `payment_completion_exceptions` DROP COLUMN `failure_category`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.8.0-d4-checkout' WHERE `setting_key` = 'schema_version';
