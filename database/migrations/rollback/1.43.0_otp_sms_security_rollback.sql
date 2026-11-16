-- Rollback for 1.43.0_otp_sms_security.sql
-- Drops the OTP count indexes and removes the security settings keys
-- (runtime falls back to built-in defaults when keys are absent).

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_otp_attempts' AND INDEX_NAME = 'idx_otp_ip_created');
SET @sql = IF(@idx > 0,
  'ALTER TABLE `customer_otp_attempts` DROP KEY `idx_otp_ip_created`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_otp_attempts' AND INDEX_NAME = 'idx_otp_created');
SET @sql = IF(@idx > 0,
  'ALTER TABLE `customer_otp_attempts` DROP KEY `idx_otp_created`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE FROM `settings` WHERE `setting_key` IN (
  'security.otp.mobile_limit',
  'security.otp.ip_limit',
  'security.otp.daily_mobile_limit',
  'security.otp.global_hourly_limit',
  'security.otp.resend_cooldown_seconds'
);

UPDATE `settings` SET `setting_value` = '1.42.1-admin-min-order-override' WHERE `setting_key` = 'schema_version';
