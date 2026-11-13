-- Veggie Tokri schema 1.42.0 — Zone-wise minimum order amount
-- Depends on: 1.41.0
-- Rollback: see database/migrations/rollback/1.42.0_delivery_zone_min_order_rollback.sql
--
-- Adds optional `minimum_order_amount` to `delivery_zones`.
-- NULL (or <= 0) means the existing Marketing tab global minimum applies as fallback.
-- Existing rows keep NULL — behaviour unchanged until an admin configures a value.
-- Safe to re-run: column is only added when missing; no data is modified.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_zones' AND COLUMN_NAME = 'minimum_order_amount');
SET @sql = IF(@col = 0,
  'ALTER TABLE `delivery_zones`
     ADD COLUMN `minimum_order_amount` DECIMAL(10,2) NULL DEFAULT NULL AFTER `free_above`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.42.0-zone-min-order' WHERE `setting_key` = 'schema_version';
