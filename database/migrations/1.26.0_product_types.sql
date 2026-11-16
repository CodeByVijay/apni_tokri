-- Admin-managed product types (products.product_type stores the type code). Forward-only, idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `product_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(191) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_types_code` (`code`),
  KEY `idx_product_types_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_types` (`code`, `name`, `sort_order`, `is_active`) VALUES
  ('fresh', 'Fresh produce', 10, 1),
  ('cut', 'Cut / prepared', 20, 1),
  ('packaged', 'Packaged food', 30, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `sort_order` = VALUES(`sort_order`);

UPDATE `settings` SET `setting_value` = '1.26.0-product-types' WHERE `setting_key` = 'schema_version';
