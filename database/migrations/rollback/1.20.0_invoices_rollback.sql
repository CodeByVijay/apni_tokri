-- Rollback 1.20.0 invoices. WARNING: drops all stored invoice documents metadata and email logs.
-- PDF files under storage/invoices must be removed separately if desired.
DROP TABLE IF EXISTS `invoice_email_log`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `invoice_sequences`;

UPDATE `settings` SET `setting_value` = '1.19.0-address-map-pin' WHERE `setting_key` = 'schema_version';
