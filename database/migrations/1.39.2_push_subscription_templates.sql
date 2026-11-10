-- Additional push templates for subscription pause/resume (lifecycle events exist in code).

INSERT INTO `push_notification_templates`
  (`template_key`, `event_type`, `title_template`, `body_template`, `click_url_template`, `is_active`)
VALUES
  ('subscription.paused.push', 'subscription.paused', 'Subscription paused', 'Your subscription has been paused.', '/my-subscriptions.php', 1),
  ('subscription.resumed.push', 'subscription.resumed', 'Subscription resumed', 'Your subscription is active again.', '/my-subscriptions.php', 1)
ON DUPLICATE KEY UPDATE
  `title_template` = VALUES(`title_template`),
  `body_template` = VALUES(`body_template`),
  `click_url_template` = VALUES(`click_url_template`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.39.2-push-sub-templates' WHERE `setting_key` = 'schema_version';
