-- Rollback 1.8.0-d3-manual (manual review recommended if SMS logs must be kept)

SET NAMES utf8mb4;

UPDATE `deliveries` SET `status` = 'queued' WHERE `status` = 'pending_dispatch';
UPDATE `deliveries` SET `status` = 'assigned' WHERE `status` = 'ready';
UPDATE `deliveries` SET `status` = 'out_for_delivery' WHERE `status` = 'dispatched';

DROP TABLE IF EXISTS `delivery_sms_logs`;

SET @db = DATABASE();
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_status_history' AND COLUMN_NAME = 'customer_visible');
SET @sql = IF(@col > 0,
  'ALTER TABLE `delivery_status_history` DROP COLUMN `customer_visible`, DROP COLUMN `public_label`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'courier_service_name');
SET @sql = IF(@col > 0,
  'ALTER TABLE `deliveries`
     DROP COLUMN `courier_service_name`,
     DROP COLUMN `booking_reference`,
     DROP COLUMN `delivery_person_phone`,
     DROP COLUMN `dispatched_at`,
     DROP COLUMN `internal_remarks`,
     DROP COLUMN `retry_count`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.8.0-d2-hardening' WHERE `setting_key` = 'schema_version';
