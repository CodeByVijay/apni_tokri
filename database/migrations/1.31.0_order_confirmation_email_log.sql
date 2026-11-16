-- Order confirmation email: delivery email log + richer payment.success template.
-- Safe to re-run where noted.

CREATE TABLE IF NOT EXISTS `delivery_email_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `event_key` VARCHAR(32) NOT NULL,
  `template_key` VARCHAR(64) NOT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `email_masked` VARCHAR(255) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `queue_id` BIGINT UNSIGNED NULL,
  `error_message` VARCHAR(255) NULL,
  `attempt_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_del_email_delivery_event` (`delivery_id`, `event_key`),
  KEY `idx_del_email_order` (`order_id`, `event_key`),
  KEY `idx_del_email_status` (`status`, `created_at`),
  CONSTRAINT `fk_del_email_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_del_email_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notification_templates`
  (`template_key`, `event_type`, `channel`, `subject`, `body_html`, `body_text`, `is_active`)
VALUES
  ('payment.success.email', 'payment.success', 'email', 'Order confirmed — {{order_number}}',
   '<p>Hi {{customer_name}},</p><p>Thank you for your order with <strong>{{site_name}}</strong>. Your payment of <strong>₹{{amount}}</strong> is confirmed.</p>{{order_summary_html}}<p style="margin:16px 0 8px;"><strong>Delivery</strong><br>{{delivery_address}}<br>{{delivery_slot_line}}</p>{{order_items_html}}<p style="margin:20px 0;"><a href="{{track_order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">Track order</a></p><p style="font-size:13px;color:#64748b;">Payment via {{gateway_name}}. Reference: {{payment_reference}}</p>',
   'Hi {{customer_name}}, order {{order_number}} is confirmed. Total Rs {{amount}}. {{order_items_text}} Delivery: {{delivery_address}} ({{delivery_slot_line}}). Track: {{track_order_url}}',
   1)
ON DUPLICATE KEY UPDATE
  `subject` = VALUES(`subject`),
  `body_html` = VALUES(`body_html`),
  `body_text` = VALUES(`body_text`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.31.0-order-confirmation-email-log' WHERE `setting_key` = 'schema_version';
