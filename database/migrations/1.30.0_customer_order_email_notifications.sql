-- Customer order/payment/refund email templates (Cashfree + Razorpay).
-- Safe to re-run: INSERT ... ON DUPLICATE KEY UPDATE.

INSERT INTO `notification_templates`
  (`template_key`, `event_type`, `channel`, `subject`, `body_html`, `body_text`, `sms_body`, `in_app_title`, `in_app_body`, `is_active`)
VALUES
  ('order.placed.email', 'order.placed', 'email', 'Order {{order_number}} — complete payment',
   '<p>Hi {{customer_name}},</p><p>Thank you for shopping with {{site_name}}. Your order <strong>{{order_number}}</strong> is placed and awaiting payment of <strong>₹{{amount}}</strong>.</p><p>Complete payment securely via {{gateway_name}}:</p><p><a href="{{payment_retry_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">Pay now</a></p><p style="font-size:13px;color:#64748b;">You can also view your order anytime from <a href="{{order_url}}">order details</a>.</p>',
   'Hi {{customer_name}}, order {{order_number}} (Rs {{amount}}) is awaiting payment. Pay at {{payment_retry_url}} or view {{order_url}}.',
   NULL, NULL, NULL, 1),
  ('order.status.email', 'order.status', 'email', 'Order {{order_number}} — {{status_label}}',
   '<p>Hi {{customer_name}},</p><p>Your order <strong>{{order_number}}</strong> is now: <strong>{{status_label}}</strong>.</p><p>Amount: ₹{{amount}}</p><p><a href="{{order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">View order</a></p>',
   'Order {{order_number}} status: {{status_label}}. View: {{order_url}}',
   NULL, NULL, NULL, 1),
  ('payment.success.email', 'payment.success', 'email', 'Payment received — order {{order_number}}',
   '<p>Hi {{customer_name}},</p><p>We received your payment of <strong>₹{{amount}}</strong> for order <strong>{{order_number}}</strong> via {{gateway_name}}.</p><p>Payment reference: <code>{{payment_reference}}</code></p><p><a href="{{order_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">View order</a></p>',
   'Payment of Rs {{amount}} received for order {{order_number}} via {{gateway_name}}. Ref: {{payment_reference}}. {{order_url}}',
   NULL, NULL, NULL, 1),
  ('payment.failed.email', 'payment.failed', 'email', 'Payment issue — order {{order_number}}',
   '<p>Hi {{customer_name}},</p><p>Your payment for order <strong>{{order_number}}</strong> (₹{{amount}}) via {{gateway_name}} could not be completed.</p><p>You can try again securely:</p><p><a href="{{payment_retry_url}}" style="display:inline-block;background:#2d7048;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600;">Retry payment</a></p><p style="font-size:13px;color:#64748b;">Need help? View <a href="{{order_url}}">order details</a>.</p>',
   'Payment for order {{order_number}} (Rs {{amount}}) failed. Retry: {{payment_retry_url}}',
   NULL, NULL, NULL, 1),
  ('refund.initiated.email', 'refund.initiated', 'email', 'Refund initiated — {{request_number}}',
   '<p>Hi {{customer_name}},</p><p>We have initiated your refund <strong>{{request_number}}</strong> for order <strong>{{order_number}}</strong>{{amount_line}}.</p><p>Status: {{status_label}}</p><p style="font-size:13px;color:#64748b;">Refunds typically appear in your account within 5–7 business days depending on your bank.</p>',
   'Refund {{request_number}} for order {{order_number}} has been initiated. Status: {{status_label}}.',
   NULL, NULL, NULL, 1),
  ('refund.completed.email', 'refund.completed', 'email', 'Refund completed — {{request_number}}',
   '<p>Hi {{customer_name}},</p><p>Your refund <strong>{{request_number}}</strong> for order <strong>{{order_number}}</strong> is complete{{amount_line}}.</p><p>If you do not see the amount yet, please allow a few days for your bank to post it.</p>',
   'Refund {{request_number}} for order {{order_number}} is complete.',
   NULL, NULL, NULL, 1),
  ('refund.status_updated.email', 'refund.status_updated', 'email', 'Refund update — {{request_number}}',
   '<p>Hi {{customer_name}},</p><p>Your refund request <strong>{{request_number}}</strong> for order <strong>{{order_number}}</strong> status is now: <strong>{{status_label}}</strong>.</p>',
   'Refund {{request_number}} (order {{order_number}}) status: {{status_label}}.',
   NULL, NULL, NULL, 1)
ON DUPLICATE KEY UPDATE
  `event_type` = VALUES(`event_type`),
  `subject` = VALUES(`subject`),
  `body_html` = VALUES(`body_html`),
  `body_text` = VALUES(`body_text`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.30.0-customer-order-email-notifications' WHERE `setting_key` = 'schema_version';
