-- Apni Tokri schema 1.8.0 — Delivery enhancement: zones, slot columns (slot UI in D2), variant weight
-- Depends on: 1.7.1
-- Rollback: see database/migrations/rollback/1.8.0_rollback.sql (manual; restore setting delivery.zone_resolution_mode)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

-- delivery_store policies
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_store' AND COLUMN_NAME = 'default_distance_mode');
SET @sql = IF(@col = 0,
  'ALTER TABLE `delivery_store`
     ADD COLUMN `default_distance_mode` VARCHAR(32) NOT NULL DEFAULT ''haversine'' AFTER `mixed_cart_free_rule`,
     ADD COLUMN `road_distance_provider` VARCHAR(32) NOT NULL DEFAULT ''none'' AFTER `default_distance_mode`,
     ADD COLUMN `road_distance_config_json` JSON NULL AFTER `road_distance_provider`,
     ADD COLUMN `overlap_policy` VARCHAR(32) NOT NULL DEFAULT ''reject'' AFTER `road_distance_config_json`,
     ADD COLUMN `fallback_radius_zone_id` INT UNSIGNED NULL AFTER `overlap_policy`,
     ADD COLUMN `require_coordinates_for_checkout` TINYINT(1) NOT NULL DEFAULT 0 AFTER `fallback_radius_zone_id`,
     ADD COLUMN `manual_delivery_when_provider_unavailable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `require_coordinates_for_checkout`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- delivery_zones distance / hybrid
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_zones' AND COLUMN_NAME = 'zone_kind');
SET @sql = IF(@col = 0,
  'ALTER TABLE `delivery_zones`
     ADD COLUMN `zone_kind` VARCHAR(32) NOT NULL DEFAULT ''pincode'' AFTER `match_value`,
     ADD COLUMN `min_distance_km` DECIMAL(8,2) NULL AFTER `zone_kind`,
     ADD COLUMN `max_distance_km` DECIMAL(8,2) NULL AFTER `min_distance_km`,
     ADD COLUMN `max_order_weight_kg` DECIMAL(8,3) NULL AFTER `free_above`,
     ADD COLUMN `eta_display` VARCHAR(128) NULL AFTER `max_order_weight_kg`,
     ADD COLUMN `pincode_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `eta_display`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `delivery_zones`
SET `zone_kind` = 'pincode'
WHERE `zone_kind` = 'pincode' AND `match_type` = 'pincode_prefix';

-- delivery_slots (schema for D2; defaults preserve current behaviour)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_slots' AND COLUMN_NAME = 'weekday_mask');
SET @sql = IF(@col = 0,
  'ALTER TABLE `delivery_slots`
     ADD COLUMN `weekday_mask` TINYINT UNSIGNED NOT NULL DEFAULT 127 AFTER `capacity`,
     ADD COLUMN `prep_buffer_minutes` INT UNSIGNED NULL AFTER `weekday_mask`,
     ADD COLUMN `booking_cutoff_minutes` INT UNSIGNED NULL AFTER `prep_buffer_minutes`,
     ADD COLUMN `max_distance_km` DECIMAL(8,2) NULL AFTER `booking_cutoff_minutes`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- catalogue weight (nullable until populated)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'weight_kg');
SET @sql = IF(@col = 0,
  'ALTER TABLE `product_variants` ADD COLUMN `weight_kg` DECIMAL(8,3) NULL AFTER `sku`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`)
VALUES ('delivery.zone_resolution_mode', 'pincode_legacy', 0)
ON DUPLICATE KEY UPDATE `setting_value` = IF(`setting_value` = '', 'pincode_legacy', `setting_value`);

UPDATE `settings` SET `setting_value` = '1.8.0' WHERE `setting_key` = 'schema_version';
