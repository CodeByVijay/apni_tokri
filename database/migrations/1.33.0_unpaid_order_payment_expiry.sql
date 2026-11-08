-- Unpaid storefront order payment expiry (auto-cancel cron).
-- Safe to re-run: ADD COLUMN only if missing (manual check); ALTER enum idempotent on MySQL 8+.

ALTER TABLE `orders`
  ADD COLUMN `payment_expires_at` DATETIME NULL DEFAULT NULL AFTER `placed_at`;

ALTER TABLE `orders`
  ADD KEY `idx_orders_unpaid_payment_expiry` (`status`, `payment_status`, `payment_expires_at`);

ALTER TABLE `order_cancellation_events`
  MODIFY COLUMN `requested_by` ENUM('customer','admin','system') NOT NULL;

UPDATE `orders`
SET `payment_expires_at` = DATE_ADD(COALESCE(`placed_at`, `created_at`), INTERVAL 15 MINUTE)
WHERE `payment_expires_at` IS NULL
  AND `status` = 'pending_payment'
  AND `payment_status` IN ('pending', 'failed');

INSERT INTO `notification_templates`
  (`template_key`, `event_type`, `channel`, `subject`, `body_html`, `body_text`, `sms_body`, `in_app_title`, `in_app_body`, `is_active`)
VALUES
  ('order.payment_timeout_cancelled.email', 'order.payment_timeout_cancelled', 'email',
   'Order {{order_number}} cancelled — payment not received',
   '<p>Hi {{customer_name}},</p><p>Your order <strong>{{order_number}}</strong> was cancelled because payment was not completed within the allowed time.</p><p>{{cancellation_reason}}</p><p>Amount: ₹{{amount}}</p><p>If you still want these items, you can place a new order anytime.</p><p><a href="{{order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">View order</a></p>',
   'Order {{order_number}} was cancelled: payment was not completed in time. {{cancellation_reason}} View: {{order_url}}',
   NULL, NULL, NULL, 1)
ON DUPLICATE KEY UPDATE
  `event_type` = VALUES(`event_type`),
  `channel` = VALUES(`channel`),
  `subject` = VALUES(`subject`),
  `body_html` = VALUES(`body_html`),
  `body_text` = VALUES(`body_text`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.33.0-unpaid-order-expiry' WHERE `setting_key` = 'schema_version';
