-- CMS footer display order. Forward-only, idempotent, non-destructive.
-- NULL = unset; storefront falls back to page id (existing order preserved).

SET NAMES utf8mb4;
SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'footer_display_order');
SET @sql = IF(@col = 0,
  'ALTER TABLE `cms_pages` ADD COLUMN `footer_display_order` INT UNSIGNED NULL DEFAULT NULL AFTER `show_in_footer`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.24.0-cms-footer-order' WHERE `setting_key` = 'schema_version';
