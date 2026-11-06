-- Veggie Tokri schema 1.8.0-d2 — Delivery D2: slot reservations & provider slugs (depends on 1.8.0)
-- Rollback: database/migrations/rollback/1.8.0_d2_rollback.sql

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_slots' AND COLUMN_NAME = 'allowed_provider_slugs_json');
SET @sql = IF(@col = 0,
  'ALTER TABLE `delivery_slots`
     ADD COLUMN `allowed_provider_slugs_json` JSON NULL AFTER `max_distance_km`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `delivery_slots`
SET `allowed_provider_slugs_json` = JSON_ARRAY('manual')
WHERE `allowed_provider_slugs_json` IS NULL;

CREATE TABLE IF NOT EXISTS `delivery_slot_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_slot_id` INT UNSIGNED NOT NULL,
  `delivery_date` DATE NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'held',
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dsr_slot_date_status` (`delivery_slot_id`, `delivery_date`, `status`),
  KEY `idx_dsr_order` (`order_id`),
  KEY `idx_dsr_expires` (`status`, `expires_at`),
  CONSTRAINT `fk_dsr_slot` FOREIGN KEY (`delivery_slot_id`) REFERENCES `delivery_slots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_dsr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.8.0-d2' WHERE `setting_key` = 'schema_version';
