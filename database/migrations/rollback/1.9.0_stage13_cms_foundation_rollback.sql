-- Rollback Stage 13 Phase 1 CMS foundation (manual).
-- Reverts schema label to 1.8.0-stage12-c3-1.
-- Does not delete cms_pages rows (including seeded placeholders).

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

DROP TABLE IF EXISTS `cms_page_versions`;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'faqs' AND INDEX_NAME = 'ft_faqs_search');
SET @sql = IF(@idx > 0, 'ALTER TABLE `faqs` DROP INDEX `ft_faqs_search`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'faqs' AND INDEX_NAME = 'idx_faqs_active_sort');
SET @sql = IF(@idx > 0, 'ALTER TABLE `faqs` DROP INDEX `idx_faqs_active_sort`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'faq_categories' AND COLUMN_NAME = 'is_active');
SET @sql = IF(@col > 0, 'ALTER TABLE `faq_categories` DROP COLUMN `is_active`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'focus_keyword');
SET @sql = IF(@col > 0,
  'ALTER TABLE `cms_pages`
     DROP COLUMN `focus_keyword`,
     DROP COLUMN `canonical_url`,
     DROP COLUMN `og_title`,
     DROP COLUMN `og_description`,
     DROP COLUMN `og_image`,
     DROP COLUMN `robots_index`,
     DROP COLUMN `robots_follow`,
     DROP COLUMN `visibility`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.8.0-stage12-c3-1' WHERE `setting_key` = 'schema_version';
