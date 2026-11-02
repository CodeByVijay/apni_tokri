-- Apni Tokri — Stage 12 Phase C.3 Step 4 (Cashfree webhook processing lease)
-- Safe to re-run (conditional ADD COLUMN).
-- Depends on: payment_webhook_events (1.4.0+). Apply on installs at 1.8.0-stage12-c2-1 or later.
-- Rollback: database/migrations/rollback/1.8.0_stage12_c3_webhook_lease_rollback.sql

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_webhook_events' AND COLUMN_NAME = 'processing_started_at');
SET @sql = IF(@col = 0,
  'ALTER TABLE `payment_webhook_events`
     ADD COLUMN `processing_started_at` TIMESTAMP NULL DEFAULT NULL AFTER `process_status`,
     ADD COLUMN `worker_token` VARCHAR(64) NULL DEFAULT NULL AFTER `processing_started_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_webhook_events' AND COLUMN_NAME = 'worker_token');
SET @sql = IF(@col = 0,
  'ALTER TABLE `payment_webhook_events` ADD COLUMN `worker_token` VARCHAR(64) NULL DEFAULT NULL AFTER `processing_started_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.8.0-stage12-c3-1' WHERE `setting_key` = 'schema_version';
