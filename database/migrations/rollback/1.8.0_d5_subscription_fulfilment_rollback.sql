-- Rollback D5 schema label only (does not revert fulfilment code)

SET NAMES utf8mb4;

UPDATE `settings` SET `setting_value` = '1.8.0-d4-hardening' WHERE `setting_key` = 'schema_version';
