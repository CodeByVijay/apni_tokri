<?php

declare(strict_types=1);

/**
 * Admin coupon form: code generation, input validation, searchable pickers.
 * Storage reuses existing tables (coupons, coupon_categories, coupon_products,
 * coupon_excluded_products, customer_coupon_assignments) — no schema change.
 */
final class CouponAdminService
{
    public const MODE_ALL = 'all';
    public const MODE_CATEGORIES = 'categories';
    public const MODE_PRODUCTS = 'products';
    public const MODE_BOTH = 'both';

    public const CUSTOMERS_ALL = 'all';
    public const CUSTOMERS_SELECTED = 'selected';
    public const CUSTOMERS_FIRST_ORDER = 'first_order';

    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function codeIsValid(string $code): bool
    {
        return (bool) preg_match('/^[A-Z0-9_-]{3,32}$/', $code);
    }

    public static function codeExists(string $code, ?int $excludeId = null): bool
    {
        Install::ensureConnection();
        $sql = 'SELECT COUNT(*) FROM coupons WHERE code = ?';
        $params = [self::normalizeCode($code)];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Random uppercase alphanumeric code, unique against existing coupons. */
    public static function generateUniqueCode(string $prefix = 'APNI', int $randomLength = 6): string
    {
        $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper($prefix)) ?? '';
        $prefix = substr($prefix, 0, 10);
        $randomLength = max(4, min(16, $randomLength));
        $max = strlen(self::CODE_CHARS) - 1;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $rand = '';
            for ($i = 0; $i < $randomLength; $i++) {
                $rand .= self::CODE_CHARS[random_int(0, $max)];
            }
            $code = $prefix . $rand;
            if (!self::codeExists($code)) {
                return $code;
            }
        }
        throw new RuntimeException('Could not generate a unique coupon code.');
    }

    /** @param list<int> $categoryIds @param list<int> $productIds */
    public static function eligibilityModeFor(array $categoryIds, array $productIds): string
    {
        if ($categoryIds !== [] && $productIds !== []) {
            return self::MODE_BOTH;
        }
        if ($categoryIds !== []) {
            return self::MODE_CATEGORIES;
        }
        if ($productIds !== []) {
            return self::MODE_PRODUCTS;
        }

        return self::MODE_ALL;
    }

    /**
     * Validate and normalize the admin POST. Does not write.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, errors: array<string, string>, data: array<string, mixed>,
     *   customer_ids: list<int>, category_ids: list<int>, product_ids: list<int>, excluded_ids: list<int>}
     */
    public static function validateInput(array $post, ?int $couponId): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $errors = [];

        // --- Coupon information ---
        $code = self::normalizeCode((string) ($post['code'] ?? ''));
        if ($code === '') {
            $errors['code'] = 'Coupon code is required.';
        } elseif (!self::codeIsValid($code)) {
            $errors['code'] = 'Use 3–32 letters, numbers, hyphen or underscore (e.g. FRESH10).';
        } elseif (self::codeExists($code, $couponId)) {
            $errors['code'] = 'This coupon code already exists. Choose a different code.';
        }
        $internal = trim((string) ($post['internal_name'] ?? ''));
        if (mb_strlen($internal) > 191) {
            $errors['internal_name'] = 'Internal name must be 191 characters or fewer.';
        }

        // --- Discount ---
        $type = (string) ($post['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $valueRaw = trim((string) ($post['discount_value'] ?? ''));
        $value = 0.0;
        if ($valueRaw === '' || !is_numeric($valueRaw) || (float) $valueRaw <= 0) {
            $errors['discount_value'] = 'Enter a discount value greater than 0.';
        } else {
            $value = round((float) $valueRaw, 2);
            if ($type === 'percent' && $value > 100) {
                $errors['discount_value'] = 'A percentage discount cannot exceed 100.';
            }
            if ($value > 9999999) {
                $errors['discount_value'] = 'Discount value is too large.';
            }
        }
        $min = self::optionalMoney($post, 'min_cart_value', 'Minimum cart amount', $errors);
        $maxDiscount = self::optionalMoney($post, 'max_discount', 'Maximum discount', $errors);
        if ($type === 'fixed') {
            $maxDiscount = null; // only meaningful for percentage coupons
        }
        $usageLimit = self::optionalInt($post, 'usage_limit', 'Total usage limit', 1, $errors);
        $perCustomer = self::optionalInt($post, 'per_customer_limit', 'Per-customer limit', 1, $errors);
        if ($usageLimit !== null && $perCustomer !== null && $perCustomer > $usageLimit) {
            $errors['per_customer_limit'] = 'Per-customer limit cannot exceed the total usage limit.';
        }
        $priorityRaw = trim((string) ($post['priority'] ?? '0'));
        $priority = 0;
        if ($priorityRaw !== '') {
            if (!preg_match('/^-?\d{1,6}$/', $priorityRaw)) {
                $errors['priority'] = 'Priority must be a whole number.';
            } else {
                $priority = (int) $priorityRaw;
            }
        }

        // --- Dates ---
        $starts = self::optionalDate($post, 'starts_at', 'Valid from', $errors);
        $ends = self::optionalDate($post, 'ends_at', 'Valid until', $errors);
        if ($starts !== null && $ends !== null && strtotime($ends) <= strtotime($starts)) {
            $errors['ends_at'] = '"Valid until" must be after "Valid from".';
        }

        // --- Product / category eligibility ---
        $mode = (string) ($post['eligibility_mode'] ?? self::MODE_ALL);
        if (!in_array($mode, [self::MODE_ALL, self::MODE_CATEGORIES, self::MODE_PRODUCTS, self::MODE_BOTH], true)) {
            $mode = self::MODE_ALL;
        }
        $categoryIds = $productIds = [];
        if ($mode === self::MODE_CATEGORIES || $mode === self::MODE_BOTH) {
            $categoryIds = self::existingIds($pdo, 'categories', self::idList($post['category_ids'] ?? []));
            if ($categoryIds === []) {
                $errors['category_ids'] = 'Select at least one category, or choose "All products".';
            }
        }
        if ($mode === self::MODE_PRODUCTS || $mode === self::MODE_BOTH) {
            $productIds = self::existingIds($pdo, 'products', self::idList($post['product_ids'] ?? []));
            if ($productIds === []) {
                $errors['product_ids'] = 'Select at least one product, or choose "All products".';
            }
        }

        // --- Customer eligibility ---
        $custMode = (string) ($post['customer_mode'] ?? self::CUSTOMERS_ALL);
        if (!in_array($custMode, [self::CUSTOMERS_ALL, self::CUSTOMERS_SELECTED, self::CUSTOMERS_FIRST_ORDER], true)) {
            $custMode = self::CUSTOMERS_ALL;
        }
        $customerIds = [];
        $firstOrderOnly = 0;
        if ($custMode === self::CUSTOMERS_SELECTED) {
            $requested = self::idList($post['customer_ids'] ?? []);
            $customerIds = self::existingIds($pdo, 'customers', $requested, 'is_active = 1');
            if ($customerIds === []) {
                $errors['customer_ids'] = 'Select at least one active customer, or choose "All customers".';
            } elseif (count($customerIds) !== count(array_unique($requested))) {
                $errors['customer_ids'] = 'Some selected customers no longer exist or are inactive. Remove them and try again.';
            }
            $firstOrderOnly = !empty($post['first_order_also']) ? 1 : 0;
        } elseif ($custMode === self::CUSTOMERS_FIRST_ORDER) {
            $firstOrderOnly = 1;
        }

        // Existing excluded products are not editable here; keep them untouched.
        $excluded = ($couponId !== null && $couponId > 0) ? CouponRepository::excludedProductIds($couponId) : [];

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'data' => [
                'code' => $code,
                'internal_name' => $internal,
                'discount_type' => $type,
                'discount_value' => $value,
                'min_cart_value' => $min,
                'max_discount' => $maxDiscount,
                'usage_limit' => $usageLimit,
                'per_customer_limit' => $perCustomer,
                'first_order_only' => $firstOrderOnly,
                'stackable_with_offers' => !empty($post['stackable_with_offers']) ? 1 : 0,
                'priority' => $priority,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'is_active' => !empty($post['is_active']) ? 1 : 0,
            ],
            'customer_ids' => $customerIds,
            'category_ids' => $categoryIds,
            'product_ids' => $productIds,
            'excluded_ids' => $excluded,
        ];
    }

    /**
     * Validate and persist. Never writes when validation fails.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, errors: array<string, string>, id?: int, message?: string}
     */
    public static function save(?int $couponId, array $post): array
    {
        $v = self::validateInput($post, $couponId);
        if (!$v['ok']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        try {
            $id = Database::transaction(static function () use ($couponId, $v): int {
                return CouponRepository::save(
                    $couponId,
                    $v['data'],
                    $v['customer_ids'],
                    $v['category_ids'],
                    $v['product_ids'],
                    $v['excluded_ids']
                );
            });
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'errors' => ['code' => 'This coupon code already exists. Choose a different code.']];
            }
            error_log('CouponAdminService::save: ' . $e->getMessage());

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save coupon.'];
        }

        return ['ok' => true, 'errors' => [], 'id' => (int) $id];
    }

    /** @return list<array{id: int, name: string, parent: string}> */
    public static function categoriesForPicker(): array
    {
        Install::ensureConnection();
        $rows = Database::pdo()->query(
            'SELECT c.id, c.name, p.name AS parent_name
             FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
             ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'parent' => (string) ($r['parent_name'] ?? '')];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string, sku: string, category: string, variants: string}>
     */
    public static function searchProducts(string $q, int $limit = 20, array $ids = []): array
    {
        Install::ensureConnection();
        $limit = max(1, min(50, $limit));
        $where = ['1=1'];
        $params = [];
        if ($ids !== []) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $where[] = 'p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        } else {
            $q = trim($q);
            if ($q !== '') {
                $like = '%' . AdminServerList::escapeLike($q) . '%';
                $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR EXISTS (
                    SELECT 1 FROM product_variants v2 WHERE v2.product_id = p.id AND (v2.sku LIKE ? OR v2.name LIKE ?)))';
                array_push($params, $like, $like, $like, $like);
            }
        }
        $sql = 'SELECT p.id, p.name, p.sku, c.name AS category_name,
                    (SELECT GROUP_CONCAT(CONCAT(v.name, IF(v.sku IS NULL OR v.sku = \'\', \'\', CONCAT(\' [\', v.sku, \']\')))
                        ORDER BY v.sort_order, v.id SEPARATOR \', \')
                     FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) AS variants
                FROM products p LEFT JOIN categories c ON c.id = p.category_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.name ASC LIMIT ' . $limit;
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $variants = (string) ($r['variants'] ?? '');
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'sku' => (string) ($r['sku'] ?? ''),
                'category' => (string) ($r['category_name'] ?? ''),
                'variants' => mb_strlen($variants) > 120 ? mb_substr($variants, 0, 117) . '…' : $variants,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string, mobile: string, email: string}>
     */
    public static function searchCustomers(string $q, int $limit = 20, array $ids = []): array
    {
        Install::ensureConnection();
        $limit = max(1, min(50, $limit));
        $where = ['is_active = 1'];
        $params = [];
        if ($ids !== []) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $where = ['id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
            $params = $ids;
        } else {
            $q = trim($q);
            if ($q === '') {
                return [];
            }
            $like = '%' . AdminServerList::escapeLike($q) . '%';
            $digits = preg_replace('/\D+/', '', $q) ?? '';
            $conds = ['name LIKE ?', 'email LIKE ?', 'mobile LIKE ?'];
            $params = [$like, $like, $like];
            if ($digits !== '' && $digits !== $q) {
                $conds[] = 'mobile LIKE ?';
                $params[] = '%' . $digits . '%';
            }
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, mobile, email FROM customers WHERE ' . implode(' AND ', $where)
            . ' ORDER BY name ASC, id ASC LIMIT ' . $limit
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) ($r['name'] ?? ''),
                'mobile' => (string) ($r['mobile'] ?? ''),
                'email' => (string) ($r['email'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param mixed $raw @return list<int> */
    private static function idList($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ((array) $raw as $v) {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $out[(int) $v] = (int) $v;
            }
        }

        return array_values($out);
    }

    /** @param list<int> $ids @return list<int> only ids that exist */
    private static function existingIds(PDO $pdo, string $table, array $ids, string $extraWhere = '1=1'): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT id FROM {$table} WHERE {$extraWhere} AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string, mixed> $post @param array<string, string> $errors */
    private static function optionalMoney(array $post, string $key, string $label, array &$errors): ?float
    {
        $raw = trim((string) ($post[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 9999999) {
            $errors[$key] = $label . ' must be a number of 0 or more.';

            return null;
        }

        return round((float) $raw, 2);
    }

    /** @param array<string, mixed> $post @param array<string, string> $errors */
    private static function optionalInt(array $post, string $key, string $label, int $min, array &$errors): ?int
    {
        $raw = trim((string) ($post[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > 99999999) {
            $errors[$key] = $label . ' must be a whole number of ' . $min . ' or more (leave blank for unlimited).';

            return null;
        }

        return (int) $raw;
    }

    /** @param array<string, mixed> $post @param array<string, string> $errors */
    private static function optionalDate(array $post, string $key, string $label, array &$errors): ?string
    {
        $raw = trim((string) ($post[$key] ?? ''));
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            $errors[$key] = $label . ' is not a valid date and time.';

            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
