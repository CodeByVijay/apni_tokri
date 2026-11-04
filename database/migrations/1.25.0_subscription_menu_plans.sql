-- Subscription Menu Planner (Plan -> Day -> Vegetables). Forward-only, idempotent, non-destructive.
-- Reuses subscription_menu_templates / subscription_menu_template_items for day menus.
-- Existing rows keep menu_plan_id = NULL and continue to work exactly as before.

SET NAMES utf8mb4;
SET @db = DATABASE();

CREATE TABLE IF NOT EXISTS `subscription_menu_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `subscription_plan_id` INT UNSIGNED NULL,
  `duration_days` SMALLINT UNSIGNED NOT NULL,
  `repeat_mode` VARCHAR(16) NOT NULL DEFAULT 'none' COMMENT 'none|every_n|weekly',
  `repeat_every` SMALLINT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `activated_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_smp_plan_active` (`subscription_plan_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_menu_templates' AND COLUMN_NAME = 'menu_plan_id');
SET @sql = IF(@col = 0,
  'ALTER TABLE `subscription_menu_templates`
     ADD COLUMN `menu_plan_id` INT UNSIGNED NULL DEFAULT NULL AFTER `notes`,
     ADD COLUMN `is_customized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `menu_plan_id`,
     ADD UNIQUE KEY `uq_menu_template_plan_day` (`menu_plan_id`, `day_index`),
     ADD CONSTRAINT `fk_smt_menu_plan` FOREIGN KEY (`menu_plan_id`) REFERENCES `subscription_menu_plans` (`id`) ON DELETE CASCADE',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.25.0-subscription-menu-planner' WHERE `setting_key` = 'schema_version';
