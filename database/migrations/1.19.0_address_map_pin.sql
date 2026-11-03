-- Structured delivery addresses + receiver details + confirmed map pin metadata.
-- latitude / longitude (DECIMAL(10,7) NULL) already exist on customer_addresses since 1.0.0.
-- Idempotent: each column is added only when missing. Legacy rows keep line1/line2 and stay valid.
-- Immutable order address snapshots are stored inside orders.delivery_snapshot_json ("address" key),
-- so no orders table change is required.

SET NAMES utf8mb4;

DELIMITER //
DROP PROCEDURE IF EXISTS `at_add_address_col`//
CREATE PROCEDURE `at_add_address_col`(IN col VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = col) = 0 THEN
    SET @sql := CONCAT('ALTER TABLE `customer_addresses` ADD COLUMN ', ddl);
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  END IF;
END//
DELIMITER ;

CALL `at_add_address_col`('receiver_name', '`receiver_name` VARCHAR(100) NULL DEFAULT NULL AFTER `label`');
CALL `at_add_address_col`('receiver_phone', '`receiver_phone` VARCHAR(15) NULL DEFAULT NULL AFTER `receiver_name`');
CALL `at_add_address_col`('alternate_phone', '`alternate_phone` VARCHAR(15) NULL DEFAULT NULL AFTER `receiver_phone`');
CALL `at_add_address_col`('house_number', '`house_number` VARCHAR(100) NULL DEFAULT NULL AFTER `alternate_phone`');
CALL `at_add_address_col`('building_name', '`building_name` VARCHAR(150) NULL DEFAULT NULL AFTER `house_number`');
CALL `at_add_address_col`('street', '`street` VARCHAR(150) NULL DEFAULT NULL AFTER `building_name`');
CALL `at_add_address_col`('area', '`area` VARCHAR(150) NULL DEFAULT NULL AFTER `street`');
CALL `at_add_address_col`('landmark', '`landmark` VARCHAR(191) NULL DEFAULT NULL AFTER `area`');
CALL `at_add_address_col`('delivery_instructions', '`delivery_instructions` VARCHAR(500) NULL DEFAULT NULL AFTER `landmark`');
CALL `at_add_address_col`('location_confirmed_at', '`location_confirmed_at` DATETIME NULL DEFAULT NULL AFTER `longitude`');

DROP PROCEDURE IF EXISTS `at_add_address_col`;

UPDATE `settings` SET `setting_value` = '1.19.0-address-map-pin' WHERE `setting_key` = 'schema_version';
