-- Rollback Stage 12 Phase C.3 webhook lease columns (manual).
-- Reverts schema label to 1.8.0-stage12-c2-1.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_webhook_events' AND COLUMN_NAME = 'worker_token');
SET @sql = IF(@col > 0,
  'ALTER TABLE `payment_webhook_events` DROP COLUMN `worker_token`, DROP COLUMN `processing_started_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.8.0-stage12-c2-1' WHERE `setting_key` = 'schema_version';
