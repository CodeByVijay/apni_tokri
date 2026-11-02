-- Apni Tokri — Stage 13 Phase 1 (CMS foundation, versions, FAQ indexes)
-- Safe to re-run (conditional ADD COLUMN / CREATE IF NOT EXISTS).
-- Depends on: cms_pages, faq_categories, faqs (database.sql / 1.7.0+).
-- Rollback: database/migrations/rollback/1.9.0_stage13_cms_foundation_rollback.sql
-- Legal page placeholders: draft rows inserted below (no policy text).

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'focus_keyword');
SET @sql = IF(@col = 0,
  'ALTER TABLE `cms_pages`
     ADD COLUMN `focus_keyword` VARCHAR(191) NULL DEFAULT NULL AFTER `meta_description`,
     ADD COLUMN `canonical_url` VARCHAR(512) NULL DEFAULT NULL AFTER `focus_keyword`,
     ADD COLUMN `og_title` VARCHAR(191) NULL DEFAULT NULL AFTER `canonical_url`,
     ADD COLUMN `og_description` VARCHAR(255) NULL DEFAULT NULL AFTER `og_title`,
     ADD COLUMN `og_image` VARCHAR(512) NULL DEFAULT NULL AFTER `og_description`,
     ADD COLUMN `robots_index` TINYINT(1) NOT NULL DEFAULT 1 AFTER `og_image`,
     ADD COLUMN `robots_follow` TINYINT(1) NOT NULL DEFAULT 1 AFTER `robots_index`,
     ADD COLUMN `visibility` VARCHAR(32) NOT NULL DEFAULT ''public'' AFTER `robots_follow`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cms_pages' AND COLUMN_NAME = 'visibility');
SET @sql = IF(@col = 0,
  'ALTER TABLE `cms_pages` ADD COLUMN `visibility` VARCHAR(32) NOT NULL DEFAULT ''public'' AFTER `show_in_footer`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `cms_page_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cms_page_id` INT UNSIGNED NOT NULL,
  `version_number` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(191) NOT NULL,
  `body_html` LONGTEXT NULL,
  `status` VARCHAR(32) NOT NULL,
  `meta_title` VARCHAR(191) NULL,
  `meta_description` VARCHAR(255) NULL,
  `focus_keyword` VARCHAR(191) NULL,
  `canonical_url` VARCHAR(512) NULL,
  `og_title` VARCHAR(191) NULL,
  `og_description` VARCHAR(255) NULL,
  `og_image` VARCHAR(512) NULL,
  `robots_index` TINYINT(1) NOT NULL DEFAULT 1,
  `robots_follow` TINYINT(1) NOT NULL DEFAULT 1,
  `visibility` VARCHAR(32) NOT NULL DEFAULT 'public',
  `change_summary` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cms_page_version` (`cms_page_id`, `version_number`),
  KEY `idx_cms_page_versions_page` (`cms_page_id`, `created_at`),
  CONSTRAINT `fk_cms_page_versions_page` FOREIGN KEY (`cms_page_id`) REFERENCES `cms_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cms_page_versions_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'faq_categories' AND COLUMN_NAME = 'is_active');
SET @sql = IF(@col = 0,
  'ALTER TABLE `faq_categories` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sort_order`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'faqs' AND INDEX_NAME = 'ft_faqs_search');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `faqs` ADD FULLTEXT KEY `ft_faqs_search` (`question`, `answer`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'faqs' AND INDEX_NAME = 'idx_faqs_active_sort');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `faqs` ADD KEY `idx_faqs_active_sort` (`is_active`, `category_id`, `sort_order`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'About Us', 'about-us', '<p>Content placeholder. Replace with approved About Us copy before publishing.</p>', 'draft', 1, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'about-us' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Contact Us', 'contact-us', '<p>Content placeholder. Replace with approved contact information before publishing.</p>', 'draft', 0, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'contact-us' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Privacy Policy', 'privacy-policy', '<p>Content placeholder. Replace with lawyer-reviewed privacy policy before publishing.</p>', 'draft', 1, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'privacy-policy' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Terms and Conditions', 'terms', '<p>Content placeholder. Replace with lawyer-reviewed terms before publishing.</p>', 'draft', 1, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'terms' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Refund and Cancellation Policy', 'refund-policy', '<p>Content placeholder. Replace with approved refund policy before publishing.</p>', 'draft', 1, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'refund-policy' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Shipping and Delivery Policy', 'shipping-policy', '<p>Content placeholder. Replace with approved shipping policy before publishing.</p>', 'draft', 1, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'shipping-policy' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Subscription Terms', 'subscription-terms', '<p>Content placeholder. Replace with approved subscription terms before publishing.</p>', 'draft', 1, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'subscription-terms' LIMIT 1);

INSERT INTO `cms_pages` (`title`, `slug`, `body_html`, `status`, `show_in_footer`, `visibility`)
SELECT 'Disclaimer', 'disclaimer', '<p>Content placeholder. Replace with approved disclaimer before publishing.</p>', 'draft', 0, 'public'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `cms_pages` WHERE slug = 'disclaimer' LIMIT 1);

UPDATE `settings` SET `setting_value` = '1.9.0-stage13-cms-1' WHERE `setting_key` = 'schema_version';
