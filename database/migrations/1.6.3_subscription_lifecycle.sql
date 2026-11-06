-- Veggie Tokri schema 1.6.3 — skip/pause, auto-confirm idempotency, notifications (Stage 11 Phase D)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS `subscription_skips` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `carry_forward_day_id` BIGINT UNSIGNED NULL,
  `skipped_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_skip_delivery_day` (`subscription_delivery_day_id`),
  KEY `idx_skips_sub` (`customer_subscription_id`),
  CONSTRAINT `fk_skip_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skip_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_skip_cf_day` FOREIGN KEY (`carry_forward_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_pauses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `paused_at` TIMESTAMP NOT NULL,
  `resumed_at` TIMESTAMP NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'open',
  `note` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pauses_sub` (`customer_subscription_id`, `status`),
  CONSTRAINT `fk_pause_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_auto_confirm_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_delivery_day_id` BIGINT UNSIGNED NOT NULL,
  `basket_id` BIGINT UNSIGNED NULL,
  `cron_run_id` VARCHAR(64) NOT NULL,
  `confirmed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auto_confirm_day` (`subscription_delivery_day_id`),
  CONSTRAINT `fk_sace_day` FOREIGN KEY (`subscription_delivery_day_id`) REFERENCES `subscription_delivery_days` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(191) NOT NULL,
  `body` TEXT NOT NULL,
  `context_json` JSON NULL,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_customer_notifications` (`customer_id`, `read_at`, `created_at`),
  CONSTRAINT `fk_cn_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.6.3' WHERE `setting_key` = 'schema_version';
