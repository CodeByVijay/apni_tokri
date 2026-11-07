-- Order number sequences (independent of invoice_sequences). Idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `order_sequences` (
  `prefix` VARCHAR(16) NOT NULL,
  `period_key` VARCHAR(12) NOT NULL,
  `series` VARCHAR(16) NOT NULL DEFAULT 'storefront',
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`prefix`, `period_key`, `series`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
