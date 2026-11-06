-- Veggie Tokri schema 1.1.0 — product attributes & related products (Stage 6)
-- mysql -u USER -p apni_tokri < database/migrations/1.1.0_catalogue_attributes.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `product_attributes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `attr_key` VARCHAR(128) NOT NULL,
  `attr_value` TEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_attributes_product` (`product_id`, `sort_order`),
  CONSTRAINT `fk_product_attributes_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_related` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `related_product_id` BIGINT UNSIGNED NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`, `related_product_id`),
  KEY `idx_product_related_related` (`related_product_id`),
  CONSTRAINT `fk_product_related_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_related_related` FOREIGN KEY (`related_product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.1.0' WHERE `setting_key` = 'schema_version';
