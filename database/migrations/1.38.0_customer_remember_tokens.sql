-- Customer remember-login tokens (30-day optional persistent sign-in, separate from PHP session cookie).
-- Apply: mysql ... apni_tokri < database/migrations/1.38.0_customer_remember_tokens.sql

CREATE TABLE IF NOT EXISTS `customer_remember_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `selector` CHAR(24) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `revoked_at` TIMESTAMP NULL DEFAULT NULL,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_remember_selector` (`selector`),
  KEY `idx_customer_remember_customer` (`customer_id`),
  KEY `idx_customer_remember_expires` (`expires_at`),
  CONSTRAINT `fk_customer_remember_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
