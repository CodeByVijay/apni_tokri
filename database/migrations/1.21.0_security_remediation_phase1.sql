-- Security remediation phase 1 (AT-SEC-002, AT-SEC-003, AT-SEC-011 guest rate limit)
-- Apply: mysql ... apni_tokri < database/migrations/1.21.0_security_remediation_phase1.sql

ALTER TABLE `admin_users`
  ADD COLUMN `auth_epoch` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `password_hash`;

CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email_normalized` VARCHAR(191) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_login_email_created` (`email_normalized`, `created_at`),
  KEY `idx_admin_login_ip_created` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_password_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `request_ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_reset_token_hash` (`token_hash`),
  KEY `idx_admin_reset_user_created` (`admin_user_id`, `created_at`),
  KEY `idx_admin_reset_expires` (`expires_at`),
  CONSTRAINT `fk_admin_reset_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_guest_ticket_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_guest_ip_created` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.21.0-security-phase1' WHERE `setting_key` = 'schema_version';
