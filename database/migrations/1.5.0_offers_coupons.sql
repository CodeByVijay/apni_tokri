-- Apni Tokri schema 1.5.0 — offers & coupons (Stage 10)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE `coupons`
  ADD COLUMN `internal_name` VARCHAR(191) NULL AFTER `code`,
  ADD COLUMN `per_customer_limit` INT UNSIGNED NULL AFTER `usage_limit`,
  ADD COLUMN `first_order_only` TINYINT(1) NOT NULL DEFAULT 0 AFTER `per_customer_limit`,
  ADD COLUMN `stackable_with_offers` TINYINT(1) NOT NULL DEFAULT 0 AFTER `first_order_only`,
  ADD COLUMN `priority` INT NOT NULL DEFAULT 0 AFTER `stackable_with_offers`;

CREATE TABLE IF NOT EXISTS `customer_coupon_assignments` (
  `coupon_id` INT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`coupon_id`, `customer_id`),
  KEY `idx_cca_customer` (`customer_id`),
  CONSTRAINT `fk_cca_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cca_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_categories` (
  `coupon_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `category_id`),
  CONSTRAINT `fk_coupon_cat_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_cat_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_products` (
  `coupon_id` INT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `product_id`),
  CONSTRAINT `fk_coupon_prod_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_prod_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_excluded_products` (
  `coupon_id` INT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`coupon_id`, `product_id`),
  CONSTRAINT `fk_coupon_excl_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coupon_excl_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `offers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `scope` VARCHAR(32) NOT NULL DEFAULT 'store',
  `target_id` BIGINT UNSIGNED NULL,
  `discount_type` ENUM('fixed','percent') NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(12,2) NOT NULL,
  `max_discount` DECIMAL(12,2) NULL,
  `min_cart_value` DECIMAL(12,2) NULL,
  `starts_at` TIMESTAMP NULL,
  `ends_at` TIMESTAMP NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `stack_with_coupons` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_offers_active_dates` (`is_active`, `starts_at`, `ends_at`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `offer_customer_assignments` (
  `offer_id` INT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`offer_id`, `customer_id`),
  CONSTRAINT `fk_oca_offer` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oca_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `coupon_usages`
  ADD UNIQUE KEY `uq_coupon_usages_order` (`order_id`);

UPDATE `settings` SET `setting_value` = '1.5.0' WHERE `setting_key` = 'schema_version';
