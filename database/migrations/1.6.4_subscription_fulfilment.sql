-- Apni Tokri schema 1.6.4 — subscription fulfilment, substitutions, reservations (Stage 11 Phase E)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_source'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `orders`
     ADD COLUMN `order_source` VARCHAR(32) NOT NULL DEFAULT ''storefront'' AFTER `order_number`,
     ADD COLUMN `subscription_delivery_day_id` BIGINT UNSIGNED NULL AFTER `order_source`,
     ADD UNIQUE KEY `uq_orders_sub_delivery_day` (`subscription_delivery_day_id`)',
  'SELECT 1'
);
PREPARE stmt_ord FROM @ddl;
EXECUTE stmt_ord;
DEALLOCATE PREPARE stmt_ord;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_delivery_days' AND COLUMN_NAME = 'fulfilment_status'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `subscription_delivery_days`
     ADD COLUMN `fulfilment_status` VARCHAR(32) NOT NULL DEFAULT ''none'' AFTER `basket_status`,
     ADD COLUMN `generated_order_id` BIGINT UNSIGNED NULL AFTER `fulfilment_status`',
  'SELECT 1'
);
PREPARE stmt_sdd FROM @ddl;
EXECUTE stmt_sdd;
DEALLOCATE PREPARE stmt_sdd;

CREATE TABLE IF NOT EXISTS `subscription_fulfilment_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `cron_run_id` VARCHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `order_id` BIGINT UNSIGNED NULL,
  `error_message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_fulfilment_day` (`subscription_delivery_day_id`),
  UNIQUE KEY `uq_sub_fulfilment_idempotency` (`idempotency_key`),
  CONSTRAINT `fk_sfe_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_stock_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(10,3) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'held',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_reservation_day_variant` (`subscription_delivery_day_id`, `variant_id`),
  KEY `idx_sub_reservation_variant` (`variant_id`, `status`),
  CONSTRAINT `fk_ssr_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssr_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_basket_substitutions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `original_variant_id` BIGINT UNSIGNED NOT NULL,
  `original_qty` DECIMAL(10,3) NOT NULL,
  `original_unit_price_inr` DECIMAL(12,2) NOT NULL,
  `proposed_variant_id` BIGINT UNSIGNED NOT NULL,
  `proposed_qty` DECIMAL(10,3) NOT NULL,
  `proposed_unit_price_inr` DECIMAL(12,2) NOT NULL,
  `price_difference_inr` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending_customer',
  `customer_decision_at` TIMESTAMP NULL DEFAULT NULL,
  `admin_user_id` INT UNSIGNED NULL,
  `admin_note` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sub_substitution_day` (`subscription_delivery_day_id`, `status`),
  CONSTRAINT `fk_sbs_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_notification_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `notification_type` VARCHAR(64) NOT NULL,
  `reference_key` VARCHAR(128) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_notification_dedupe` (`notification_type`, `reference_key`),
  CONSTRAINT `fk_snl_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.6.4' WHERE `setting_key` = 'schema_version';
