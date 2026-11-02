<?php

declare(strict_types=1);

final class ProductRepository
{
    /** @return list<array<string, mixed>> */
    public static function adminList(?string $query = null, ?string $categorySlug = null, ?string $status = null): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $sql = 'SELECT p.*, c.slug AS category_slug, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE 1=1';
        $params = [];
        if ($query !== null && trim($query) !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.slug LIKE ?)';
            $like = '%' . trim($query) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($categorySlug !== null && $categorySlug !== '') {
            $sql .= ' AND c.slug = ?';
            $params[] = $categorySlug;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY p.updated_at DESC, p.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function find(int $id): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, c.slug AS category_slug FROM products p
             LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function findBySlug(string $slug, bool $publicOnly = false): ?array
    {
        Install::ensureConnection();
        $sql = 'SELECT p.*, c.slug AS category_slug FROM products p
                LEFT JOIN categories c ON c.id = p.category_id WHERE p.slug = ?';
        if ($publicOnly) {
            $sql .= " AND p.status = 'published'";
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public static function variants(int $productId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function images(int $productId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function attributes(int $productId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM product_attributes WHERE product_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<int> */
    public static function relatedProductIds(int $productId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT related_product_id FROM product_related WHERE product_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$productId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, errors?: array<string, string>, message?: string}
     */
    public static function saveGeneral(?int $id, array $input): array
    {
        $errors = Validation::requiredFields($input, ['name']);
        $name = trim((string) ($input['name'] ?? ''));
        $slugInput = trim((string) ($input['slug'] ?? ''));
        $slug = $slugInput !== '' ? $slugInput : CatalogueSlug::fromName($name);
        if (!Validation::slug($slug)) {
            $errors['slug'] = 'Invalid slug format.';
        }
        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $status = 'draft';
        }
        $type = (string) ($input['product_type'] ?? 'fresh');
        if (!in_array($type, ['fresh', 'cut', 'packaged'], true)) {
            $type = 'fresh';
        }
        $categoryId = isset($input['category_id']) && $input['category_id'] !== ''
            ? (int) $input['category_id']
            : null;
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $slug = CatalogueSlug::uniqueProductSlug($slug, $id);
        $sku = trim((string) ($input['sku'] ?? ''));
        $short = trim((string) ($input['short_description'] ?? ''));
        $desc = trim((string) ($input['description'] ?? ''));
        $featured = !empty($input['is_featured']) ? 1 : 0;
        $best = !empty($input['is_bestseller']) ? 1 : 0;
        $newArr = !empty($input['is_new_arrival']) ? 1 : 0;
        $metaTitle = trim((string) ($input['meta_title'] ?? ''));
        $metaDesc = trim((string) ($input['meta_description'] ?? ''));
        $robots = !empty($input['robots_index']) ? 1 : 0;

        Install::ensureConnection();
        $pdo = Database::pdo();

        if ($id === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO products (category_id, name, slug, sku, short_description, description, product_type, status,
                 is_featured, is_bestseller, is_new_arrival, meta_title, meta_description, robots_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $categoryId,
                $name,
                $slug,
                $sku !== '' ? $sku : null,
                $short !== '' ? $short : null,
                $desc !== '' ? $desc : null,
                $type,
                $status,
                $featured,
                $best,
                $newArr,
                $metaTitle !== '' ? $metaTitle : null,
                $metaDesc !== '' ? $metaDesc : null,
                $robots,
            ]);
            $newId = (int) $pdo->lastInsertId();
            AuditLog::write('product.create', 'product', (string) $newId, ['slug' => $slug]);

            return ['ok' => true, 'id' => $newId];
        }

        $existing = self::find($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        try {
            Database::transaction(static function () use (
                $existing,
                $id,
                $categoryId,
                $name,
                $slug,
                $sku,
                $short,
                $desc,
                $type,
                $status,
                $featured,
                $best,
                $newArr,
                $metaTitle,
                $metaDesc,
                $robots
            ): void {
                $redirect = SeoRedirectService::registerProductSlugChangeIfNeeded($existing, $slug, $status);
                if (!$redirect['ok']) {
                    throw new RuntimeException($redirect['message'] ?? 'Redirect failed.');
                }
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'UPDATE products SET category_id = ?, name = ?, slug = ?, sku = ?, short_description = ?, description = ?,
                     product_type = ?, status = ?, is_featured = ?, is_bestseller = ?, is_new_arrival = ?,
                     meta_title = ?, meta_description = ?, robots_index = ?, updated_at = NOW() WHERE id = ?'
                );
                $stmt->execute([
                    $categoryId,
                    $name,
                    $slug,
                    $sku !== '' ? $sku : null,
                    $short !== '' ? $short : null,
                    $desc !== '' ? $desc : null,
                    $type,
                    $status,
                    $featured,
                    $best,
                    $newArr,
                    $metaTitle !== '' ? $metaTitle : null,
                    $metaDesc !== '' ? $metaDesc : null,
                    $robots,
                    $id,
                ]);
            });
        } catch (Throwable $e) {
            error_log('ProductRepository::saveGeneral update: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not save product.'];
        }
        AuditLog::write('product.update', 'product', (string) $id, ['slug' => $slug]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param list<array<string, mixed>> $variants rows from form
     */
    public static function saveVariants(int $productId, array $variants): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $existing = self::variants($productId);
        $existingIds = [];
        foreach ($existing as $e) {
            $existingIds[(int) $e['id']] = true;
        }
        $kept = [];

        foreach ($variants as $i => $v) {
            $vid = isset($v['id']) && $v['id'] !== '' ? (int) $v['id'] : null;
            $vname = trim((string) ($v['name'] ?? ''));
            if ($vname === '') {
                continue;
            }
            $price = (float) ($v['price'] ?? 0);
            if ($price < 0) {
                return ['ok' => false, 'message' => 'Variant price cannot be negative.'];
            }
            $saleRaw = trim((string) ($v['sale_price'] ?? ''));
            $sale = $saleRaw === '' ? null : (float) $saleRaw;
            $sku = trim((string) ($v['sku'] ?? ''));
            $stock = max(0, (int) ($v['stock_qty'] ?? 0));
            $low = max(0, (int) ($v['low_stock_threshold'] ?? 5));
            $active = !empty($v['is_active']) ? 1 : 0;
            $sort = (int) ($v['sort_order'] ?? $i);

            if ($vid !== null && isset($existingIds[$vid])) {
                $pdo->prepare(
                    'UPDATE product_variants SET name = ?, sku = ?, price = ?, sale_price = ?, stock_qty = ?,
                     low_stock_threshold = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND product_id = ?'
                )->execute([$vname, $sku !== '' ? $sku : null, $price, $sale, $stock, $low, $active, $sort, $vid, $productId]);
                $kept[$vid] = true;
            } else {
                $pdo->prepare(
                    'INSERT INTO product_variants (product_id, name, sku, price, sale_price, stock_qty, low_stock_threshold, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$productId, $vname, $sku !== '' ? $sku : null, $price, $sale, $stock, $low, $active, $sort]);
                $kept[(int) $pdo->lastInsertId()] = true;
            }
        }

        foreach (array_keys($existingIds) as $eid) {
            if (!isset($kept[$eid])) {
                $pdo->prepare('DELETE FROM product_variants WHERE id = ? AND product_id = ?')->execute([$eid, $productId]);
            }
        }

        return ['ok' => true];
    }

    /**
     * @param list<array{label: string, value: string}> $attrs
     */
    public static function saveAttributes(int $productId, array $attrs): void
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM product_attributes WHERE product_id = ?')->execute([$productId]);
        $stmt = $pdo->prepare(
            'INSERT INTO product_attributes (product_id, attr_key, attr_value, sort_order) VALUES (?, ?, ?, ?)'
        );
        $i = 0;
        foreach ($attrs as $a) {
            $key = trim((string) ($a['label'] ?? $a['attr_key'] ?? ''));
            $val = trim((string) ($a['value'] ?? $a['attr_value'] ?? ''));
            if ($key === '' || $val === '') {
                continue;
            }
            $stmt->execute([$productId, $key, $val, $i++]);
        }
    }

    public static function addImage(int $productId, string $filePath, bool $isPrimary = false): int
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($isPrimary) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        }
        $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM product_images WHERE product_id = ?');
        $max->execute([$productId]);
        $sort = (int) $max->fetchColumn() + 1;
        $pdo->prepare(
            'INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, ?, ?)'
        )->execute([$productId, $filePath, $sort, $isPrimary ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    public static function deleteImage(int $productId, int $imageId): bool
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT file_path FROM product_images WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->execute([$imageId, $productId]);
        $path = $stmt->fetchColumn();
        if ($path === false) {
            return false;
        }
        Database::pdo()->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')
            ->execute([$imageId, $productId]);
        $full = APNI_TOKRI_ROOT . '/' . ltrim((string) $path, '/');
        if (strpos((string) $path, 'uploads/') === 0 && is_file($full)) {
            @unlink($full);
        }

        return true;
    }

    /** @param list<int> $orderedImageIds */
    public static function reorderImages(int $productId, array $orderedImageIds): void
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        foreach ($orderedImageIds as $sort => $imageId) {
            $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?')
                ->execute([$sort, (int) $imageId, $productId]);
        }
    }

    public static function setPrimaryImage(int $productId, int $imageId): void
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        $pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')
            ->execute([$imageId, $productId]);
    }

    /** Build storefront product array with variants loaded. */
    public static function toPublicArray(array $row, bool $withRelations = true): array
    {
        $productId = (int) $row['id'];
        $flags = [];
        if (!empty($row['is_featured'])) {
            $flags[] = 'featured';
        }
        if (!empty($row['is_bestseller'])) {
            $flags[] = 'bestseller';
        }
        if (!empty($row['is_new_arrival'])) {
            $flags[] = 'new';
        }

        $variants = [];
        foreach (self::variants($productId) as $v) {
            if (!(int) $v['is_active']) {
                continue;
            }
            $variants[] = [
                'id' => (string) $v['id'],
                'label' => (string) $v['name'],
                'price' => (float) $v['price'],
                'sale_price' => $v['sale_price'] !== null ? (float) $v['sale_price'] : null,
                'stock' => (int) $v['stock_qty'],
                'low_stock' => (int) $v['low_stock_threshold'],
                'sku' => (string) ($v['sku'] ?? ''),
            ];
        }

        $images = [];
        foreach (self::images($productId) as $img) {
            $path = (string) $img['file_path'];
            $images[] = strpos($path, 'http') === 0 ? $path : asset_url($path);
        }

        $attributes = [];
        foreach (self::attributes($productId) as $a) {
            $attributes[] = [
                'label' => (string) $a['attr_key'],
                'value' => (string) $a['attr_value'],
            ];
        }

        $relatedSlugs = [];
        if ($withRelations) {
            foreach (self::relatedProductIds($productId) as $rid) {
                $rp = self::find($rid);
                if ($rp && ($rp['status'] ?? '') === 'published') {
                    $relatedSlugs[] = (string) $rp['slug'];
                }
            }
        }

        return [
            'id' => (string) $row['slug'],
            'slug' => (string) $row['slug'],
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) $row['name'],
            'category_slug' => (string) ($row['category_slug'] ?? ''),
            'type' => (string) ($row['product_type'] ?? 'fresh'),
            'short_description' => (string) ($row['short_description'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'images' => $images !== [] ? $images : [asset_url('assets/images/logo-placeholder.svg')],
            'flags' => $flags,
            'variants' => $variants,
            'attributes' => $attributes,
            'reviews' => [],
            'related_slugs' => $relatedSlugs,
            'status' => (string) ($row['status'] ?? 'draft'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function publishedProducts(): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->query(
            "SELECT p.*, c.slug AS category_slug FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.status = 'published'
             ORDER BY p.name"
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::toPublicArray($row);
        }

        return $out;
    }

    public static function productCount(): int
    {
        if (!Install::isSchemaReady()) {
            return 0;
        }
        try {
            Install::ensureConnection();

            return (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
