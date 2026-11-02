-- D4 hardening: payment completion failure classification (depends on 1.8.0-d4-checkout)
-- Rollback: database/migrations/rollback/1.8.0_d4_hardening_rollback.sql

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_completion_exceptions' AND COLUMN_NAME = 'failure_category');
SET @sql = IF(@col = 0,
  'ALTER TABLE `payment_completion_exceptions` ADD COLUMN `failure_category` VARCHAR(32) NULL AFTER `failure_reason`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.8.0-d4-hardening' WHERE `setting_key` = 'schema_version';
