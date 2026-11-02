-- D3 audit hardening: atomic SMS send locks + HTTP success contract settings (schema stays 1.8.0-d3-manual)
-- Rollback: database/migrations/rollback/1.8.0_d3_hardening_rollback.sql

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS `delivery_sms_send_locks` (
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(32) NOT NULL,
  `lock_status` VARCHAR(16) NOT NULL DEFAULT 'idle',
  `lease_token` VARCHAR(64) NOT NULL DEFAULT '',
  `lease_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `active_log_id` BIGINT UNSIGNED NULL,
  `success_log_id` BIGINT UNSIGNED NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`delivery_id`, `event_key`),
  KEY `idx_dssl_lease` (`lock_status`, `lease_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('sms.http.allowed_status_codes', '200', 0),
  ('sms.http.require_json', '1', 0),
  ('sms.http.success_json_path', 'status', 0),
  ('sms.http.success_json_value', 'success', 0),
  ('sms.http.message_id_json_path', 'message_id', 0),
  ('sms.http.timeout_seconds', '15', 0)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `settings` SET `setting_value` = '1.8.0-d3-manual' WHERE `setting_key` = 'schema_version';
