DELETE FROM `settings` WHERE `setting_key` IN (
  'sms.startmessaging.variable_app_name',
  'sms.startmessaging.variable_expiry'
);

UPDATE `settings` SET `setting_value` = '1.21.0-security-phase1' WHERE `setting_key` = 'schema_version';
