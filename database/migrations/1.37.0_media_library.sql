-- Central media library + product image media_id link.
-- Preflight (run manually on existing DBs): duplicate active hashes block UNIQUE uq_media_file_sha256:
--   SELECT file_sha256, COUNT(*) c FROM media_assets WHERE file_sha256 IS NOT NULL GROUP BY file_sha256 HAVING c > 1;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `media_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_filename` VARCHAR(255) NOT NULL,
  `storage_filename` VARCHAR(128) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(64) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT UNSIGNED NULL DEFAULT NULL,
  `height` INT UNSIGNED NULL DEFAULT NULL,
  `file_sha256` CHAR(64) NULL DEFAULT NULL,
  `alt_text` VARCHAR(255) NOT NULL DEFAULT '',
  `uploaded_by` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_file_path` (`file_path`),
  KEY `idx_media_created` (`created_at`),
  KEY `idx_media_deleted` (`deleted_at`),
  KEY `idx_media_sha256` (`file_sha256`),
  UNIQUE KEY `uq_media_file_sha256` (`file_sha256`),
  KEY `idx_media_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_media_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `product_images`
  ADD COLUMN `media_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `product_id`,
  ADD COLUMN `alt_text` VARCHAR(255) NULL DEFAULT NULL AFTER `file_path`;

ALTER TABLE `product_images`
  ADD KEY `idx_product_images_media` (`media_id`),
  ADD CONSTRAINT `fk_product_images_media` FOREIGN KEY (`media_id`) REFERENCES `media_assets` (`id`) ON DELETE RESTRICT;

UPDATE `settings` SET `setting_value` = '1.37.0-media-library' WHERE `setting_key` = 'schema_version';
