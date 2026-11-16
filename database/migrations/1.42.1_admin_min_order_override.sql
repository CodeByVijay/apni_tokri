-- Admin-assisted minimum-order override + RBAC.
-- Safe to re-run: idempotent permission seeding only.

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('orders.override_min_order', 'Override minimum order value on assisted orders', 'orders')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `module` = VALUES(`module`);

INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug = 'orders.override_min_order'
WHERE r.slug IN ('super_admin', 'order_manager');

UPDATE `settings` SET `setting_value` = '1.42.1-admin-min-order-override' WHERE `setting_key` = 'schema_version';
