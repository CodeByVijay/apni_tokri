SET NAMES utf8mb4;

-- Run only when no product_images.media_id IS NOT NULL (FK blocks drop otherwise).
-- Does NOT delete physical files under uploads/ (by design).

ALTER TABLE `product_images` DROP FOREIGN KEY `fk_product_images_media`;
ALTER TABLE `product_images` DROP KEY `idx_product_images_media`;
ALTER TABLE `product_images` DROP COLUMN `media_id`, DROP COLUMN `alt_text`;

DROP TABLE IF EXISTS `media_assets`;

UPDATE `settings` SET `setting_value` = '1.36.0-customer-registration-consent' WHERE `setting_key` = 'schema_version';
