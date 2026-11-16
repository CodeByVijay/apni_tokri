-- Promotional coupon push template (placeholders populated from verified coupon + customer records).

INSERT INTO `push_notification_templates`
  (`template_key`, `event_type`, `title_template`, `body_template`, `click_url_template`, `is_active`)
VALUES
  (
    'coupon.promotional.push',
    'promotion.coupon',
    'A coupon just for you',
    'Hi {customer_name}, use code {coupon_code} for {discount_value}. Minimum order {minimum_order}. Valid till {expiry_date}.',
    '{click_url}',
    1
  )
ON DUPLICATE KEY UPDATE
  `event_type` = VALUES(`event_type`),
  `title_template` = VALUES(`title_template`),
  `body_template` = VALUES(`body_template`),
  `click_url_template` = VALUES(`click_url_template`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.39.3-push-coupon-template' WHERE `setting_key` = 'schema_version';
