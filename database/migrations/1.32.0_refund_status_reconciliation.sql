-- Refund gateway status reconciliation (cron tracking + lease).
-- Safe to re-run.

SET @db = DATABASE();

SET @has_last_checked = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payment_refunds' AND COLUMN_NAME = 'last_status_checked_at'
);
SET @sql = IF(@has_last_checked = 0,
  'ALTER TABLE `payment_refunds`
     ADD COLUMN `last_status_checked_at` DATETIME NULL DEFAULT NULL AFTER `completed_at`,
     ADD COLUMN `status_check_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_status_checked_at`,
     ADD COLUMN `next_status_check_at` DATETIME NULL DEFAULT NULL AFTER `status_check_attempts`,
     ADD COLUMN `last_status_check_source` VARCHAR(16) NULL DEFAULT NULL AFTER `next_status_check_at`,
     ADD COLUMN `last_status_check_error` VARCHAR(255) NULL DEFAULT NULL AFTER `last_status_check_source`,
     ADD COLUMN `reconcile_lease_token` VARCHAR(32) NULL DEFAULT NULL AFTER `last_status_check_error`,
     ADD COLUMN `reconcile_lease_expires_at` DATETIME NULL DEFAULT NULL AFTER `reconcile_lease_token`,
     ADD KEY `idx_payment_refunds_reconcile` (`completed_at`, `next_status_check_at`, `gateway_status`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `notification_templates`
SET
  `subject` = 'Your Veggie Tokri refund has been processed – Order #{{order_number}}',
  `body_html` = '<p>Hi {{customer_name}},</p><p>Your refund of <strong>{{amount_line}}</strong> for order <strong>{{order_number}}</strong> has been processed by {{gateway_name}}.</p><p>Refund reference: <strong>{{refund_reference}}</strong><br>Completed: {{completion_date}}</p><p><a href="{{order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">View order</a></p><p style="font-size:13px;color:#64748b;">Bank posting times vary by issuer; we do not control settlement speed.</p>',
  `body_text` = 'Hi {{customer_name}}, refund {{amount_line}} for order {{order_number}} is complete ({{gateway_name}}). Reference: {{refund_reference}}. View: {{order_url}}'
WHERE `template_key` = 'refund.completed.email';

UPDATE `settings` SET `setting_value` = '1.32.0-refund-status-reconciliation' WHERE `setting_key` = 'schema_version';
