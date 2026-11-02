-- Rollback 1.8.0-d2 (run after releasing active slot holds)

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `delivery_slot_reservations`;

ALTER TABLE `delivery_slots` DROP COLUMN IF EXISTS `allowed_provider_slugs_json`;

UPDATE `settings` SET `setting_value` = '1.8.0' WHERE `setting_key` = 'schema_version';
