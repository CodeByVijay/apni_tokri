-- D5: subscription fulfilment uses D4 delivery quote + snapshot (version bump only)
-- Depends on: 1.8.0-d4-hardening

SET NAMES utf8mb4;
SET time_zone = '+05:30';

UPDATE `settings` SET `setting_value` = '1.8.0-d5-subscription' WHERE `setting_key` = 'schema_version';
