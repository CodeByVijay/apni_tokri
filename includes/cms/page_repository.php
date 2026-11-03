<?php

declare(strict_types=1);

final class CmsPageRepository
{
    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM cms_pages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM cms_pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public static function listForAdmin(int $page, int $perPage): array
    {
        return self::searchForAdmin('', null, null, $page, $perPage);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function searchForAdmin(
        string $query,
        ?string $status,
        ?string $visibility,
        int $page,
        int $perPage
    ): array {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;
        $where = ['1=1'];
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $where[] = '(title LIKE ? OR slug LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== null && $status !== '' && in_array($status, [PublishStatus::DRAFT, PublishStatus::PUBLISHED], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($visibility !== null && $visibility !== '' && in_array($visibility, ['public', 'private'], true)) {
            $where[] = 'visibility = ?';
            $params[] = $visibility;
        }
        $whereSql = implode(' AND ', $where);
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM cms_pages WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $sql = 'SELECT id, title, slug, status, visibility, show_in_footer, published_at, updated_at
                FROM cms_pages WHERE ' . $whereSql . ' ORDER BY updated_at DESC LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public static function slugTaken(string $slug, ?int $excludeId = null): bool
    {
        Install::ensureConnection();
        $sql = 'SELECT COUNT(*) FROM cms_pages WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $slug = CatalogueSlug::fromName($base);
        if (!Validation::slug($slug)) {
            $slug = 'page';
        }
        $candidate = $slug;
        $n = 0;
        while (true) {
            $sql = 'SELECT COUNT(*) FROM cms_pages WHERE slug = ?';
            $params = [$candidate];
            if ($excludeId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $excludeId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $n++;
            $candidate = $slug . '-' . $n;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insert(array $data): int
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO cms_pages (
                title, slug, body_html, status, show_in_footer, visibility,
                meta_title, meta_description, focus_keyword, canonical_url,
                og_title, og_description, og_image, robots_index, robots_follow, published_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['title'],
            $data['slug'],
            $data['body_html'],
            $data['status'],
            $data['show_in_footer'],
            $data['visibility'],
            $data['meta_title'],
            $data['meta_description'],
            $data['focus_keyword'],
            $data['canonical_url'],
            $data['og_title'],
            $data['og_description'],
            $data['og_image'],
            $data['robots_index'],
            $data['robots_follow'],
            $data['published_at'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE cms_pages SET
                title = ?, slug = ?, body_html = ?, status = ?, show_in_footer = ?, visibility = ?,
                meta_title = ?, meta_description = ?, focus_keyword = ?, canonical_url = ?,
                og_title = ?, og_description = ?, og_image = ?, robots_index = ?, robots_follow = ?,
                published_at = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $data['title'],
            $data['slug'],
            $data['body_html'],
            $data['status'],
            $data['show_in_footer'],
            $data['visibility'],
            $data['meta_title'],
            $data['meta_description'],
            $data['focus_keyword'],
            $data['canonical_url'],
            $data['og_title'],
            $data['og_description'],
            $data['og_image'],
            $data['robots_index'],
            $data['robots_follow'],
            $data['published_at'],
            $id,
        ]);
    }

    public static function nextVersionNumber(int $cmsPageId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1 FROM cms_page_versions WHERE cms_page_id = ?'
        );
        $stmt->execute([$cmsPageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function insertVersion(int $cmsPageId, int $versionNumber, array $snapshot, ?int $adminId, ?string $changeSummary): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'INSERT INTO cms_page_versions (
                cms_page_id, version_number, title, slug, body_html, status,
                meta_title, meta_description, focus_keyword, canonical_url,
                og_title, og_description, og_image, robots_index, robots_follow, visibility,
                change_summary, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $cmsPageId,
            $versionNumber,
            $snapshot['title'],
            $snapshot['slug'],
            $snapshot['body_html'],
            $snapshot['status'],
            $snapshot['meta_title'],
            $snapshot['meta_description'],
            $snapshot['focus_keyword'],
            $snapshot['canonical_url'],
            $snapshot['og_title'],
            $snapshot['og_description'],
            $snapshot['og_image'],
            $snapshot['robots_index'],
            $snapshot['robots_follow'],
            $snapshot['visibility'],
            $changeSummary,
            $adminId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function versionHistory(int $cmsPageId, int $limit = 50): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, cms_page_id, version_number, title, slug, status, change_summary, created_by, created_at
             FROM cms_page_versions WHERE cms_page_id = ? ORDER BY version_number DESC LIMIT ?'
        );
        $stmt->bindValue(1, $cmsPageId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function findVersionById(int $versionId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM cms_page_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function deleteById(int $id): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('DELETE FROM cms_pages WHERE id = ?')->execute([$id]);
    }
}
