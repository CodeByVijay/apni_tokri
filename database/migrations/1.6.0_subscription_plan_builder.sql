-- Apni Tokri schema 1.6.0 — subscription plan builder (Stage 11 Phase A)

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `description` TEXT NULL,
  `internal_notes` TEXT NULL,
  `calendar_duration_days` INT UNSIGNED NOT NULL,
  `delivery_count` INT UNSIGNED NOT NULL,
  `duration_model` VARCHAR(32) NOT NULL DEFAULT 'hybrid',
  `frequency_type` VARCHAR(32) NOT NULL DEFAULT 'daily',
  `frequency_config` JSON NULL,
  `basket_rules_json` JSON NOT NULL,
  `price` DECIMAL(12,2) NOT NULL,
  `compare_at_price` DECIMAL(12,2) NULL,
  `payment_rules_json` JSON NULL,
  `skip_pause_policy_json` JSON NULL,
  `slot_policy_json` JSON NULL,
  `selection_cutoff_hours` INT UNSIGNED NOT NULL DEFAULT 18,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `is_default_template` TINYINT(1) NOT NULL DEFAULT 0,
  `template_key` VARCHAR(32) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscription_plans_template_key` (`template_key`),
  KEY `idx_subscription_plans_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_number` VARCHAR(32) NOT NULL,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `subscription_plan_id` INT UNSIGNED NULL,
  `plan_snapshot_json` JSON NOT NULL,
  `price_paid` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
  `start_date` DATE NULL,
  `calendar_end_date` DATE NULL,
  `deliveries_remaining` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_subscriptions_number` (`subscription_number`),
  KEY `idx_customer_subscriptions_customer` (`customer_id`, `status`),
  CONSTRAINT `fk_cs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cs_plan` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_plan_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscription_plan_id` INT UNSIGNED NOT NULL,
  `admin_user_id` INT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subscription_plan_audit_plan` (`subscription_plan_id`, `created_at`),
  CONSTRAINT `fk_spa_plan` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_selection_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_subscription_id` BIGINT UNSIGNED NOT NULL,
  `delivery_date` DATE NULL,
  `actor_type` VARCHAR(16) NOT NULL DEFAULT 'system',
  `actor_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(64) NOT NULL,
  `detail_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subscription_selection_audit_sub` (`customer_subscription_id`, `created_at`),
  CONSTRAINT `fk_ssa_sub` FOREIGN KEY (`customer_subscription_id`) REFERENCES `customer_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subscription_plans` (
  `name`, `description`, `calendar_duration_days`, `delivery_count`, `duration_model`, `frequency_type`,
  `frequency_config`, `basket_rules_json`, `price`, `compare_at_price`, `payment_rules_json`, `skip_pause_policy_json`,
  `slot_policy_json`, `selection_cutoff_hours`, `is_active`, `is_default_template`, `template_key`, `sort_order`
) VALUES
(
  'Taazi Sabzi — 7 deliveries',
  'Seven fresh vegetable deliveries within a 14-day calendar window. Suggested daily basket with optional customization.',
  14, 7, 'hybrid', 'daily',
  JSON_OBJECT('interval_days', 1),
  JSON_OBJECT(
    'daily_allowance_inr', 499,
    'max_line_items', 10,
    'unused_allowance_policy', 'expire',
    'over_allowance_requires_approval', true,
    'never_silent_charge', true
  ),
  999.00, 1199.00,
  JSON_OBJECT('prepaid_percent', 100),
  JSON_OBJECT('max_skips', 2, 'skip_cutoff_hours', 18, 'pause_allowed', 1, 'carry_forward_skips', 1),
  JSON_OBJECT('use_delivery_slots', 1),
  18, 1, 1, 'veg_7', 10
),
(
  'Taazi Sabzi — 15 deliveries',
  'Fifteen deliveries over a 30-day calendar. Ideal for regular families.',
  30, 15, 'hybrid', 'daily',
  JSON_OBJECT('interval_days', 1),
  JSON_OBJECT(
    'daily_allowance_inr', 499,
    'max_line_items', 10,
    'unused_allowance_policy', 'expire',
    'over_allowance_requires_approval', true,
    'never_silent_charge', true
  ),
  1899.00, 2199.00,
  JSON_OBJECT('prepaid_percent', 100),
  JSON_OBJECT('max_skips', 4, 'skip_cutoff_hours', 18, 'pause_allowed', 1, 'carry_forward_skips', 1),
  JSON_OBJECT('use_delivery_slots', 1),
  18, 1, 1, 'veg_15', 20
),
(
  'Taazi Sabzi — 30 deliveries',
  'Thirty deliveries within a 45-day calendar window.',
  45, 30, 'hybrid', 'daily',
  JSON_OBJECT('interval_days', 1),
  JSON_OBJECT(
    'daily_allowance_inr', 549,
    'max_line_items', 12,
    'unused_allowance_policy', 'carry_forward',
    'over_allowance_requires_approval', true,
    'never_silent_charge', true
  ),
  3499.00, 3999.00,
  JSON_OBJECT('prepaid_percent', 100),
  JSON_OBJECT('max_skips', 6, 'skip_cutoff_hours', 18, 'pause_allowed', 1, 'carry_forward_skips', 1),
  JSON_OBJECT('use_delivery_slots', 1),
  18, 1, 1, 'veg_30', 30
)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

UPDATE `settings` SET `setting_value` = '1.6.0' WHERE `setting_key` = 'schema_version';
