<?php

declare(strict_types=1);

/**
 * Records public HTML 404 paths for admin review (no query strings with secrets).
 */
final class SeoNotFoundLog
{
    public const MAX_PATH_LEN = 512;
    private const MAX_REFERRER_HOST = 191;

    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            return Database::pdo()->query("SHOW TABLES LIKE 'seo_not_found_log'")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function recordCurrentRequest(): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $ref = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
        self::recordPath($uri, $ref);
    }

    public static function recordPath(string $requestPath, ?string $referrer = null): void
    {
        if (!self::tableReady() || PHP_SAPI === 'cli') {
            return;
        }
        $path = self::sanitizePath($requestPath);
        if ($path === null) {
            return;
        }
        $hash = sha1($path);
        $refHost = self::referrerHost($referrer);
        try {
            $pdo = Database::pdo();
            $pdo->prepare(
                'INSERT INTO seo_not_found_log (request_path, path_hash, hit_count, referrer_host)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                   hit_count = hit_count + 1,
                   last_seen_at = CURRENT_TIMESTAMP,
                   referrer_host = COALESCE(VALUES(referrer_host), referrer_host)'
            )->execute([$path, $hash, $refHost]);
        } catch (Throwable $e) {
            error_log('SeoNotFoundLog::recordPath: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForAdmin(int $limit = 50, bool $includeIgnored = false): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $sql = 'SELECT * FROM seo_not_found_log';
        if (!$includeIgnored) {
            $sql .= ' WHERE ignored_at IS NULL';
        }
        $sql .= ' ORDER BY last_seen_at DESC LIMIT ' . max(1, min(200, $limit));
        $stmt = Database::pdo()->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function ignore(int $id): bool
    {
        if (!self::tableReady() || $id <= 0) {
            return false;
        }
        $stmt = Database::pdo()->prepare('UPDATE seo_not_found_log SET ignored_at = NOW() WHERE id = ? AND ignored_at IS NULL');

        return $stmt->execute([$id]) && $stmt->rowCount() > 0;
    }

    public static function countActive(): int
    {
        if (!self::tableReady()) {
            return 0;
        }

        return (int) Database::pdo()->query('SELECT COUNT(*) FROM seo_not_found_log WHERE ignored_at IS NULL')->fetchColumn();
    }

    private static function sanitizePath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > self::MAX_PATH_LEN) {
            return null;
        }
        $lower = strtolower($raw);
        foreach (['/admin', '/includes', '/storage', '/database', '/scripts', '/webhooks', '/cron'] as $deny) {
            if (str_starts_with($lower, $deny)) {
                return null;
            }
        }
        if (str_contains($raw, '..')) {
            return null;
        }
        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $raw;
        }

        return $path;
    }

    private static function referrerHost(?string $referrer): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);

        return strlen($host) <= self::MAX_REFERRER_HOST ? $host : substr($host, 0, self::MAX_REFERRER_HOST);
    }
}
