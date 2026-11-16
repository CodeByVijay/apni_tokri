-- Veggie Tokri schema 1.31.0 — coupon storefront visibility (Available Offers discovery)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE `coupons`
  ADD COLUMN `storefront_visible` TINYINT(1) NOT NULL DEFAULT 1
  COMMENT '1 = may appear in cart Available Offers; 0 = manual code entry only'
  AFTER `is_active`;

UPDATE `settings` SET `setting_value` = '1.31.0-coupon-storefront-visible' WHERE `setting_key` = 'schema_version';
