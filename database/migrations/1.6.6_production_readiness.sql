-- Veggie Tokri schema 1.6.6 — refund timestamps, recovery audit

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_day_credits' AND COLUMN_NAME = 'gateway_refund_requested_at'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `subscription_day_credits`
     ADD COLUMN `gateway_refund_requested_at` TIMESTAMP NULL DEFAULT NULL AFTER `gateway_refund_status`,
     ADD COLUMN `gateway_refund_completed_at` TIMESTAMP NULL DEFAULT NULL AFTER `gateway_refund_requested_at`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `subscription_recovery_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `operation` VARCHAR(64) NOT NULL,
  `reference_key` VARCHAR(128) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `detail_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_recovery_log_op` (`operation`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.6.6' WHERE `setting_key` = 'schema_version';
