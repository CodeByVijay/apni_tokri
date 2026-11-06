-- Veggie Tokri schema 1.8.0-d3-manual — D3 manual courier + delivery SMS logs (depends on 1.8.0-d2-hardening)
-- Rollback: database/migrations/rollback/1.8.0_d3_manual_delivery_rollback.sql

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

-- deliveries: operational fields
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'courier_service_name');
SET @sql = IF(@col = 0,
  'ALTER TABLE `deliveries`
     ADD COLUMN `courier_service_name` VARCHAR(128) NULL AFTER `driver_name`,
     ADD COLUMN `booking_reference` VARCHAR(191) NULL AFTER `courier_service_name`,
     ADD COLUMN `delivery_person_phone` VARCHAR(32) NULL AFTER `booking_reference`,
     ADD COLUMN `dispatched_at` TIMESTAMP NULL DEFAULT NULL AFTER `assigned_at`,
     ADD COLUMN `internal_remarks` TEXT NULL AFTER `notes`,
     ADD COLUMN `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `internal_remarks`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- delivery_status_history extensions (before legacy audit rows)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'delivery_status_history' AND COLUMN_NAME = 'customer_visible');
SET @sql = IF(@col = 0,
  'ALTER TABLE `delivery_status_history`
     ADD COLUMN `customer_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `note`,
     ADD COLUMN `public_label` VARCHAR(64) NULL AFTER `customer_visible`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Preserve legacy status in history before rewriting current row status
INSERT INTO `delivery_status_history` (`delivery_id`, `status`, `note`, `admin_user_id`, `customer_visible`, `public_label`)
SELECT d.id, d.status, CONCAT('Legacy status preserved at D3 migration: ', d.status), NULL, 0, NULL
FROM `deliveries` d
WHERE d.status IN ('queued', 'assigned', 'out_for_delivery', 'in_transit')
  AND NOT EXISTS (
    SELECT 1 FROM `delivery_status_history` h
    WHERE h.delivery_id = d.id AND h.note LIKE 'Legacy status preserved at D3 migration:%'
  );

UPDATE `deliveries` SET `status` = 'pending_dispatch' WHERE `status` IN ('queued', 'assigned');
UPDATE `deliveries` SET `status` = 'dispatched', `dispatched_at` = COALESCE(`dispatched_at`, `assigned_at`, NOW())
  WHERE `status` IN ('out_for_delivery', 'in_transit');

CREATE TABLE IF NOT EXISTS `delivery_sms_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(32) NOT NULL,
  `template_key` VARCHAR(64) NOT NULL,
  `mobile` VARCHAR(16) NOT NULL,
  `mobile_masked` VARCHAR(16) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `gateway` VARCHAR(32) NULL,
  `gateway_message_id` VARCHAR(191) NULL,
  `gateway_response` TEXT NULL,
  `error_message` VARCHAR(255) NULL,
  `attempt_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dsl_delivery_event` (`delivery_id`, `event_key`),
  KEY `idx_dsl_status` (`status`, `created_at`),
  CONSTRAINT `fk_dsl_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dsl_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('delivery.courier_presets', '["Porter","Rapido","In-house","Other"]', 0),
  ('sms.enabled', '0', 0),
  ('sms.provider', 'simulate', 0),
  ('sms.sender_id', '', 0),
  ('sms.template.order_confirmed', 'Your Veggie Tokri order {order_number} is confirmed. Thank you!', 0),
  ('sms.template.ready', 'Your Veggie Tokri order {order_number} is packed and ready for dispatch.', 0),
  ('sms.template.dispatched', 'Your Veggie Tokri order {order_number} has been dispatched.', 0),
  ('sms.template.delivered', 'Your Veggie Tokri order {order_number} was delivered. Enjoy!', 0),
  ('sms.template.failed', 'We could not complete delivery for order {order_number}. Our team will contact you.', 0)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `settings` SET `setting_value` = '1.8.0-d3-manual' WHERE `setting_key` = 'schema_version';
