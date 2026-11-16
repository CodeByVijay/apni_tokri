<?php

declare(strict_types=1);

/**
 * Geocoding provider usage metering + quota enforcement (Phase 4).
 * Rollup rows in `geocoding_usage_daily` (migration 1.43.0_geocoding_usage.sql).
 * Only provider-level external calls are recorded — never queries or addresses.
 */
final class GeocodingUsageMeter
{
    private static $tableReady;

    public static function tableReady(): bool
    {
        if (self::$tableReady === null) {
            self::$tableReady = false;
            try {
                Install::ensureConnection();
                $st = Database::pdo()->query("SHOW TABLES LIKE 'geocoding_usage_daily'");
                self::$tableReady = $st !== false && $st->fetchColumn() !== false;
            } catch (Throwable $e) {
                self::$tableReady = false;
            }
        }

        return self::$tableReady;
    }

    public static function resetTableCheck(): void
    {
        self::$tableReady = null;
    }

    /** Record one external provider call. */
    public static function record(string $provider, string $service, bool $ok, ?int $httpStatus, int $ms): void
    {
        if (!self::tableReady()) {
            return;
        }
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO geocoding_usage_daily (provider, service, day, requests, failures, total_ms, last_http, updated_at)
                 VALUES (?, ?, CURDATE(), 1, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    requests = requests + 1,
                    failures = failures + VALUES(failures),
                    total_ms = total_ms + VALUES(total_ms),
                    last_http = VALUES(last_http),
                    updated_at = NOW()'
            );
            $stmt->execute([$provider, $service, $ok ? 0 : 1, max(0, $ms), $httpStatus]);
        } catch (Throwable $e) {
            error_log('GeocodingUsageMeter::record failed: ' . $e->getMessage());
        }
    }

    /** Used request counts for a provider across all services. @return array{daily:int,monthly:int} */
    public static function used(string $provider): array
    {
        $out = ['daily' => 0, 'monthly' => 0];
        if (!self::tableReady()) {
            return $out;
        }
        try {
            $pdo = Database::pdo();
            $st = $pdo->prepare('SELECT COALESCE(SUM(requests),0) FROM geocoding_usage_daily WHERE provider = ? AND day = CURDATE()');
            $st->execute([$provider]);
            $out['daily'] = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COALESCE(SUM(requests),0) FROM geocoding_usage_daily WHERE provider = ? AND DATE_FORMAT(day, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
            $st->execute([$provider]);
            $out['monthly'] = (int) $st->fetchColumn();
        } catch (Throwable $e) {
            error_log('GeocodingUsageMeter::used failed: ' . $e->getMessage());
        }

        return $out;
    }

    /** Server-side quota gate: true when provider is under its configured limits. */
    public static function quotaAllow(string $provider): bool
    {
        $q = GeoProviderSettings::quota($provider);
        if ($q['daily'] === 0 && $q['monthly'] === 0) {
            return true;
        }
        $u = self::used($provider);

        return ($q['daily'] === 0 || $u['daily'] < $q['daily'])
            && ($q['monthly'] === 0 || $u['monthly'] < $q['monthly']);
    }

    /** Full availability check used by the router before attempting a provider. */
    public static function providerAvailable(string $provider): bool
    {
        return GeoProviderSettings::providerUsable($provider) && self::quotaAllow($provider);
    }

    /** Admin dashboard rows: today + this month per provider/service. */
    public static function dashboardSummary(): array
    {
        $rows = [];
        if (!self::tableReady()) {
            return $rows;
        }
        try {
            $st = Database::pdo()->query(
                "SELECT provider, service,
                        SUM(CASE WHEN day = CURDATE() THEN requests ELSE 0 END) AS today_requests,
                        SUM(CASE WHEN day = CURDATE() THEN failures ELSE 0 END) AS today_failures,
                        SUM(requests) AS month_requests,
                        SUM(failures) AS month_failures,
                        SUM(total_ms) AS month_ms,
                        MAX(last_http) AS last_http,
                        MAX(updated_at) AS last_call
                 FROM geocoding_usage_daily
                 WHERE DATE_FORMAT(day, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                 GROUP BY provider, service ORDER BY provider, service"
            );
            $rows = $st !== false ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            error_log('GeocodingUsageMeter::dashboardSummary failed: ' . $e->getMessage());
        }

        return $rows;
    }
}
