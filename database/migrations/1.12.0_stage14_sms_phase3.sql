-- Stage 14.3 — SMS admin settings & OTP integration (existing `settings` table)

INSERT INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
  ('sms.template.otp', 'Your Apni Tokri login code is {otp}. Valid for {minutes} minutes. Do not share it.', 0)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

UPDATE `settings` SET `setting_value` = '1.12.0-stage14-sms-3' WHERE `setting_key` = 'schema_version';
