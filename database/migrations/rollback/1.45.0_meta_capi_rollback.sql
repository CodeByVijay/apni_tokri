-- Rollback for 1.45.0_meta_capi.sql
-- Drops the CAPI outbox table (removes any queued/sent event records)
-- and restores the prior schema version marker.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

DROP TABLE IF EXISTS `meta_capi_events`;

UPDATE `settings` SET `setting_value` = '1.44.0-meta-ads' WHERE `setting_key` = 'schema_version';
