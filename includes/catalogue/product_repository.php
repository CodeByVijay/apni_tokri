<?php

declare(strict_types=1);

final class ProductRepository
{
    /** @var array<int, true>|null */
    private static $visibleCategoryIds = null;

    public static function resetPublicVisibilityCache(): void
    {
        self::$visibleCategoryIds = null;
    }

    /**
     * Category ids assigned to a product row (extensible if multi-category is added later).
     *
     * @param array<string, mixed> $productRow
     * @return list<int>
     */
    public static function assignedCategoryIds(array $productRow): array
    {
        $id = $productRow['category_id'] ?? null;
        if ($id === null || $id === '') {
            return [];
        }

        return [(int) $id];
    }

    /**
     * True when at least one assigned category id is publicly visible (published ancestor chain).
     *
     * @param list<int> $categoryIds
     */
    public static function hasPublicCategoryAssignment(array $categoryIds): bool
    {
        if ($categoryIds === []) {
            return false;
        }
        $visible = self::publiclyVisibleCategoryIds();
        foreach ($categoryIds as $cid) {
            if (isset($visible[$cid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Storefront visibility: published product with at least one publicly visible category assignment.
     *
     * @param array<string, mixed> $row products row (+ optional category_slug from join)
     */
    public static function isPubliclyVisibleRow(array $row): bool
    {
        if ((string) ($row['status'] ?? '') !== 'published') {
            return false;
        }

        return self::hasPublicCategoryAssignment(self::assignedCategoryIds($row));
    }

    /** @return array<int, true> */
    private static function publiclyVisibleCategoryIds(): array
    {
        if (self::$visibleCategoryIds !== null) {
            return self::$visibleCategoryIds;
        }
        Install::ensureConnection();
        $meta = Database::pdo()->query('SELECT id, parent_id, status FROM categories')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        self::$visibleCategoryIds = CategoryRepository::visibleIdSet($meta);

        return self::$visibleCategoryIds;
    }

    /** @return list<array<string, mixed>> */
    public static function adminList(?string $query = null, ?string $categorySlug = null, ?string $status = null): array
    {
        $result = self::searchForAdmin(
            $query ?? '',
            $categorySlug ?? '',
            $status ?? '',
            1,
            500,
            'updated_at',
            'desc'
        );

        return $result['rows'];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public static function searchForAdmin(
        string $query,
        string $categorySlug,
        string $status,
        int $page,
        int $perPage,
        string $sortColumn = 'updated_at',
        string $sortDirection = 'desc'
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
            $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.slug LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($categorySlug !== '') {
            $where[] = 'c.slug = ?';
            $params[] = $categorySlug;
        }
        if ($status !== '') {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $sortMap = [
            'name' => 'p.name',
            'sku' => 'p.sku',
            'updated_at' => 'p.updated_at',
            'id' => 'p.id',
            'status' => 'p.status',
        ];
        $orderSql = AdminServerList::orderBySql($sortColumn, $sortDirection, $sortMap, 'updated_at');
        $from = ' FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE ' . $whereSql;
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT p.*, c.slug AS category_slug, c.name AS category_name' . $from
            . ' ORDER BY ' . $orderSql . ', p.id DESC LIMIT ? OFFSET ?';
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
        if (!$row) {
            return null;
        }
        if ($publicOnly && !self::isPubliclyVisibleRow($row)) {
            return null;
        }

        return $row;
    }

    public static function slugTaken(string $slug, ?int $excludeId = null): bool
    {
        Install::ensureConnection();
        $sql = 'SELECT COUNT(*) FROM products WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
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
    /**
     * SEO-only update from Admin → SEO catalog (does not change slug, SKU, or catalogue fields).
     *
     * @param array<string, mixed> $input meta_title, meta_description, optional robots_index
     * @return array{ok: bool, message?: string, errors?: array<string, string>}
     */
    public static function saveSeoFields(int $id, array $input): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Invalid product.'];
        }
        $existing = self::find($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        $metaTitle = trim((string) ($input['meta_title'] ?? ''));
        $metaDesc = trim((string) ($input['meta_description'] ?? ''));
        $robots = !empty($input['robots_index']) ? 1 : 0;
        $errors = [];
        if ($metaTitle !== '' && mb_strlen($metaTitle) > 70) {
            $errors['meta_title'] = 'Keep the search title within 70 characters.';
        }
        if ($metaDesc !== '' && mb_strlen($metaDesc) > 320) {
            $errors['meta_description'] = 'Keep the summary within 320 characters.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Fix the highlighted fields.', 'errors' => $errors];
        }
        Install::ensureConnection();
        Database::pdo()->prepare(
            'UPDATE products SET meta_title = ?, meta_description = ?, robots_index = ?, updated_at = NOW() WHERE id = ?'
        )->execute([
            $metaTitle !== '' ? mb_substr($metaTitle, 0, 191) : null,
            $metaDesc !== '' ? mb_substr($metaDesc, 0, 320) : null,
            $robots,
            $id,
        ]);
        AuditLog::write('product.seo_update', 'product', (string) $id, [
            'from' => 'seo_catalog',
        ]);

        return ['ok' => true, 'message' => 'Product SEO saved.'];
    }

    public static function saveGeneral(?int $id, array $input): array
    {
        $errors = Validation::requiredFields($input, ['name']);
        $name = trim((string) ($input['name'] ?? ''));
        $existing = $id !== null ? self::find($id) : null;
        $slugRes = CatalogueSlug::resolveForAdminSave('product', $name, $input, $existing, $id);
        if (!$slugRes['ok']) {
            $errors = array_merge($errors, $slugRes['errors']);
            $slug = '';
        } else {
            $slug = $slugRes['slug'];
        }
        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $status = 'draft';
        }
        $type = strtolower(trim((string) ($input['product_type'] ?? ProductTypeRepository::defaultCode())));
        if (!ProductTypeRepository::isAllowedCode(
            $type,
            $existing !== null && (string) ($existing['product_type'] ?? '') === $type
        )) {
            $errors['product_type'] = 'Choose a valid product type.';
        }
        $categoryId = isset($input['category_id']) && $input['category_id'] !== ''
            ? (int) $input['category_id']
            : null;
        $skuInput = trim((string) ($input['sku'] ?? ''));
        if ($skuInput !== '') {
            $skuCheck = ProductSkuService::validateForSave($skuInput, $id);
            if (!$skuCheck['ok']) {
                $errors['sku'] = $skuCheck['message'] ?? 'Invalid SKU.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $short = trim((string) ($input['short_description'] ?? ''));
        $desc = trim((string) ($input['description'] ?? ''));
        $featured = !empty($input['is_featured']) ? 1 : 0;
        $best = !empty($input['is_bestseller']) ? 1 : 0;
        $newArr = !empty($input['is_new_arrival']) ? 1 : 0;
        $metaTitle = trim((string) ($input['meta_title'] ?? ''));
        $metaDesc = trim((string) ($input['meta_description'] ?? ''));
        $robots = !empty($input['robots_index']) ? 1 : 0;

        Install::ensureConnection();

        if ($id === null) {
            return Database::transaction(static function () use (
                $categoryId,
                $name,
                $slug,
                $skuInput,
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
            ): array {
                $sku = $skuInput !== '' ? ProductSkuService::normalize($skuInput) : ProductSkuService::allocateNext();
                $pdo = Database::pdo();
                $stmt = $pdo->prepare(
                    'INSERT INTO products (category_id, name, slug, sku, short_description, description, product_type, status,
                     is_featured, is_bestseller, is_new_arrival, meta_title, meta_description, robots_index)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $categoryId,
                    $name,
                    $slug,
                    $sku,
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
                AuditLog::write('product.create', 'product', (string) $newId, ['slug' => $slug, 'sku' => $sku]);

                return ['ok' => true, 'id' => $newId];
            });
        }

        $sku = $skuInput !== '' ? ProductSkuService::normalize($skuInput) : null;

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
        $seenSkus = [];

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
            if ($sku !== '') {
                $sku = ProductSkuService::normalize($sku);
                if (isset($seenSkus[$sku])) {
                    return ['ok' => false, 'message' => 'Duplicate variant SKU "' . $sku . '" on this product.'];
                }
                $seenSkus[$sku] = true;
                $productRow = self::find($productId);
                if ($productRow !== null && ProductSkuService::normalize((string) ($productRow['sku'] ?? '')) === $sku) {
                    return ['ok' => false, 'message' => 'Variant SKU cannot be the same as this product\'s SKU.'];
                }
                if (ProductSkuService::skuInUse($sku, $productId, $vid)) {
                    return ['ok' => false, 'message' => 'Variant SKU "' . $sku . '" is already used by another product or variant.'];
                }
            }
            $stock = max(0, (int) ($v['stock_qty'] ?? 0));
            $low = max(0, (int) ($v['low_stock_threshold'] ?? 5));
            $maxPer = CartQuantityRules::adminMaxPerOrderOrDefault($v['max_qty_per_order'] ?? '');
            $minPer = CartQuantityRules::adminMinPerOrderOrDefault($v['min_qty_per_order'] ?? '');
            $active = !empty($v['is_active']) ? 1 : 0;
            $sort = (int) ($v['sort_order'] ?? $i);

            if ($vid !== null && isset($existingIds[$vid])) {
                try {
                    $pdo->prepare(
                        'UPDATE product_variants SET name = ?, sku = ?, price = ?, sale_price = ?, stock_qty = ?,
                         low_stock_threshold = ?, max_qty_per_order = ?, min_qty_per_order = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND product_id = ?'
                    )->execute([$vname, $sku !== '' ? $sku : null, $price, $sale, $stock, $low, $maxPer, $minPer, $active, $sort, $vid, $productId]);
                } catch (Throwable $e) {
                    $pdo->prepare(
                        'UPDATE product_variants SET name = ?, sku = ?, price = ?, sale_price = ?, stock_qty = ?,
                         low_stock_threshold = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND product_id = ?'
                    )->execute([$vname, $sku !== '' ? $sku : null, $price, $sale, $stock, $low, $active, $sort, $vid, $productId]);
                }
                $kept[$vid] = true;
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO product_variants (product_id, name, sku, price, sale_price, stock_qty, low_stock_threshold, max_qty_per_order, min_qty_per_order, is_active, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$productId, $vname, $sku !== '' ? $sku : null, $price, $sale, $stock, $low, $maxPer, $minPer, $active, $sort]);
                } catch (Throwable $e) {
                    $pdo->prepare(
                        'INSERT INTO product_variants (product_id, name, sku, price, sale_price, stock_qty, low_stock_threshold, is_active, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$productId, $vname, $sku !== '' ? $sku : null, $price, $sale, $stock, $low, $active, $sort]);
                }
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
                'max_qty_per_order' => array_key_exists('max_qty_per_order', $v) ? $v['max_qty_per_order'] : CartQuantityRules::DEFAULT_MAX_PER_ORDER,
                'min_qty_per_order' => array_key_exists('min_qty_per_order', $v) ? (int) $v['min_qty_per_order'] : CartQuantityRules::DEFAULT_MIN_PER_ORDER,
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
                if ($rp !== null && self::isPubliclyVisibleRow($rp)) {
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
            if (!self::isPubliclyVisibleRow($row)) {
                continue;
            }
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

    /** Published products visible on the public storefront (status + category visibility). */
    public static function publishedPublicProductCount(): int
    {
        if (!Install::isSchemaReady()) {
            return 0;
        }
        try {
            return count(self::publishedProducts());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array{ok: bool, message: string, archived?: bool} */
    public static function deleteOrArchiveForAdmin(int $id): array
    {
        $row = self::find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM order_items oi
             INNER JOIN product_variants pv ON pv.id = oi.variant_id
             WHERE pv.product_id = ?'
        );
        $st->execute([$id]);
        $viaVariant = (int) $st->fetchColumn();
        $sku = trim((string) ($row['sku'] ?? ''));
        $viaSku = 0;
        if ($sku !== '') {
            $sk = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE sku = ?');
            $sk->execute([$sku]);
            $viaSku = (int) $sk->fetchColumn();
        }
        if ($viaVariant > 0 || $viaSku > 0) {
            $pdo->prepare("UPDATE products SET status = 'archived', updated_at = NOW() WHERE id = ?")->execute([$id]);
            CatalogueGateway::reset();
            AuditLog::write('product.archive', 'product', (string) $id, ['reason' => 'order_history']);

            return ['ok' => true, 'message' => 'Product appears on past orders and was archived instead of deleted.', 'archived' => true];
        }
        try {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            CatalogueGateway::reset();
            AuditLog::write('product.delete', 'product', (string) $id, ['slug' => (string) $row['slug']]);

            return ['ok' => true, 'message' => 'Product deleted.'];
        } catch (Throwable $e) {
            error_log('ProductRepository::deleteOrArchiveForAdmin: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not delete product. Try archiving it from the editor instead.'];
        }
    }
}
