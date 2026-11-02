-- Apni Tokri schema 1.8.0-d2-hardening — D2 production hardening (depends on 1.8.0-d2)
-- Before applying: resolve duplicate reservations —
--   SELECT order_id, COUNT(*) AS c FROM delivery_slot_reservations WHERE order_id IS NOT NULL GROUP BY order_id HAVING c > 1;
-- Rollback: database/migrations/rollback/1.8.0_d2_hardening_rollback.sql

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @dup = (
  SELECT COUNT(*) FROM (
    SELECT order_id FROM delivery_slot_reservations WHERE order_id IS NOT NULL GROUP BY order_id HAVING COUNT(*) > 1
  ) AS d
);
SET @sql = IF(@dup > 0,
  'SELECT ''ABORT: duplicate delivery_slot_reservations.order_id rows — resolve manually before hardening'' AS migration_error',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_slot_reservations' AND INDEX_NAME = 'uq_dsr_order');
SET @sql = IF(@idx = 0 AND @dup = 0,
  'ALTER TABLE `delivery_slot_reservations` ADD UNIQUE KEY `uq_dsr_order` (`order_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `payment_completion_exceptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `payment_id` BIGINT UNSIGNED NULL,
  `gateway` VARCHAR(32) NOT NULL,
  `gateway_payment_id` VARCHAR(191) NOT NULL,
  `gateway_order_id` VARCHAR(191) NULL,
  `gateway_event_id` VARCHAR(191) NULL,
  `captured_amount_inr` DECIMAL(12,2) NOT NULL,
  `delivery_slot_id` INT UNSIGNED NULL,
  `delivery_date` DATE NULL,
  `failure_reason` VARCHAR(255) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'open',
  `resolution_note` TEXT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `admin_user_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pce_gateway_payment` (`gateway`, `gateway_payment_id`),
  KEY `idx_pce_order` (`order_id`),
  KEY `idx_pce_status` (`status`),
  CONSTRAINT `fk_pce_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pce_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pce_slot` FOREIGN KEY (`delivery_slot_id`) REFERENCES `delivery_slots` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.8.0-d2-hardening' WHERE `setting_key` = 'schema_version';
