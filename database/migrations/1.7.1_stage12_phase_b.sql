-- Veggie Tokri schema 1.7.1 — Stage 12 Phase B (refund webhooks, support attachments, subscription refund links)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_payments' AND COLUMN_NAME = 'amount_refunded_inr');
SET @sql = IF(@col = 0,
  'ALTER TABLE `subscription_payments` ADD COLUMN `amount_refunded_inr` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `amount`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND COLUMN_NAME = 'subscription_payment_id');
SET @sql = IF(@col = 0,
  'ALTER TABLE `payment_refunds`
     ADD COLUMN `subscription_payment_id` BIGINT UNSIGNED NULL AFTER `payment_id`,
     ADD COLUMN `subscription_day_credit_id` BIGINT UNSIGNED NULL AFTER `subscription_payment_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND INDEX_NAME = 'uq_payment_refunds_gateway_refund');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `payment_refunds` ADD UNIQUE KEY `uq_payment_refunds_gateway_refund` (`gateway`, `gateway_refund_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND COLUMN_NAME = 'payment_id' AND IS_NULLABLE = 'YES');
SET @sql = IF(@col = 0,
  'ALTER TABLE `payment_refunds` MODIFY `payment_id` BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `support_message_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_attach_message` (`message_id`),
  CONSTRAINT `fk_support_attach_message` FOREIGN KEY (`message_id`) REFERENCES `support_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.7.1' WHERE `setting_key` = 'schema_version';
