-- Rollback for 1.15.0_stage14_reports_phase6.sql
-- Drops financial timestamp columns and related indexes. Does not modify captured/refund row data beyond column removal.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND INDEX_NAME = 'idx_payment_refunds_completed_at'
);
SET @ddl := IF(
  @idx > 0,
  'ALTER TABLE `payment_refunds` DROP INDEX `idx_payment_refunds_completed_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_payments' AND INDEX_NAME = 'idx_spay_captured_at'
);
SET @ddl := IF(
  @idx > 0,
  'ALTER TABLE `subscription_payments` DROP INDEX `idx_spay_captured_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_captured_at'
);
SET @ddl := IF(
  @idx > 0,
  'ALTER TABLE `payments` DROP INDEX `idx_payments_captured_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND COLUMN_NAME = 'completed_at'
);
SET @ddl := IF(
  @col > 0,
  'ALTER TABLE `payment_refunds` DROP COLUMN `completed_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_payments' AND COLUMN_NAME = 'captured_at'
);
SET @ddl := IF(
  @col > 0,
  'ALTER TABLE `subscription_payments` DROP COLUMN `captured_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'captured_at'
);
SET @ddl := IF(
  @col > 0,
  'ALTER TABLE `payments` DROP COLUMN `captured_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.14.0-stage14-reports-5' WHERE `setting_key` = 'schema_version';
