-- Stage 14.6 Phase B — Financial report timestamps (capture / refund completion)
-- Idempotent: safe to re-run on partially applied databases.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'captured_at'
);
SET @ddl := IF(
  @col = 0,
  'ALTER TABLE `payments` ADD COLUMN `captured_at` DATETIME NULL DEFAULT NULL AFTER `status`',
  'SELECT 1'
);
PREPARE stmt_p FROM @ddl;
EXECUTE stmt_p;
DEALLOCATE PREPARE stmt_p;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_payments' AND COLUMN_NAME = 'captured_at'
);
SET @ddl := IF(
  @col = 0,
  'ALTER TABLE `subscription_payments` ADD COLUMN `captured_at` DATETIME NULL DEFAULT NULL AFTER `status`',
  'SELECT 1'
);
PREPARE stmt_sp FROM @ddl;
EXECUTE stmt_sp;
DEALLOCATE PREPARE stmt_sp;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND COLUMN_NAME = 'completed_at'
);
SET @ddl := IF(
  @col = 0,
  'ALTER TABLE `payment_refunds` ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL AFTER `gateway_status`',
  'SELECT 1'
);
PREPARE stmt_pr FROM @ddl;
EXECUTE stmt_pr;
DEALLOCATE PREPARE stmt_pr;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_captured_at'
);
SET @ddl := IF(
  @idx = 0,
  'ALTER TABLE `payments` ADD KEY `idx_payments_captured_at` (`captured_at`)',
  'SELECT 1'
);
PREPARE stmt_ip FROM @ddl;
EXECUTE stmt_ip;
DEALLOCATE PREPARE stmt_ip;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_payments' AND INDEX_NAME = 'idx_spay_captured_at'
);
SET @ddl := IF(
  @idx = 0,
  'ALTER TABLE `subscription_payments` ADD KEY `idx_spay_captured_at` (`captured_at`)',
  'SELECT 1'
);
PREPARE stmt_isp FROM @ddl;
EXECUTE stmt_isp;
DEALLOCATE PREPARE stmt_isp;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND INDEX_NAME = 'idx_payment_refunds_completed_at'
);
SET @ddl := IF(
  @idx = 0,
  'ALTER TABLE `payment_refunds` ADD KEY `idx_payment_refunds_completed_at` (`completed_at`)',
  'SELECT 1'
);
PREPARE stmt_ipr FROM @ddl;
EXECUTE stmt_ipr;
DEALLOCATE PREPARE stmt_ipr;

UPDATE `settings` SET `setting_value` = '1.15.0-stage14-reports-6' WHERE `setting_key` = 'schema_version';
