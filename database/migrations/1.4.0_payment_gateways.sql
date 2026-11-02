-- Apni Tokri schema 1.4.0 — payment gateways (Stage 9)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE `payments`
  ADD COLUMN `idempotency_key` VARCHAR(64) NULL AFTER `gateway_payment_id`,
  ADD COLUMN `gateway_event_id` VARCHAR(191) NULL AFTER `idempotency_key`,
  ADD COLUMN `metadata_json` JSON NULL AFTER `status`;

ALTER TABLE `payments`
  ADD UNIQUE KEY `uq_payments_idempotency` (`idempotency_key`);

ALTER TABLE `payment_webhook_events`
  ADD COLUMN `process_status` VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `payload_json`,
  ADD COLUMN `error_message` VARCHAR(255) NULL AFTER `processed_at`;

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('payment.active_gateway', 'simulate', 0),
  ('payment.test_mode', '1', 0),
  ('razorpay.enabled', '0', 0),
  ('cashfree.enabled', '0', 0)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `settings` SET `setting_value` = '1.4.0' WHERE `setting_key` = 'schema_version';
