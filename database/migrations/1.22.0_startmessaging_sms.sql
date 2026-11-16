-- StartMessaging SMS provider defaults (settings EAV only — no schema DDL).
-- Live provider remains admin-configured; fresh installs stay on simulate until Live mode is enabled.
-- Do not seed API keys or template IDs here.

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('sms.startmessaging.variable_app_name', '1', 0),
  ('sms.startmessaging.variable_expiry', '0', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

UPDATE `settings` SET `setting_value` = '1.22.0-startmessaging-sms' WHERE `setting_key` = 'schema_version';
