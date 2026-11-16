-- Veggie Tokri schema 1.28.0 — credit-based subscription lifecycle (pause dates, no calendar expiry)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

SET @db := DATABASE();

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subscription_pauses' AND COLUMN_NAME = 'pause_start_date'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `subscription_pauses`
     ADD COLUMN `pause_start_date` DATE NULL AFTER `customer_subscription_id`,
     ADD COLUMN `resume_on_date` DATE NULL AFTER `pause_start_date`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `subscription_pauses`
SET `pause_start_date` = DATE(`paused_at`)
WHERE `pause_start_date` IS NULL;

UPDATE `subscription_pauses`
SET `resume_on_date` = DATE(`resumed_at`)
WHERE `resume_on_date` IS NULL AND `status` = 'closed' AND `resumed_at` IS NOT NULL;

-- Active pauses only: synthetic resume if missing (review open rows where resume_on_date <= CURDATE() before go-live).
UPDATE `subscription_pauses`
SET `resume_on_date` = DATE(DATE_ADD(COALESCE(`pause_start_date`, DATE(`paused_at`)), INTERVAL 30 DAY))
WHERE `resume_on_date` IS NULL AND `status` IN ('open', 'pending');

UPDATE `settings` SET `setting_value` = '1.28.0-subscription-credit-lifecycle' WHERE `setting_key` = 'schema_version';
