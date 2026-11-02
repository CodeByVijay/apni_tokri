-- Stage 14.5 — Reports export permission and order report index

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('reports.export', 'Export reports (CSV)', 'reports')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `admin_roles` r
CROSS JOIN `admin_permissions` p
WHERE r.slug IN ('super_admin', 'finance') AND p.slug = 'reports.export';

SET @idx = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_placed_payment');
SET @sql = IF(@idx = 0,
  'ALTER TABLE `orders` ADD KEY `idx_orders_placed_payment` (`placed_at`, `payment_status`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `settings` SET `setting_value` = '1.14.0-stage14-reports-5' WHERE `setting_key` = 'schema_version';
