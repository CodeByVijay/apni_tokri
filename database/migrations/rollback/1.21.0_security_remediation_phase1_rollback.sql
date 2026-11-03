DROP TABLE IF EXISTS `support_guest_ticket_attempts`;
DROP TABLE IF EXISTS `admin_password_reset_tokens`;
DROP TABLE IF EXISTS `admin_login_attempts`;
ALTER TABLE `admin_users` DROP COLUMN `auth_epoch`;
UPDATE `settings` SET `setting_value` = '1.20.0-invoices' WHERE `setting_key` = 'schema_version';
