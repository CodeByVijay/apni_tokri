-- Veggie Tokri schema 1.3.0 — delivery engine (Stage 8)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE `products`
  ADD COLUMN `grants_free_delivery` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_new_arrival`;

ALTER TABLE `orders`
  ADD COLUMN `delivery_zone_id` INT UNSIGNED NULL AFTER `delivery_slot`,
  ADD COLUMN `delivery_slot_id` INT UNSIGNED NULL AFTER `delivery_zone_id`,
  ADD COLUMN `delivery_date` DATE NULL AFTER `delivery_slot_id`,
  ADD COLUMN `delivery_eta` VARCHAR(255) NULL AFTER `delivery_date`,
  ADD COLUMN `delivery_snapshot_json` JSON NULL AFTER `delivery_eta`;

CREATE TABLE IF NOT EXISTS `delivery_store` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL DEFAULT 'Veggie Tokri — Indore',
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `max_radius_km` DECIMAL(8,2) NOT NULL DEFAULT 12.00,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
  `slots_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `prep_minutes` INT UNSIGNED NOT NULL DEFAULT 45,
  `business_open` TIME NOT NULL DEFAULT '08:00:00',
  `business_close` TIME NOT NULL DEFAULT '20:00:00',
  `mixed_cart_free_rule` VARCHAR(64) NOT NULL DEFAULT 'eligible_subtotal',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_zones` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `match_type` VARCHAR(32) NOT NULL DEFAULT 'pincode_prefix',
  `match_value` VARCHAR(64) NOT NULL,
  `flat_fee` DECIMAL(12,2) NOT NULL DEFAULT 40.00,
  `free_above` DECIMAL(12,2) NOT NULL DEFAULT 499.00,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_zones_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(32) NOT NULL,
  `label` VARCHAR(128) NOT NULL,
  `window_label` VARCHAR(128) NOT NULL,
  `delivery_day` VARCHAR(16) NOT NULL DEFAULT 'same_day',
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `cutoff_time` TIME NOT NULL,
  `capacity` INT UNSIGNED NOT NULL DEFAULT 25,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_slots_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
  `driver_name` VARCHAR(128) NULL,
  `notes` TEXT NULL,
  `assigned_at` TIMESTAMP NULL DEFAULT NULL,
  `delivered_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deliveries_order` (`order_id`),
  KEY `idx_deliveries_status` (`status`),
  CONSTRAINT `fk_deliveries_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `note` VARCHAR(255) NULL,
  `admin_user_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_delivery_hist` (`delivery_id`, `created_at`),
  CONSTRAINT `fk_delivery_hist_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `delivery_store` (`id`, `name`, `latitude`, `longitude`, `max_radius_km`, `slots_enabled`)
VALUES (1, 'Veggie Tokri — Vijay Nagar, Indore', 22.7196000, 75.8577000, 12.00, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `delivery_zones` (`name`, `match_type`, `match_value`, `flat_fee`, `free_above`, `sort_order`) VALUES
  ('Indore Central (4520xx)', 'pincode_prefix', '4520', 40.00, 499.00, 1),
  ('Indore West (4530xx)', 'pincode_prefix', '4530', 40.00, 499.00, 2)
ON DUPLICATE KEY UPDATE `is_active` = 1;

INSERT INTO `delivery_slots` (`slug`, `label`, `window_label`, `delivery_day`, `start_time`, `end_time`, `cutoff_time`, `capacity`, `sort_order`) VALUES
  ('morning', 'Today', '9 AM – 12 PM', 'same_day', '09:00:00', '12:00:00', '08:30:00', 25, 1),
  ('afternoon', 'Today', '3 PM – 6 PM', 'same_day', '15:00:00', '18:00:00', '14:30:00', 25, 2),
  ('tomorrow', 'Tomorrow', 'Morning window', 'next_day', '09:00:00', '12:00:00', '20:00:00', 40, 3)
ON DUPLICATE KEY UPDATE `is_active` = 1;

UPDATE `settings` SET `setting_value` = '1.3.0' WHERE `setting_key` = 'schema_version';
