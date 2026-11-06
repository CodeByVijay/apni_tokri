-- Veggie Tokri schema 1.6.2 — menu planner, suggested/custom baskets (Stage 11 Phase C)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

-- Extend delivery days for basket state and cutoff
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_delivery_days' AND COLUMN_NAME = 'selection_cutoff_at'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE `subscription_delivery_days`
     ADD COLUMN `selection_cutoff_at` DATETIME NULL AFTER `status`,
     ADD COLUMN `basket_status` VARCHAR(32) NOT NULL DEFAULT ''none'' AFTER `selection_cutoff_at`,
     ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
  'SELECT 1'
);
PREPARE stmt_sdd FROM @ddl;
EXECUTE stmt_sdd;
DEALLOCATE PREPARE stmt_sdd;

CREATE TABLE IF NOT EXISTS `subscription_menu_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `planner_type` VARCHAR(16) NOT NULL COMMENT 'date|weekly|day_index',
  `template_date` DATE NULL,
  `weekday` TINYINT UNSIGNED NULL COMMENT '1=Mon..7=Sun',
  `day_index` INT UNSIGNED NULL COMMENT '1-based plan delivery index',
  `notes` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_menu_templates_lookup` (`planner_type`, `template_date`, `weekday`, `day_index`),
  KEY `idx_menu_templates_active` (`is_active`, `planner_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_menu_template_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_template_id` INT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `default_qty` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `unit_label` VARCHAR(32) NOT NULL DEFAULT 'kg',
  `unit_price_inr` DECIMAL(12,2) NOT NULL,
  `include_in_rotation` TINYINT(1) NOT NULL DEFAULT 1,
  `max_qty` DECIMAL(10,3) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_item_variant` (`menu_template_id`, `variant_id`),
  KEY `idx_menu_items_template` (`menu_template_id`, `sort_order`),
  CONSTRAINT `fk_smti_template` FOREIGN KEY (`menu_template_id`) REFERENCES `subscription_menu_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_smti_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_customer_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `preference` VARCHAR(16) NOT NULL DEFAULT 'prefer' COMMENT 'prefer|avoid',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_pref_customer_variant` (`customer_id`, `variant_id`),
  CONSTRAINT `fk_scp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_scp_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_delivery_baskets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `source_type` VARCHAR(16) NOT NULL DEFAULT 'suggested' COMMENT 'suggested|custom',
  `status` VARCHAR(16) NOT NULL DEFAULT 'draft' COMMENT 'suggested|draft|confirmed',
  `allowance_inr` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total_inr` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_basket_day` (`subscription_delivery_day_id`),
  CONSTRAINT `fk_sdb_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_delivery_basket_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `basket_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `product_name` VARCHAR(191) NOT NULL,
  `variant_name` VARCHAR(191) NOT NULL,
  `unit_label` VARCHAR(32) NOT NULL,
  `qty` DECIMAL(10,3) NOT NULL,
  `unit_price_inr` DECIMAL(12,2) NOT NULL,
  `line_total_inr` DECIMAL(12,2) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_basket_items_basket` (`basket_id`, `sort_order`),
  CONSTRAINT `fk_sdbi_basket` FOREIGN KEY (`basket_id`) REFERENCES `subscription_delivery_baskets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.6.2' WHERE `setting_key` = 'schema_version';
