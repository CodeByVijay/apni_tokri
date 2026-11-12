-- Admin-assisted offline payment recording + RBAC.
-- Safe to re-run: idempotent permission seeding only.

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('orders.record_offline_payment', 'Record offline payment on assisted orders', 'orders')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `module` = VALUES(`module`);

INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `admin_roles` r
INNER JOIN `admin_permissions` p ON p.slug = 'orders.record_offline_payment'
WHERE r.slug IN ('super_admin', 'order_manager');

UPDATE `settings` SET `setting_value` = '1.41.0-admin-assisted-offline' WHERE `setting_key` = 'schema_version';
