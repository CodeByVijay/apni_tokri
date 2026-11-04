-- Customer/admin order cancellation audit + idempotent storefront stock restoration.
-- Forward-only. Safe on existing databases (CREATE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `order_cancellation_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `reason_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_detail` text COLLATE utf8mb4_unicode_ci,
  `requested_by` enum('customer','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int unsigned DEFAULT NULL,
  `admin_user_id` int unsigned DEFAULT NULL,
  `idempotency_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_cancel_order` (`order_id`),
  UNIQUE KEY `uq_order_cancel_idem` (`idempotency_key`),
  KEY `idx_order_cancel_customer` (`customer_id`),
  CONSTRAINT `fk_order_cancel_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_stock_restock_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `idempotency_key` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_restock_order` (`order_id`),
  CONSTRAINT `fk_order_restock_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
