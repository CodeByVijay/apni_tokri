-- Veggie Tokri schema 1.45.0 — Meta Conversions API outbox (Phase 3)
-- Depends on: 1.44.0
-- Rollback: see database/migrations/rollback/1.45.0_meta_capi_rollback.sql
--
-- Adds `meta_capi_events`, a durable outbox for server-side Meta Conversions
-- API events. Rows are created inside the PaymentService::completePayment()
-- transaction so a Purchase event exists atomically with orders.payment_status
-- = 'paid' — Meta availability is never on the payment critical path.
--
-- Status lifecycle: pending → processing (worker lease) → sent | failed
-- Retryable failures stay 'failed' with a future next_attempt_at; terminal
-- failures keep status='failed' with next_attempt_at NULL and attempts
-- exhausted. 'suppressed' marks unsent events blocked after an observed
-- marketing-consent withdrawal. The (gateway,event) lease pattern mirrors
-- payment_webhook_events but is intentionally a separate table — inbound
-- gateway dedup and outbound Meta delivery are different lifecycles.

SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS `meta_capi_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` varchar(191) NOT NULL COMMENT 'Deterministic vtpo_* id shared with browser Pixel dedup',
  `order_id` bigint unsigned NOT NULL,
  `order_number` varchar(32) NOT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `event_name` varchar(64) NOT NULL DEFAULT 'Purchase',
  `payload_json` json NOT NULL COMMENT 'Complete CAPI event object; user_data already hashed',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `max_attempts` int unsigned NOT NULL DEFAULT 6,
  `next_attempt_at` datetime DEFAULT NULL COMMENT 'Backoff scheduling; NULL = due now (or terminal when failed)',
  `processing_started_at` datetime DEFAULT NULL,
  `worker_token` varchar(64) DEFAULT NULL COMMENT 'Lease fencing — only the owning worker may finish',
  `last_error` varchar(255) DEFAULT NULL COMMENT 'Sanitized error text; never tokens or PII',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_meta_capi_event_id` (`event_id`),
  KEY `idx_meta_capi_status_next` (`status`, `next_attempt_at`),
  KEY `idx_meta_capi_order` (`order_id`),
  KEY `idx_meta_capi_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `settings` SET `setting_value` = '1.45.0-meta-capi' WHERE `setting_key` = 'schema_version';
