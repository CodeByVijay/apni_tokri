<?php

declare(strict_types=1);

final class CategoryRepository
{
    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        $result = self::searchForAdmin('', null, 1, 500, 'sort_order', 'asc');

        return $result['rows'];
    }

    /** @return list<array<string, mixed>> Every category in display order (admin reorder UI). */
    public static function allOrdered(): array
    {
        if (!Install::isSchemaReady()) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT id, name, slug, status, show_on_homepage, parent_id, sort_order FROM categories ORDER BY sort_order, id'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<int> */
    public static function orderedIdList(): array
    {
        $ids = [];
        foreach (self::allOrdered() as $row) {
            $ids[] = (int) ($row['id'] ?? 0);
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * @param list<int> $orderedIds
     * @param list<int> $baselineIds
     * @return array{ok: bool, message: string}
     */
    public static function reorderAll(array $orderedIds, array $baselineIds): array
    {
        $orderedIds = array_values(array_unique(array_filter(array_map('intval', $orderedIds), static fn (int $id): bool => $id > 0)));
        $baselineIds = array_values(array_filter(array_map('intval', $baselineIds), static fn (int $id): bool => $id > 0));

        if ($orderedIds === []) {
            return ['ok' => false, 'message' => 'No categories to reorder.'];
        }
        if (!Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Database is not available.'];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $currentIds = self::orderedIdList();
        if ($currentIds === []) {
            return ['ok' => false, 'message' => 'No categories to reorder.'];
        }
        if (count($orderedIds) !== count($currentIds)) {
            return ['ok' => false, 'message' => 'Reorder must include every category. Refresh the page and try again.'];
        }

        $currentSet = $currentIds;
        sort($currentSet);
        $orderedSet = $orderedIds;
        sort($orderedSet);
        if ($orderedSet !== $currentSet) {
            return ['ok' => false, 'message' => 'Invalid category selection. Refresh the page and try again.'];
        }

        $baselineFiltered = array_values(array_filter($baselineIds, static fn (int $id): bool => in_array($id, $currentIds, true)));
        if ($baselineFiltered !== $currentIds) {
            return [
                'ok' => false,
                'message' => 'Category order was changed elsewhere. Reload this page to see the latest sequence, then save again.',
            ];
        }

        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE categories SET sort_order = ?, updated_at = NOW() WHERE id = ?');
            foreach ($orderedIds as $index => $id) {
                $update->execute([$index + 1, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'Could not save category order. No changes were applied.'];
        }

        CatalogueGateway::reset();

        return ['ok' => true, 'message' => 'Category order saved.'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchForAdmin(
        string $query,
        ?string $status,
        int $page,
        int $perPage,
        string $sortColumn = 'sort_order',
        string $sortDirection = 'asc'
    ): array {
        if (!Install::isSchemaReady()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = ['1=1'];
        $params = [];
        $q = trim($query);
        if ($q !== '') {
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $where[] = '(c.name LIKE ? OR c.slug LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'name' => 'c.name',
            'slug' => 'c.slug',
            'sort_order' => 'c.sort_order',
            'id' => 'c.id',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'sort_order');
        $from = ' FROM categories c LEFT JOIN categories parent ON parent.id = c.parent_id WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT c.*, parent.name AS parent_name' . $from . ' ORDER BY ' . $orderSql . ', c.id ASC LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Publicly visible categories: status = published AND every ancestor is published (a hidden parent
     * hides its whole subtree). Cyclic data is treated as not visible.
     *
     * @return list<array<string, mixed>>
     */
    public static function published(): array
    {
        Install::ensureConnection();
        $rows = Database::pdo()->query('SELECT * FROM categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $visible = self::visibleIdSet($rows);

        return array_values(array_filter($rows, static function (array $r) use ($visible): bool {
            return isset($visible[(int) $r['id']]);
        }));
    }

    /**
     * @param list<array<string, mixed>> $rows all categories
     * @return array<int, true>
     */
    public static function visibleIdSet(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $ok = [];
        foreach ($byId as $id => $r) {
            $cur = $r;
            $seen = [$id => true];
            $good = true;
            while (true) {
                if ((string) ($cur['status'] ?? '') !== 'published') {
                    $good = false;
                    break;
                }
                $pid = $cur['parent_id'] ?? null;
                if ($pid === null || $pid === '' || !isset($byId[(int) $pid])) {
                    break;
                }
                $pid = (int) $pid;
                if (isset($seen[$pid])) {
                    $good = false;
                    break;
                }
                $seen[$pid] = true;
                $cur = $byId[$pid];
            }
            if ($good) {
                $ok[$id] = true;
            }
        }

        return $ok;
    }

    /**
     * Public navigation tree (top-level categories with nested 'children'), storefront array shape.
     *
     * @return list<array<string, mixed>>
     */
    public static function navigationTree(): array
    {
        $rows = self::published();
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int) $r['id']] = true;
        }
        $children = [];
        foreach ($rows as $r) {
            $pid = $r['parent_id'] !== null && $r['parent_id'] !== '' ? (int) $r['parent_id'] : 0;
            $children[$pid][] = $r;
        }
        $build = static function (int $pid) use (&$build, $children): array {
            $out = [];
            foreach ($children[$pid] ?? [] as $r) {
                $node = CategoryRepository::toPublicArray($r);
                $node['children'] = $build((int) $r['id']);
                $out[] = $node;
            }

            return $out;
        };

        return $build(0);
    }

    /** Homepage tiles: publicly visible AND show_on_homepage. @return list<array<string, mixed>> */
    public static function homepage(): array
    {
        $out = [];
        foreach (self::published() as $r) {
            if (!empty($r['show_on_homepage'])) {
                $out[] = self::toPublicArray($r);
            }
        }

        return $out;
    }

    /** Visible direct children of a category. @return list<array<string, mixed>> */
    public static function visibleChildren(int $parentId): array
    {
        $out = [];
        foreach (self::published() as $r) {
            if ($r['parent_id'] !== null && (int) $r['parent_id'] === $parentId) {
                $out[] = self::toPublicArray($r);
            }
        }

        return $out;
    }

    /** Ancestor trail (root first, excluding the category itself). @return list<array<string, mixed>> */
    public static function ancestors(int $id): array
    {
        $trail = [];
        $seen = [$id => true];
        $cur = self::find($id);
        while ($cur !== null && $cur['parent_id'] !== null && !isset($seen[(int) $cur['parent_id']])) {
            $pid = (int) $cur['parent_id'];
            $seen[$pid] = true;
            $cur = self::find($pid);
            if ($cur !== null) {
                array_unshift($trail, self::toPublicArray($cur));
            }
        }

        return $trail;
    }

    /** All descendant ids (any depth) of a category. @return list<int> */
    public static function descendantIds(int $id): array
    {
        Install::ensureConnection();
        $rows = Database::pdo()->query('SELECT id, parent_id FROM categories')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $children = [];
        foreach ($rows as $r) {
            if ($r['parent_id'] !== null) {
                $children[(int) $r['parent_id']][] = (int) $r['id'];
            }
        }
        $out = [];
        $queue = [$id];
        while ($queue !== []) {
            $cur = array_shift($queue);
            foreach ($children[$cur] ?? [] as $c) {
                if (!isset($out[$c]) && $c !== $id) {
                    $out[$c] = $c;
                    $queue[] = $c;
                }
            }
        }

        return array_values($out);
    }

    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findBySlug(string $slug, bool $publicOnly = false): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM categories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ($publicOnly) {
            $all = Database::pdo()->query('SELECT id, parent_id, status FROM categories')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!isset(self::visibleIdSet($all)[(int) $row['id']])) {
                return null;
            }
        }

        return $row;
    }

    public static function slugTaken(string $slug, ?int $excludeId = null): bool
    {
        Install::ensureConnection();
        $sql = 'SELECT COUNT(*) FROM categories WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, errors?: array<string, string>, message?: string}
     */
    public static function save(?int $id, array $input): array
    {
        $errors = Validation::requiredFields($input, ['name']);
        $name = trim((string) ($input['name'] ?? ''));
        $existing = $id !== null ? self::find($id) : null;
        $slugRes = CatalogueSlug::resolveForAdminSave('category', $name, $input, $existing, $id);
        if (!$slugRes['ok']) {
            $errors = array_merge($errors, $slugRes['errors']);
            $slug = '';
        } else {
            $slug = $slugRes['slug'];
        }
        if (!Validation::slug($slug) && !isset($errors['slug'])) {
            $errors['slug'] = 'Use lowercase letters, numbers and hyphens only.';
        }
        $status = (string) ($input['status'] ?? 'published');
        if (!in_array($status, ['published', 'hidden'], true)) {
            $status = 'published';
        }
        $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;
        if ($parentId !== null && $parentId === $id) {
            $errors['parent_id'] = 'Category cannot be its own parent.';
        } elseif ($parentId !== null) {
            if (self::find($parentId) === null) {
                $errors['parent_id'] = 'Selected parent category does not exist.';
            } elseif ($id !== null && in_array($parentId, self::descendantIds($id), true)) {
                $errors['parent_id'] = 'A category cannot be moved under one of its own sub-categories.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $sortOrder = (int) ($input['sort_order'] ?? 0);
        $showHome = !empty($input['show_on_homepage']) ? 1 : 0;
        $description = trim((string) ($input['description'] ?? ''));
        $metaTitle = trim((string) ($input['meta_title'] ?? ''));
        $metaDesc = trim((string) ($input['meta_description'] ?? ''));

        Install::ensureConnection();
        $pdo = Database::pdo();

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO categories (parent_id, name, slug, description, sort_order, show_on_homepage, status, meta_title, meta_description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $parentId,
                $name,
                $slug,
                $description !== '' ? $description : null,
                $sortOrder,
                $showHome,
                $status,
                $metaTitle !== '' ? $metaTitle : null,
                $metaDesc !== '' ? $metaDesc : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
            AuditLog::write('category.create', 'category', (string) $newId, ['slug' => $slug]);

            return ['ok' => true, 'id' => $newId];
        }

        $existing = self::find($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Category not found.'];
        }
        try {
            Database::transaction(static function () use (
                $existing,
                $id,
                $parentId,
                $name,
                $slug,
                $description,
                $sortOrder,
                $showHome,
                $status,
                $metaTitle,
                $metaDesc
            ): void {
                $redirect = SeoRedirectService::registerCategorySlugChangeIfNeeded($existing, $slug, $status);
                if (!$redirect['ok']) {
                    throw new RuntimeException($redirect['message'] ?? 'Redirect failed.');
                }
                Database::pdo()->prepare(
                    'UPDATE categories SET parent_id = ?, name = ?, slug = ?, description = ?, sort_order = ?,
                     show_on_homepage = ?, status = ?, meta_title = ?, meta_description = ?, updated_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $parentId,
                    $name,
                    $slug,
                    $description !== '' ? $description : null,
                    $sortOrder,
                    $showHome,
                    $status,
                    $metaTitle !== '' ? $metaTitle : null,
                    $metaDesc !== '' ? $metaDesc : null,
                    $id,
                ]);
            });
        } catch (Throwable $e) {
            error_log('CategoryRepository::save update: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save category.'];
        }
        AuditLog::write('category.update', 'category', (string) $id, ['slug' => $slug]);

        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteForAdmin(int $id): array
    {
        $row = self::find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Category not found.'];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Remove or reassign child categories before deleting this category.'];
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Reassign or archive products in this category before deleting it.'];
        }
        try {
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            CatalogueGateway::reset();
            AuditLog::write('category.delete', 'category', (string) $id, ['slug' => (string) $row['slug']]);

            return ['ok' => true, 'message' => 'Category deleted.'];
        } catch (Throwable $e) {
            error_log('CategoryRepository::deleteForAdmin: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete category. It may still be referenced elsewhere.'];
        }
    }

    /** Map DB row to storefront shape. */
    public static function toPublicArray(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'image_path' => isset($row['image_path']) && $row['image_path'] !== null && $row['image_path'] !== ''
                ? (string) $row['image_path']
                : '',
            'icon' => '',
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null,
            'show_on_homepage' => (bool) ($row['show_on_homepage'] ?? false),
        ];
    }
}
