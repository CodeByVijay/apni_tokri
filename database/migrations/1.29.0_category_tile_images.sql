-- Veggie Tokri schema 1.29.0 — category tile cover images (homepage visibility column already in base schema)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'image_path'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `categories` ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `description`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'show_on_homepage'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `categories` ADD COLUMN `show_on_homepage` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sort_order`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.29.0-category-tile-images' WHERE `setting_key` = 'schema_version';
