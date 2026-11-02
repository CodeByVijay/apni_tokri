-- Admin in-app notifications (support ticket bell). Idempotent where noted.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(64) NOT NULL,
  `ticket_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` TEXT NOT NULL,
  `context_json` JSON NULL,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_notifications_idempotency` (`idempotency_key`),
  KEY `idx_admin_notifications_ticket` (`ticket_id`),
  KEY `idx_admin_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_notification_reads` (
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `admin_user_id` INT UNSIGNED NOT NULL,
  `read_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`, `admin_user_id`),
  KEY `idx_admin_notification_reads_user` (`admin_user_id`, `read_at`),
  CONSTRAINT `fk_anr_notification` FOREIGN KEY (`notification_id`) REFERENCES `admin_notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_anr_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.16.0-admin-support-notifications' WHERE `setting_key` = 'schema_version';
