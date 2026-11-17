-- Veggie Tokri schema 1.44.0 — Meta Ads foundation (Phase 1)
-- Depends on: 1.43.0
-- Rollback: see database/migrations/rollback/1.44.0_meta_ads_rollback.sql
--
-- Adds the `meta.manage` admin permission for the new Marketing → Meta Ads
-- module and seeds disabled-by-default Meta integration settings (non-secret
-- values only; the Conversions API access token is never seeded — it is stored
-- encrypted via AppSettings when an admin saves it).
-- Safe to re-run: permission/settings inserts are idempotent and never
-- overwrite admin-configured values.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('meta.manage', 'Manage Meta Ads integration', 'meta')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `module` = VALUES(`module`);

-- Grant to the system super-admin role (role_id 1), matching prior migrations.
INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `admin_permissions` WHERE `slug` = 'meta.manage';

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('meta.pixel_enabled', '0', 0),
  ('meta.pixel_id', NULL, 0),
  ('meta.capi_enabled', '0', 0),
  ('meta.dataset_id', NULL, 0),
  ('meta.test_event_code', NULL, 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

UPDATE `settings` SET `setting_value` = '1.44.0-meta-ads' WHERE `setting_key` = 'schema_version';
