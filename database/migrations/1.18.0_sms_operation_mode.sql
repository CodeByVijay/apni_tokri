-- SMS operation mode (test / live / disabled) — defaults to disabled (safe).

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('sms.operation_mode', 'disabled', 0),
  ('sms.test.fixed_otp', '', 0)
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

UPDATE `settings` SET `setting_value` = '1.18.0-sms-operation-mode' WHERE `setting_key` = 'schema_version';
