-- Veggie Tokri schema 1.6.5 — Stage 11 production hardening

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_subscriptions' AND COLUMN_NAME = 'preferred_delivery_slot_slug'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `customer_subscriptions` ADD COLUMN `preferred_delivery_slot_slug` VARCHAR(64) NULL AFTER `calendar_end_date`',
  'SELECT 1'
);
PREPARE stmt_cs FROM @ddl;
EXECUTE stmt_cs;
DEALLOCATE PREPARE stmt_cs;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_delivery_days' AND COLUMN_NAME = 'preferred_delivery_slot_slug'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `subscription_delivery_days`
     ADD COLUMN `preferred_delivery_slot_slug` VARCHAR(64) NULL AFTER `generated_order_id`,
     ADD COLUMN `preferred_delivery_slot_id` INT UNSIGNED NULL AFTER `preferred_delivery_slot_slug`,
     ADD COLUMN `slot_snapshot_json` JSON NULL AFTER `preferred_delivery_slot_id`',
  'SELECT 1'
);
PREPARE stmt_sdd FROM @ddl;
EXECUTE stmt_sdd;
DEALLOCATE PREPARE stmt_sdd;

CREATE TABLE IF NOT EXISTS `subscription_delivery_entitlement_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_entitlement_day` (`subscription_delivery_day_id`),
  UNIQUE KEY `uq_sub_entitlement_delivery` (`delivery_id`),
  UNIQUE KEY `uq_sub_entitlement_idempotency` (`idempotency_key`),
  CONSTRAINT `fk_sdee_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_stock_restock_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `reason` VARCHAR(191) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_restock_order` (`order_id`),
  UNIQUE KEY `uq_sub_restock_idempotency` (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_substitution_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `substitution_id` BIGINT UNSIGNED NOT NULL,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `gateway` VARCHAR(32) NOT NULL DEFAULT 'simulate',
  `amount` DECIMAL(12,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `status` VARCHAR(32) NOT NULL DEFAULT 'created',
  `idempotency_key` VARCHAR(64) NOT NULL,
  `gateway_order_id` VARCHAR(64) NULL,
  `gateway_payment_id` VARCHAR(64) NULL,
  `gateway_event_id` VARCHAR(64) NULL,
  `metadata_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_subst_pay_idempotency` (`idempotency_key`),
  UNIQUE KEY `uq_sub_subst_pay_substitution` (`substitution_id`),
  CONSTRAINT `fk_sspay_substitution` FOREIGN KEY (`substitution_id`) REFERENCES `subscription_basket_substitutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sspay_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_day_credits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `credit_type` VARCHAR(32) NOT NULL COMMENT 'delivery_entitlement|refund_pending|refund_completed',
  `amount_inr` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'applied',
  `idempotency_key` VARCHAR(64) NOT NULL,
  `parent_subscription_payment_id` BIGINT UNSIGNED NULL,
  `gateway_refund_id` VARCHAR(64) NULL,
  `gateway_refund_status` VARCHAR(32) NULL,
  `detail_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_day_credit_day` (`subscription_delivery_day_id`),
  UNIQUE KEY `uq_sub_day_credit_idempotency` (`idempotency_key`),
  CONSTRAINT `fk_sdc_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sdc_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_basket_substitutions' AND COLUMN_NAME = 'substitution_payment_id'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `subscription_basket_substitutions`
     ADD COLUMN `substitution_payment_id` BIGINT UNSIGNED NULL AFTER `admin_note`,
     ADD COLUMN `rejection_resolution` VARCHAR(32) NULL AFTER `substitution_payment_id`',
  'SELECT 1'
);
PREPARE stmt_sbs FROM @ddl;
EXECUTE stmt_sbs;
DEALLOCATE PREPARE stmt_sbs;

UPDATE `settings` SET `setting_value` = '1.6.5' WHERE `setting_key` = 'schema_version';
