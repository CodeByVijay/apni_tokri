-- Firebase Cloud Messaging (FCM) — projects, device tokens, templates, logs
-- Prerequisites: settings.schema_version at 1.38.0 or later.
-- Safe to re-run: CREATE IF NOT EXISTS / INSERT permissions ON DUPLICATE.

CREATE TABLE IF NOT EXISTS `firebase_projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `firebase_project_id` VARCHAR(128) NOT NULL,
  `firebase_project_number` VARCHAR(32) NULL,
  `status` ENUM('draft','inactive','active') NOT NULL DEFAULT 'draft',
  `is_active_global` TINYINT(1) NOT NULL DEFAULT 0,
  `web_api_key` VARCHAR(128) NULL,
  `web_app_id` VARCHAR(191) NULL,
  `web_auth_domain` VARCHAR(191) NULL,
  `web_storage_bucket` VARCHAR(191) NULL,
  `web_measurement_id` VARCHAR(64) NULL,
  `vapid_public_key` VARCHAR(512) NULL,
  `android_app_id` VARCHAR(191) NULL,
  `android_package_name` VARCHAR(191) NULL,
  `service_account_encrypted` MEDIUMTEXT NULL,
  `service_account_client_email` VARCHAR(191) NULL,
  `credential_fingerprint` CHAR(64) NULL,
  `last_test_status` ENUM('unknown','passed','failed') NOT NULL DEFAULT 'unknown',
  `last_test_at` TIMESTAMP NULL DEFAULT NULL,
  `last_test_message` VARCHAR(255) NULL,
  `platform_notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_firebase_projects_project_id` (`firebase_project_id`),
  KEY `idx_firebase_projects_active` (`is_active_global`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_push_registrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NULL,
  `firebase_project_id` INT UNSIGNED NOT NULL,
  `platform` ENUM('web','android') NOT NULL,
  `target_type` ENUM('fid','fcm_token') NOT NULL DEFAULT 'fcm_token',
  `fcm_token` VARCHAR(512) NOT NULL COMMENT 'Web: Firebase Installation ID; Android: FCM token',
  `device_key` CHAR(64) NOT NULL,
  `user_agent` VARCHAR(255) NULL,
  `app_version` VARCHAR(64) NULL,
  `permission_status` ENUM('granted','denied','default','unknown') NOT NULL DEFAULT 'unknown',
  `status` ENUM('active','revoked','invalid') NOT NULL DEFAULT 'active',
  `last_refresh_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_push_token` (`firebase_project_id`, `platform`, `fcm_token`(191)),
  KEY `idx_device_push_customer` (`customer_id`, `status`),
  KEY `idx_device_push_device_key` (`device_key`, `status`),
  CONSTRAINT `fk_device_push_firebase_project` FOREIGN KEY (`firebase_project_id`)
    REFERENCES `firebase_projects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_device_push_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_notification_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `title_template` VARCHAR(255) NOT NULL,
  `body_template` VARCHAR(512) NOT NULL,
  `click_url_template` VARCHAR(512) NULL,
  `image_url_template` VARCHAR(512) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_push_templates_key` (`template_key`),
  KEY `idx_push_templates_event` (`event_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_notification_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` CHAR(36) NOT NULL,
  `firebase_project_id` INT UNSIGNED NULL,
  `platform` ENUM('web','android','mixed') NOT NULL DEFAULT 'mixed',
  `customer_id` BIGINT UNSIGNED NULL,
  `device_registration_id` INT UNSIGNED NULL,
  `notification_type` VARCHAR(64) NOT NULL,
  `status` ENUM('pending','sent','failed','skipped','dry_run') NOT NULL DEFAULT 'pending',
  `http_status` SMALLINT UNSIGNED NULL,
  `failure_category` VARCHAR(64) NULL,
  `provider_message_id` VARCHAR(191) NULL,
  `idempotency_key` VARCHAR(191) NOT NULL,
  `title_redacted` VARCHAR(191) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_push_log_idempotency` (`idempotency_key`),
  KEY `idx_push_log_customer` (`customer_id`, `created_at`),
  KEY `idx_push_log_project` (`firebase_project_id`, `created_at`),
  CONSTRAINT `fk_push_log_firebase_project` FOREIGN KEY (`firebase_project_id`)
    REFERENCES `firebase_projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin_permissions` (`slug`, `name`, `module`) VALUES
  ('firebase.manage', 'Manage Firebase projects and push notifications', 'firebase')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `module` = VALUES(`module`);

INSERT IGNORE INTO `admin_role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `admin_permissions` WHERE `slug` = 'firebase.manage';

INSERT INTO `push_notification_templates`
  (`template_key`, `event_type`, `title_template`, `body_template`, `click_url_template`, `is_active`)
VALUES
  ('order.confirmed.push', 'payment.success', 'Order confirmed', 'Hi {customer_name}, order {order_number} is confirmed. Total {order_total}.', '/order-details.php?id={order_id}', 1),
  ('order.processing.push', 'order.status', 'Order update', 'Order {order_number} is now {status_label}.', '/order-details.php?id={order_id}', 1),
  ('order.dispatched.push', 'order.status', 'Order dispatched', 'Order {order_number} has been dispatched.', '/order-details.php?id={order_id}', 1),
  ('order.out_for_delivery.push', 'order.status', 'Out for delivery', 'Order {order_number} is out for delivery.', '/order-details.php?id={order_id}', 1),
  ('order.delivered.push', 'order.status', 'Delivered', 'Order {order_number} was delivered. Thank you!', '/order-details.php?id={order_id}', 1),
  ('order.cancelled.push', 'order.status', 'Order cancelled', 'Order {order_number} was cancelled.', '/order-details.php?id={order_id}', 1),
  ('refund.status.push', 'refund.status_updated', 'Refund update', 'Refund {request_number}: {status_label}.', '/my-orders.php', 1),
  ('subscription.activated.push', 'subscription.activated', 'Subscription active', 'Your subscription is now active.', '/my-account.php', 1),
  ('announcement.general.push', 'announcement', '{title}', '{body}', '/', 1),
  ('offer.promo.push', 'promotion', '{title}', '{body}', '{click_url}', 1)
ON DUPLICATE KEY UPDATE
  `title_template` = VALUES(`title_template`),
  `body_template` = VALUES(`body_template`),
  `click_url_template` = VALUES(`click_url_template`),
  `is_active` = VALUES(`is_active`);

UPDATE `settings` SET `setting_value` = '1.39.0-firebase-push' WHERE `setting_key` = 'schema_version';
