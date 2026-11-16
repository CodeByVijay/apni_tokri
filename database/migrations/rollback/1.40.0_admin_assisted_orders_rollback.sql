-- Rollback 1.40.0_admin_assisted_orders (manual / staging only).

DELETE FROM `admin_role_permissions` WHERE `permission_id` IN (
  SELECT `id` FROM `admin_permissions` WHERE `slug` = 'orders.create_on_behalf'
);
DELETE FROM `admin_permissions` WHERE `slug` = 'orders.create_on_behalf';

ALTER TABLE `orders` DROP FOREIGN KEY `fk_orders_created_by_admin`;
ALTER TABLE `orders` DROP COLUMN `admin_meta_json`;
ALTER TABLE `orders` DROP COLUMN `created_by_admin_id`;
