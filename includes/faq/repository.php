<?php

declare(strict_types=1);

final class FaqRepository
{
    /** @return list<array<string, mixed>> */
    public static function activeCategories(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $hasActive = self::categoryHasIsActive();
        $sql = 'SELECT id, name, slug, sort_order FROM faq_categories';
        if ($hasActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function activeFaqsByCategory(?int $categoryId): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($categoryId === null) {
            $stmt = $pdo->prepare(
                'SELECT f.id, f.category_id, f.question, f.answer, f.sort_order, c.slug AS category_slug, c.name AS category_name
                 FROM faqs f
                 LEFT JOIN faq_categories c ON c.id = f.category_id
                 WHERE f.is_active = 1' . (self::categoryHasIsActive() ? ' AND (c.id IS NULL OR c.is_active = 1)' : '') . '
                 ORDER BY f.sort_order ASC, f.id ASC'
            );
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                'SELECT f.id, f.category_id, f.question, f.answer, f.sort_order, c.slug AS category_slug, c.name AS category_name
                 FROM faqs f
                 LEFT JOIN faq_categories c ON c.id = f.category_id
                 WHERE f.is_active = 1 AND f.category_id = ?' . (self::categoryHasIsActive() ? ' AND (c.id IS NULL OR c.is_active = 1)' : '') . '
                 ORDER BY f.sort_order ASC, f.id ASC'
            );
            $stmt->execute([$categoryId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function searchActive(string $query, int $limit = 50, ?int $categoryId = null): array
    {
        $result = self::listActivePublic(null, $query, 1, $limit, $categoryId);

        return $result['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function listActivePublic(
        ?string $categorySlug,
        ?string $searchQuery,
        int $page = 1,
        int $perPage = 20,
        ?int $categoryIdOverride = null
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;
        $categoryId = $categoryIdOverride;
        if ($categoryId === null && $categorySlug !== null && $categorySlug !== '') {
            $cat = self::findCategoryBySlug($categorySlug);
            if ($cat === null || (self::categoryHasIsActive() && empty($cat['is_active']))) {
                return ['rows' => [], 'total' => 0];
            }
            $categoryId = (int) $cat['id'];
        }
        $search = trim((string) ($searchQuery ?? ''));
        $catActiveSql = self::categoryHasIsActive() ? ' AND c.is_active = 1' : '';
        $baseFrom = ' FROM faqs f INNER JOIN faq_categories c ON c.id = f.category_id
                      WHERE f.is_active = 1' . $catActiveSql;
        $params = [];
        if ($categoryId !== null) {
            $baseFrom .= ' AND f.category_id = ?';
            $params[] = $categoryId;
        }
        if ($search !== '') {
            if (self::hasFulltextIndex()) {
                $baseFrom .= ' AND MATCH(f.question, f.answer) AGAINST (? IN NATURAL LANGUAGE MODE)';
                $params[] = $search;
            } else {
                $like = '%' . self::escapeLike($search) . '%';
                $baseFrom .= ' AND (f.question LIKE ? OR f.answer LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseFrom);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $order = $search !== '' && self::hasFulltextIndex()
            ? ' ORDER BY MATCH(f.question, f.answer) AGAINST (? IN NATURAL LANGUAGE MODE) DESC, c.sort_order ASC, f.sort_order ASC, f.id ASC'
            : ' ORDER BY c.sort_order ASC, f.sort_order ASC, f.id ASC';
        $select = 'SELECT f.id, f.category_id, f.question, f.answer, f.sort_order, c.slug AS category_slug, c.name AS category_name, c.sort_order AS category_sort_order'
            . $baseFrom . $order . ' LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($select);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        if ($search !== '' && self::hasFulltextIndex()) {
            $stmt->bindValue($i++, $search);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public static function searchForAdmin(
        string $query,
        ?int $categoryId,
        ?int $isActive,
        int $page,
        int $perPage,
        string $sortColumn = 'sort_order',
        string $sortDirection = 'asc'
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));
        $offset = ($page - 1) * $perPage;
        $where = ['1=1'];
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where[] = '(f.question LIKE ? OR f.answer LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($categoryId !== null && $categoryId > 0) {
            $where[] = 'f.category_id = ?';
            $params[] = $categoryId;
        }
        if ($isActive !== null && ($isActive === 0 || $isActive === 1)) {
            $where[] = 'f.is_active = ?';
            $params[] = $isActive;
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'sort_order' => 'f.sort_order',
            'id' => 'f.id',
            'question' => 'f.question',
        ];
        $sortCol = $sortMap[$sortColumn] ?? 'f.sort_order';
        $sortDir = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM faqs f WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $sql = 'SELECT f.id, f.category_id, f.question, f.answer, f.sort_order, f.is_active, c.name AS category_name
                FROM faqs f
                LEFT JOIN faq_categories c ON c.id = f.category_id
                WHERE ' . $whereSql . ' ORDER BY ' . $sortCol . ' ' . $sortDir . ', f.id DESC LIMIT ? OFFSET ?';
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

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public static function listForAdmin(int $page = 1, int $perPage = 50): array
    {
        return self::searchForAdmin('', null, null, $page, $perPage);
    }

    /** @return list<array<string, mixed>> */
    public static function listCategoriesForAdmin(bool $includeInactive = true): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $sql = 'SELECT id, name, slug, sort_order';
        if (self::categoryHasIsActive()) {
            $sql .= ', is_active';
        } else {
            $sql .= ', 1 AS is_active';
        }
        $sql .= ' FROM faq_categories';
        if (!$includeInactive && self::categoryHasIsActive()) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function findCategoryById(int $id): ?array
    {
        if ($id <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM faq_categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findCategoryBySlug(string $slug): ?array
    {
        if ($slug === '' || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM faq_categories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findFaqById(int $id): ?array
    {
        if ($id <= 0 || !Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM faqs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function categorySlugTaken(string $slug, ?int $excludeId = null): bool
    {
        Install::ensureConnection();
        $sql = 'SELECT COUNT(*) FROM faq_categories WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function faqCountForCategory(int $categoryId): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM faqs WHERE category_id = ?');
        $stmt->execute([$categoryId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insertCategory(array $data): int
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        if (self::categoryHasIsActive()) {
            $stmt = $pdo->prepare(
                'INSERT INTO faq_categories (name, slug, sort_order, is_active) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$data['name'], $data['slug'], $data['sort_order'], $data['is_active']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO faq_categories (name, slug, sort_order) VALUES (?, ?, ?)');
            $stmt->execute([$data['name'], $data['slug'], $data['sort_order']]);
        }

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateCategory(int $id, array $data): void
    {
        Install::ensureConnection();
        if (self::categoryHasIsActive()) {
            Database::pdo()->prepare(
                'UPDATE faq_categories SET name = ?, slug = ?, sort_order = ?, is_active = ? WHERE id = ?'
            )->execute([$data['name'], $data['slug'], $data['sort_order'], $data['is_active'], $id]);
        } else {
            Database::pdo()->prepare(
                'UPDATE faq_categories SET name = ?, slug = ?, sort_order = ? WHERE id = ?'
            )->execute([$data['name'], $data['slug'], $data['sort_order'], $id]);
        }
    }

    public static function setCategoryActive(int $id, bool $active): void
    {
        Install::ensureConnection();
        if (!self::categoryHasIsActive()) {
            return;
        }
        Database::pdo()->prepare('UPDATE faq_categories SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }

    public static function deleteCategory(int $id): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('DELETE FROM faq_categories WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function insertFaq(array $data): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO faqs (category_id, question, answer, sort_order, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['category_id'],
            $data['question'],
            $data['answer'],
            $data['sort_order'],
            $data['is_active'],
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateFaq(int $id, array $data): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE faqs SET category_id = ?, question = ?, answer = ?, sort_order = ?, is_active = ? WHERE id = ?'
        )->execute([
            $data['category_id'],
            $data['question'],
            $data['answer'],
            $data['sort_order'],
            $data['is_active'],
            $id,
        ]);
    }

    public static function setFaqActive(int $id, bool $active): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE faqs SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public static function deleteFaq(int $id): void
    {
        Install::ensureConnection();
        Database::pdo()->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
    }

    public static function categoryHasIsActiveColumn(): bool
    {
        return self::categoryHasIsActive();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function categoryHasIsActive(): bool
    {
        try {
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE table_schema = DATABASE() AND table_name = 'faq_categories' AND column_name = 'is_active'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function hasFulltextIndex(): bool
    {
        try {
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE() AND table_name = 'faqs' AND index_name = 'ft_faqs_search'"
            );

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
