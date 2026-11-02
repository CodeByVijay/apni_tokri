<?php

declare(strict_types=1);

final class SeoRedirectRepository
{
    /** @return array<string, mixed>|null */
    public static function findByFromPath(string $fromPath): ?array
    {
        if (!Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, from_path, to_path, http_code FROM seo_redirects WHERE from_path = ? LIMIT 1'
        );
        $stmt->execute([$fromPath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function upsertPermanent(string $fromPath, string $toPath): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'INSERT INTO seo_redirects (from_path, to_path, http_code) VALUES (?, ?, 301)
             ON DUPLICATE KEY UPDATE to_path = VALUES(to_path), http_code = 301'
        )->execute([$fromPath, $toPath]);
    }

    public static function updateToPath(string $oldToPath, string $newToPath): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('UPDATE seo_redirects SET to_path = ? WHERE to_path = ?');
        $stmt->execute([$newToPath, $oldToPath]);

        return $stmt->rowCount();
    }

    public static function deleteByFromPrefix(string $prefix): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('DELETE FROM seo_redirects WHERE from_path LIKE ?')
            ->execute([$prefix . '%']);
    }

    /** @return list<string> */
    public static function listAllFromPaths(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query('SELECT from_path FROM seo_redirects');
        if ($stmt === false) {
            return [];
        }
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $p = (string) ($row['from_path'] ?? '');
            if ($p !== '') {
                $paths[] = $p;
            }
        }

        return $paths;
    }
}
