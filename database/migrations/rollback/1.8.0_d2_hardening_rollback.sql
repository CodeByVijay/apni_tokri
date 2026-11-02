-- Rollback 1.8.0-d2-hardening
-- WARNING: Rolling back 1.8.0-d2 drops ALL delivery_slot_reservations history (see 1.8.0_d2_rollback.sql).
-- This rollback only removes hardening additions.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `payment_completion_exceptions`;

ALTER TABLE `delivery_slot_reservations` DROP INDEX IF EXISTS `uq_dsr_order`;

UPDATE `settings` SET `setting_value` = '1.8.0-d2' WHERE `setting_key` = 'schema_version';
