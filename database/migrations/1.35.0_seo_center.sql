-- SEO Management Center: optional 404 request log (admin review). Idempotent.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `seo_not_found_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_path` VARCHAR(512) NOT NULL,
  `path_hash` CHAR(40) NOT NULL,
  `hit_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `referrer_host` VARCHAR(191) NULL DEFAULT NULL,
  `ignored_at` TIMESTAMP NULL DEFAULT NULL,
  `suggested_redirect` VARCHAR(512) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seo_nf_path_hash` (`path_hash`),
  KEY `idx_seo_nf_last_seen` (`last_seen_at`),
  KEY `idx_seo_nf_ignored` (`ignored_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
