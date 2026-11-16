-- Customer address management: soft-archive addresses referenced by historical orders.
-- Idempotent: adds archived_at only when missing.

SET NAMES utf8mb4;

SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = 'archived_at');
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `customer_addresses` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL AFTER `is_default`, ADD KEY `idx_customer_addresses_active` (`customer_id`, `archived_at`)',
  'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.17.0-customer-addresses' WHERE `setting_key` = 'schema_version';
