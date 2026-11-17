-- Rollback for 1.44.0_meta_ads.sql
-- Removes the meta.manage permission (and role bindings) plus all meta.* settings
-- rows, including any stored Conversions API access token.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

DELETE rp FROM `admin_role_permissions` rp
INNER JOIN `admin_permissions` p ON p.`id` = rp.`permission_id`
WHERE p.`slug` = 'meta.manage';

DELETE FROM `admin_permissions` WHERE `slug` = 'meta.manage';

DELETE FROM `settings` WHERE `setting_key` IN (
  'meta.pixel_enabled',
  'meta.pixel_id',
  'meta.capi_enabled',
  'meta.dataset_id',
  'meta.test_event_code',
  'meta.capi_access_token'
);

UPDATE `settings` SET `setting_value` = '1.43.0-otp-sms-security' WHERE `setting_key` = 'schema_version';
