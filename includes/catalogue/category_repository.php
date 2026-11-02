<?php

declare(strict_types=1);

final class CategoryRepository
{
    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            'SELECT c.*, parent.name AS parent_name
             FROM categories c
             LEFT JOIN categories parent ON parent.id = c.parent_id
             ORDER BY c.sort_order, c.name'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function published(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            "SELECT * FROM categories WHERE status = 'published' ORDER BY sort_order, name"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $sql = 'SELECT * FROM categories WHERE slug = ?';
        if ($publicOnly) {
            $sql .= " AND status = 'published'";
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, errors?: array<string, string>, message?: string}
     */
    public static function save(?int $id, array $input): array
    {
        $errors = Validation::requiredFields($input, ['name']);
        $name = trim((string) ($input['name'] ?? ''));
        $slugInput = trim((string) ($input['slug'] ?? ''));
        $slug = $slugInput !== '' ? $slugInput : CatalogueSlug::fromName($name);
        if (!Validation::slug($slug)) {
            $errors['slug'] = 'Use lowercase letters, numbers and hyphens only.';
        }
        $status = (string) ($input['status'] ?? 'published');
        if (!in_array($status, ['published', 'hidden'], true)) {
            $status = 'published';
        }
        $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;
        if ($parentId !== null && $parentId === $id) {
            $errors['parent_id'] = 'Category cannot be its own parent.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $slug = CatalogueSlug::uniqueCategorySlug($slug, $id);
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

    /** Map DB row to storefront shape. */
    public static function toPublicArray(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'icon' => '',
            'show_on_homepage' => (bool) ($row['show_on_homepage'] ?? false),
        ];
    }
}
