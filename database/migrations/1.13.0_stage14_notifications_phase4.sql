-- Stage 14.4 — Notification queue expansion, templates, admin permission
--
-- Apply once on a backup of production. Safe to re-run only where noted (INSERT ... ON DUPLICATE).
-- Preserves existing notification_queue rows; adds columns and indexes (no TRUNCATE).
-- Prerequisites: 1.11.0 (SMTP), 1.12.0 (SMS). See docs/stage14-notifications-migration.md.

ALTER TABLE `notification_queue`
  MODIFY COLUMN `channel` ENUM('email','sms','in_app') NOT NULL,
  ADD COLUMN `event_type` VARCHAR(64) NOT NULL DEFAULT 'generic' AFTER `channel`,
  ADD COLUMN `recipient_ref` VARCHAR(64) NULL AFTER `recipient`,
  ADD COLUMN `idempotency_key` VARCHAR(191) NULL AFTER `payload_json`,
  ADD COLUMN `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER `attempts`,
  ADD COLUMN `next_retry_at` TIMESTAMP NULL DEFAULT NULL AFTER `scheduled_at`,
  ADD COLUMN `error_code` VARCHAR(64) NULL AFTER `next_retry_at`,
  ADD COLUMN `error_message` VARCHAR(255) NULL AFTER `error_code`,
  ADD COLUMN `lease_token` VARCHAR(64) NULL AFTER `error_message`,
  ADD COLUMN `lease_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `lease_token`,
  ADD COLUMN `processing_started_at` TIMESTAMP NULL DEFAULT NULL AFTER `lease_expires_at`,
  ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
  ADD COLUMN `processed_at` TIMESTAMP NULL DEFAULT NULL AFTER `sent_at`;

ALTER TABLE `notification_queue`
  ADD UNIQUE KEY `uk_notification_idempotency` (`channel`, `idempotency_key`),
  ADD KEY `idx_notification_pending_retry` (`status`, `next_retry_at`, `scheduled_at`);

CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `channel` ENUM('email','sms','in_app') NOT NULL,
  `subject` VARCHAR(255) NULL,
  `body_html` TEXT NULL,
  `body_text` TEXT NULL,
  `sms_body` VARCHAR(512) NULL,
  `in_app_title` VARCHAR(191) NULL,
  `in_app_body` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_templates_key` (`template_key`),
  KEY `idx_notification_templates_event` (`event_type`, `channel`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('notifications.manage', 'Manage notification queue', 'notifications')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `admin_permissions` WHERE `slug` = 'notifications.manage';

INSERT INTO `notification_templates`
  (`template_key`, `event_type`, `channel`, `subject`, `body_html`, `body_text`, `sms_body`, `in_app_title`, `in_app_body`, `is_active`)
VALUES
  ('payment.success.email', 'payment.success', 'email', 'Payment received — order {{order_number}}',
   '<p>Hi {{customer_name}},</p><p>We received your payment of ₹{{amount}} for order <strong>{{order_number}}</strong>.</p>',
   'Hi {{customer_name}}, we received your payment of Rs {{amount}} for order {{order_number}}.',
   NULL, NULL, NULL, 1),
  ('payment.failed.email', 'payment.failed', 'email', 'Payment issue — order {{order_number}}',
   '<p>Hi {{customer_name}},</p><p>Your payment for order {{order_number}} could not be completed. You can retry from your account.</p>',
   'Payment for order {{order_number}} could not be completed. Please retry from your account.', NULL, NULL, NULL, 1),
  ('support.ticket_created.email', 'support.ticket_created', 'email', 'Support ticket {{ticket_number}} received',
   '<p>Hi {{customer_name}},</p><p>We received your support request ({{ticket_number}}). Our team will respond soon.</p>',
   'We received your support ticket {{ticket_number}}.', NULL, NULL, NULL, 1),
  ('support.ticket_replied.email', 'support.ticket_replied', 'email', 'Update on ticket {{ticket_number}}',
   '<p>Hi {{customer_name}},</p><p>There is a new reply on your support ticket {{ticket_number}}.</p>',
   'New reply on support ticket {{ticket_number}}.', NULL, NULL, NULL, 1),
  ('support.ticket_created.in_app', 'support.ticket_created', 'in_app', NULL, NULL, NULL, NULL,
   'Support ticket opened', 'Ticket {{ticket_number}} was created.', 1),
  ('refund.status_updated.email', 'refund.status_updated', 'email', 'Refund update — {{request_number}}',
   '<p>Hi {{customer_name}},</p><p>Your refund request {{request_number}} status is now: {{status_label}}.</p>',
   'Refund {{request_number}} status: {{status_label}}.', NULL, NULL, NULL, 1)
ON DUPLICATE KEY UPDATE
  `subject` = VALUES(`subject`),
  `body_html` = VALUES(`body_html`),
  `body_text` = VALUES(`body_text`),
  `sms_body` = VALUES(`sms_body`),
  `in_app_title` = VALUES(`in_app_title`),
  `in_app_body` = VALUES(`in_app_body`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.13.0-stage14-notifications-4' WHERE `setting_key` = 'schema_version';
