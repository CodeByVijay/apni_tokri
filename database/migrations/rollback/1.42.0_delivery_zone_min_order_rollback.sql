-- Rollback for 1.42.0_delivery_zone_min_order.sql
-- Drops the optional per-zone minimum order column. All zones then use the
-- Marketing tab global minimum again (previous behaviour).

SET NAMES utf8mb4;

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_zones' AND COLUMN_NAME = 'minimum_order_amount');
SET @sql = IF(@col > 0,
  'ALTER TABLE `delivery_zones` DROP COLUMN `minimum_order_amount`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.41.0-admin-assisted-offline' WHERE `setting_key` = 'schema_version';
