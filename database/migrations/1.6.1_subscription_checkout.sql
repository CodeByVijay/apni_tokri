-- Apni Tokri schema 1.6.1 — subscription checkout, payments, calendar days (Stage 11 Phase B)
-- Idempotent: safe to re-run on partially applied databases.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS `subscription_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `gateway` VARCHAR(32) NOT NULL,
  `gateway_order_id` VARCHAR(191) NULL,
  `gateway_payment_id` VARCHAR(191) NULL,
  `gateway_event_id` VARCHAR(191) NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `status` VARCHAR(32) NOT NULL DEFAULT 'created',
  `idempotency_key` VARCHAR(64) NOT NULL,
  `metadata_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_payments_idempotency` (`idempotency_key`),
  KEY `idx_subscription_payments_sub` (`customer_subscription_id`),
  KEY `idx_subscription_payments_gateway` (`gateway`, `gateway_order_id`),
  CONSTRAINT `fk_spay_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_delivery_days` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `delivery_date` DATE NOT NULL,
  `day_index` INT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'scheduled',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_delivery_day` (`customer_subscription_id`, `delivery_date`),
  KEY `idx_sub_delivery_days_sub` (`customer_subscription_id`, `day_index`),
  CONSTRAINT `fk_sdd_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_subscriptions' AND COLUMN_NAME = 'activated_at'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `customer_subscriptions` ADD COLUMN `activated_at` TIMESTAMP NULL DEFAULT NULL AFTER `deliveries_remaining`',
  'SELECT 1'
);
PREPARE stmt_161 FROM @ddl;
EXECUTE stmt_161;
DEALLOCATE PREPARE stmt_161;

UPDATE `settings` SET `setting_value` = '1.6.1' WHERE `setting_key` = 'schema_version';
