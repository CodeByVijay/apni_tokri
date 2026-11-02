-- Apni Tokri — Stage 12 Phase C.2.1 (review moderation audit history)
-- Safe to re-run (CREATE IF NOT EXISTS).
-- Depends on: product_reviews (1.7.0+). Apply on installs already at 1.8.0-d5-subscription or later.
-- Rollback: database/migrations/rollback/1.7.2_stage12_review_moderation_history_rollback.sql
--   (drops review_moderation_history; reverts schema_version label to 1.8.0-d5-subscription).

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS `review_moderation_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('admin','system') NOT NULL DEFAULT 'admin',
  `admin_user_id` INT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,
  `previous_status` VARCHAR(32) NULL,
  `new_status` VARCHAR(32) NULL,
  `reason` VARCHAR(255) NULL,
  `metadata_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_mod_history_review` (`review_id`, `created_at`),
  KEY `idx_review_mod_history_admin` (`admin_user_id`),
  KEY `idx_review_mod_history_created` (`created_at`),
  CONSTRAINT `fk_review_mod_history_review` FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_mod_history_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.8.0-stage12-c2-1' WHERE `setting_key` = 'schema_version';
