-- Admin-assisted order creation: order metadata columns + RBAC permission.
-- Safe to re-run: conditional ADD COLUMN and idempotent permission seeding.

SET @col_created_by := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'created_by_admin_id'
);
SET @ddl_created_by := IF(
  @col_created_by = 0,
  'ALTER TABLE `orders` ADD COLUMN `created_by_admin_id` INT UNSIGNED NULL AFTER `customer_address_id`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_created_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_meta := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'admin_meta_json'
);
SET @ddl_meta := IF(
  @col_meta = 0,
  'ALTER TABLE `orders` ADD COLUMN `admin_meta_json` JSON NULL AFTER `delivery_snapshot_json`',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_meta;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND CONSTRAINT_NAME = 'fk_orders_created_by_admin'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @ddl_fk := IF(
  @fk_exists = 0,
  'ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_created_by_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('orders.create_on_behalf', 'Create orders on behalf of customers', 'orders')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `module` = VALUES(`module`);

INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug = 'orders.create_on_behalf'
WHERE r.slug IN ('super_admin', 'order_manager');

UPDATE `settings` SET `setting_value` = '1.40.0-admin-assisted-orders' WHERE `setting_key` = 'schema_version';
