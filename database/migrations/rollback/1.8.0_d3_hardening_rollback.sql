DROP TABLE IF EXISTS `delivery_sms_send_locks`;

DELETE FROM `settings` WHERE `setting_key` IN (
  'sms.http.allowed_status_codes',
  'sms.http.require_json',
  'sms.http.success_json_path',
  'sms.http.success_json_value',
  'sms.http.message_id_json_path',
  'sms.http.timeout_seconds'
);

UPDATE `settings` SET `setting_value` = '1.8.0-d3-manual' WHERE `setting_key` = 'schema_version';
