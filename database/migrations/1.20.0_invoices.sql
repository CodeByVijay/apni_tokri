-- Invoice management: immutable invoice / credit-note documents, financial-year sequences, email delivery log.
-- No existing table is altered. Idempotent (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `invoice_sequences` (
  `prefix` VARCHAR(16) NOT NULL,
  `fiscal_year` VARCHAR(9) NOT NULL,
  `doc_series` VARCHAR(16) NOT NULL DEFAULT 'invoice',
  `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`prefix`, `fiscal_year`, `doc_series`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_ref` CHAR(24) NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `document_type` VARCHAR(24) NOT NULL DEFAULT 'retail_invoice',
  `document_status` VARCHAR(16) NOT NULL DEFAULT 'final',
  `parent_invoice_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `payment_refund_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `dedupe_key` VARCHAR(64) NOT NULL,
  `invoice_number` VARCHAR(48) NOT NULL,
  `fiscal_year` VARCHAR(9) NOT NULL,
  `sequence_no` INT UNSIGNED NOT NULL,
  `invoice_date` DATE NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'INR',
  `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `snapshot_json` LONGTEXT NOT NULL,
  `snapshot_hash` CHAR(64) NOT NULL,
  `pdf_path` VARCHAR(255) NOT NULL,
  `pdf_hash` CHAR(64) NOT NULL,
  `pdf_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `pdf_generated_at` DATETIME NOT NULL,
  `generator_version` VARCHAR(16) NOT NULL DEFAULT '1',
  `created_by_admin_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  UNIQUE KEY `uq_invoices_dedupe` (`dedupe_key`),
  UNIQUE KEY `uq_invoices_public_ref` (`public_ref`),
  KEY `idx_invoices_order` (`order_id`),
  KEY `idx_invoices_customer` (`customer_id`, `invoice_date`),
  KEY `idx_invoices_date` (`invoice_date`),
  KEY `idx_invoices_parent` (`parent_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_email_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `recipient` VARCHAR(190) NOT NULL,
  `trigger_source` VARCHAR(16) NOT NULL DEFAULT 'admin',
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `error_code` VARCHAR(64) NULL DEFAULT NULL,
  `admin_user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `auto_key` VARCHAR(40) NULL DEFAULT NULL,
  `attempted_at` DATETIME NOT NULL,
  `completed_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_email_auto` (`auto_key`),
  KEY `idx_invoice_email_invoice` (`invoice_id`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.20.0-invoices' WHERE `setting_key` = 'schema_version';
