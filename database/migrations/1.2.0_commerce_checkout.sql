-- Apni Tokri schema 1.2.0 — cart coupon, order checkout fields, seed coupons (Stage 7)

SET NAMES utf8mb4;

ALTER TABLE `carts` ADD COLUMN `coupon_code` VARCHAR(64) NULL AFTER `session_id`;

ALTER TABLE `orders`
  ADD COLUMN `customer_address_id` BIGINT UNSIGNED NULL AFTER `customer_id`,
  ADD COLUMN `delivery_slot` VARCHAR(32) NULL AFTER `payment_status`,
  ADD COLUMN `coupon_code` VARCHAR(64) NULL AFTER `delivery_slot`,
  ADD COLUMN `checkout_nonce` VARCHAR(64) NULL AFTER `coupon_code`;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer_address` FOREIGN KEY (`customer_address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL;

INSERT INTO `coupons` (`code`, `discount_type`, `discount_value`, `min_cart_value`, `max_discount`, `usage_limit`, `is_active`, `starts_at`)
VALUES
  ('WELCOME10', 'percent', 10.00, 100.00, 200.00, NULL, 1, NOW()),
  ('SAVE50', 'fixed', 50.00, 200.00, NULL, NULL, 1, NOW())
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.2.0' WHERE `setting_key` = 'schema_version';
