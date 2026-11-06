-- Veggie Tokri schema 1.7.0 — reviews, testimonials, support, refunds (Stage 12)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

-- product_reviews extensions
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_reviews' AND COLUMN_NAME = 'order_item_id');
SET @sql = IF(@col = 0,
  'ALTER TABLE `product_reviews`
     ADD COLUMN `order_item_id` BIGINT UNSIGNED NULL AFTER `order_id`,
     ADD COLUMN `display_name` VARCHAR(191) NULL AFTER `customer_id`,
     ADD COLUMN `verified_purchase` TINYINT(1) NOT NULL DEFAULT 0 AFTER `body`,
     ADD COLUMN `sub_ratings_json` JSON NULL AFTER `verified_purchase`,
     ADD COLUMN `admin_reply` TEXT NULL AFTER `status`,
     ADD COLUMN `admin_reply_at` TIMESTAMP NULL DEFAULT NULL AFTER `admin_reply`,
     ADD COLUMN `admin_reply_by` INT UNSIGNED NULL AFTER `admin_reply_at`,
     ADD COLUMN `rejection_reason` VARCHAR(255) NULL AFTER `admin_reply_by`,
     ADD COLUMN `featured` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rejection_reason`,
     ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'product_reviews' AND INDEX_NAME = 'uq_reviews_order_item');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `product_reviews` ADD UNIQUE KEY `uq_reviews_order_item` (`order_item_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `product_review_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_review_photos_review` (`review_id`),
  CONSTRAINT `fk_review_photos_review` FOREIGN KEY (`review_id`) REFERENCES `product_reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `testimonials` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_display_name` VARCHAR(191) NOT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `consent_recorded_at` TIMESTAMP NULL DEFAULT NULL,
  `consent_note` VARCHAR(255) NULL,
  `featured` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `customer_id` BIGINT UNSIGNED NULL,
  `created_by_admin_id` INT UNSIGNED NULL,
  `rejection_reason` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_testimonials_status_featured` (`status`, `featured`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'support_tickets' AND COLUMN_NAME = 'updated_at');
SET @sql = IF(@col = 0,
  'ALTER TABLE `support_tickets`
     ADD COLUMN `priority` VARCHAR(32) NOT NULL DEFAULT ''normal'' AFTER `status`,
     ADD COLUMN `assigned_admin_id` INT UNSIGNED NULL AFTER `priority`,
     ADD COLUMN `closed_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`,
     ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `closed_at`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `refund_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_number` VARCHAR(32) NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `support_ticket_id` BIGINT UNSIGNED NULL,
  `reason_code` VARCHAR(64) NOT NULL,
  `description` TEXT NULL,
  `preferred_resolution` VARCHAR(32) NOT NULL DEFAULT 'refund',
  `status` VARCHAR(32) NOT NULL DEFAULT 'requested',
  `approved_amount_inr` DECIMAL(12,2) NULL,
  `rejection_reason` VARCHAR(255) NULL,
  `internal_notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refund_request_number` (`request_number`),
  KEY `idx_refund_requests_status` (`status`, `created_at`),
  KEY `idx_refund_requests_order` (`order_id`),
  CONSTRAINT `fk_refund_req_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_refund_req_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refund_request_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_request_id` BIGINT UNSIGNED NOT NULL,
  `order_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `amount_inr` DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_refund_items_request` (`refund_request_id`),
  CONSTRAINT `fk_refund_items_request` FOREIGN KEY (`refund_request_id`) REFERENCES `refund_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_refund_items_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refund_request_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_request_id` BIGINT UNSIGNED NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(64) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_refund_evidence_request` (`refund_request_id`),
  CONSTRAINT `fk_refund_evidence_request` FOREIGN KEY (`refund_request_id`) REFERENCES `refund_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refund_request_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_request_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('customer','admin','system') NOT NULL,
  `actor_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,
  `payload_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_refund_history_request` (`refund_request_id`, `created_at`),
  CONSTRAINT `fk_refund_history_request` FOREIGN KEY (`refund_request_id`) REFERENCES `refund_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_request_id` BIGINT UNSIGNED NULL,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `gateway` VARCHAR(32) NOT NULL,
  `amount_inr` DECIMAL(12,2) NOT NULL,
  `idempotency_key` VARCHAR(64) NOT NULL,
  `gateway_refund_id` VARCHAR(64) NULL,
  `gateway_status` VARCHAR(32) NOT NULL DEFAULT 'created',
  `response_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_refunds_idempotency` (`idempotency_key`),
  KEY `idx_payment_refunds_request` (`refund_request_id`),
  KEY `idx_payment_refunds_payment` (`payment_id`),
  CONSTRAINT `fk_payment_refunds_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_refunds_request` FOREIGN KEY (`refund_request_id`) REFERENCES `refund_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'amount_refunded_inr');
SET @sql = IF(@col = 0,
  'ALTER TABLE `payments` ADD COLUMN `amount_refunded_inr` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `amount`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('refunds.allow_store_credit', '0', 0),
  ('refunds.allow_replacement', '1', 0)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `settings` SET `setting_value` = '1.7.0' WHERE `setting_key` = 'schema_version';
