-- Geocoding provider usage metering (Phase 4).
-- One rollup row per provider+service+day; monthly totals are SUM() over day rows.
-- Rollback: DROP TABLE IF EXISTS geocoding_usage_daily;

CREATE TABLE IF NOT EXISTS geocoding_usage_daily (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    service VARCHAR(32) NOT NULL,
    day DATE NOT NULL,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    total_ms INT UNSIGNED NOT NULL DEFAULT 0,
    last_http SMALLINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_geo_usage (provider, service, day),
    KEY ix_geo_usage_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
