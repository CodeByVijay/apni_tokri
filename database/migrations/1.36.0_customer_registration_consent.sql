-- Customer account registration consent (Terms + Privacy at first signup).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `customer_registration_consents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `consent_type` VARCHAR(64) NOT NULL DEFAULT 'account_registration',
  `terms_slug` VARCHAR(64) NULL DEFAULT NULL,
  `terms_version` INT UNSIGNED NULL DEFAULT NULL,
  `privacy_slug` VARCHAR(64) NULL DEFAULT NULL,
  `privacy_version` INT UNSIGNED NULL DEFAULT NULL,
  `accepted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(512) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_registration_consent` (`customer_id`, `consent_type`),
  KEY `idx_crc_accepted_at` (`accepted_at`),
  CONSTRAINT `fk_crc_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
