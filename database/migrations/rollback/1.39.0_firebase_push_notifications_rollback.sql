-- Rollback 1.39.0 Firebase push (destructive — drops push data)

DROP TABLE IF EXISTS `push_notification_logs`;
DROP TABLE IF EXISTS `device_push_registrations`;
DROP TABLE IF EXISTS `push_notification_templates`;
DROP TABLE IF EXISTS `firebase_projects`;

DELETE FROM `admin_role_permissions` WHERE `permission_id` IN (
  SELECT `id` FROM `admin_permissions` WHERE `slug` = 'firebase.manage'
);
DELETE FROM `admin_permissions` WHERE `slug` = 'firebase.manage';

UPDATE `settings` SET `setting_value` = '1.38.0-customer-remember' WHERE `setting_key` = 'schema_version';
