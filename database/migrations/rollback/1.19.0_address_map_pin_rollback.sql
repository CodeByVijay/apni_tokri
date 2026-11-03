-- Rollback 1.19.0: drop structured address columns (data in these columns is lost; line1/line2/lat/lon are kept).
-- Orders keep their immutable delivery_snapshot_json["address"] block.
SET NAMES utf8mb4;

DELIMITER //
DROP PROCEDURE IF EXISTS `at_drop_address_col`//
CREATE PROCEDURE `at_drop_address_col`(IN col VARCHAR(64))
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_addresses' AND COLUMN_NAME = col) > 0 THEN
    SET @sql := CONCAT('ALTER TABLE `customer_addresses` DROP COLUMN `', col, '`');
    PREPARE s FROM @sql;
    EXECUTE s;
    DEALLOCATE PREPARE s;
  END IF;
END//
DELIMITER ;

CALL `at_drop_address_col`('receiver_name');
CALL `at_drop_address_col`('receiver_phone');
CALL `at_drop_address_col`('alternate_phone');
CALL `at_drop_address_col`('house_number');
CALL `at_drop_address_col`('building_name');
CALL `at_drop_address_col`('street');
CALL `at_drop_address_col`('area');
CALL `at_drop_address_col`('landmark');
CALL `at_drop_address_col`('delivery_instructions');
CALL `at_drop_address_col`('location_confirmed_at');

DROP PROCEDURE IF EXISTS `at_drop_address_col`;

UPDATE `settings` SET `setting_value` = '1.18.0-sms-operation-mode' WHERE `setting_key` = 'schema_version';
