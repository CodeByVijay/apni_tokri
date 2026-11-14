-- Veggie Tokri schema 1.43.0 — OTP & SMS security (VT-P-03)
-- Depends on: 1.42.1
-- Rollback: see database/migrations/rollback/1.43.0_otp_sms_security_rollback.sql
--
-- Adds indexes used by the per-IP and global OTP SMS window counts and seeds the
-- configurable security limits (see admin/settings/otp-sms-security.php).
-- Safe to re-run: indexes are only added when missing; settings are only inserted
-- when absent (existing admin-configured values are never overwritten).

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_otp_attempts' AND INDEX_NAME = 'idx_otp_ip_created');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `customer_otp_attempts`
     ADD KEY `idx_otp_ip_created` (`ip_address`, `created_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customer_otp_attempts' AND INDEX_NAME = 'idx_otp_created');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `customer_otp_attempts`
     ADD KEY `idx_otp_created` (`created_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('security.otp.mobile_limit', '3', 0),
  ('security.otp.ip_limit', '10', 0),
  ('security.otp.daily_mobile_limit', '6', 0),
  ('security.otp.global_hourly_limit', '200', 0),
  ('security.otp.resend_cooldown_seconds', '120', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

UPDATE `settings` SET `setting_value` = '1.43.0-otp-sms-security' WHERE `setting_key` = 'schema_version';
