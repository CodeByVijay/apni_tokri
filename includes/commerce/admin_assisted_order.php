<?php

declare(strict_types=1);

/**
 * Admin-assisted order creation (Phase 1+: search, validation; placement in later phases).
 */
final class AdminAssistedOrderService
{
    public const PERMISSION = 'orders.create_on_behalf';
    public const PERMISSION_RECORD_OFFLINE = 'orders.record_offline_payment';
    public const MIN_SEARCH_LEN = 2;
    public const CUSTOMER_PER_PAGE = 20;
    public const VARIANT_SEARCH_LIMIT = 20;
    public const VARIANT_PAGE_LIMIT = 20;
    private const PLACE_SUBMISSION_TTL_SECONDS = 7200;
    private const PLACE_TOKEN_PREFIX = 'aplt';
    private const SESSION_ADDRESS_SUBMISSIONS = 'admin_assisted_address_submissions';

    public static function canCreateOnBehalf(): bool
    {
        if (!Install::isSchemaReady() || !AdminAuth::check()) {
            return false;
        }

        return Permission::can(self::PERMISSION);
    }

    public static function canRecordOfflinePayment(): bool
    {
        if (!self::canCreateOnBehalf()) {
            return false;
        }

        return Permission::can(self::PERMISSION_RECORD_OFFLINE);
    }

    public static function denyUnlessCanCreate(): void
    {
        AdminGuard::requireAuthentication();
        if (!self::canCreateOnBehalf()) {
            if (AdminGuard::isAjaxRequest()) {
                Ajax::error('Forbidden.', 403, 'forbidden');
            }
            http_response_code(403);
            admin_layout_start(['pageTitle' => 'Access denied', 'activeAdminNav' => 'orders']);
            echo '<div class="at-alert at-alert--error">You do not have permission to create orders on behalf of customers.</div>';
            admin_layout_end();
            exit;
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    public static function searchCustomers(string $query, int $page, int $perPage): array
    {
        if (!Install::isSchemaReady()) {
            return ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }
        $q = trim($query);
        if (mb_strlen($q) < self::MIN_SEARCH_LEN) {
            return ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $like = '%' . AdminServerList::escapeLike($q) . '%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $conds = ['c.name LIKE ?', 'c.email LIKE ?', 'c.mobile LIKE ?'];
        $params = [$like, $like, $like];
        if ($digits !== '' && $digits !== $q) {
            $conds[] = 'c.mobile LIKE ?';
            $params[] = '%' . $digits . '%';
        }
        $whereSql = '(' . implode(' OR ', $conds) . ')';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM customers c WHERE ' . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT c.id, c.name, c.mobile, c.email, c.is_active
                FROM customers c
                WHERE ' . $whereSql . '
                ORDER BY c.is_active DESC, c.name ASC, c.id ASC
                LIMIT ? OFFSET ?';
        $stmt = $pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $active = (int) ($row['is_active'] ?? 0) === 1;
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'mobile' => (string) ($row['mobile'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'is_active' => $active,
                'selectable' => $active,
                'status_label' => $active ? 'Active' : 'Inactive',
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function findCustomerForAssisted(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        return AdminCustomerService::findById($customerId);
    }

    /**
     * @return array{ok: bool, message?: string, items?: list<array<string, mixed>>}
     */
    public static function addressesForCustomer(int $customerId, ?int $actingForCustomerId = null): array
    {
        if ($customerId <= 0) {
            return ['ok' => false, 'message' => 'Invalid customer.'];
        }
        if ($actingForCustomerId !== null && $actingForCustomerId !== $customerId) {
            return ['ok' => false, 'message' => 'Address does not belong to the selected customer.'];
        }
        $customer = self::findCustomerForAssisted($customerId);
        if ($customer === null) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        if ((int) ($customer['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'This customer is inactive and cannot receive orders.'];
        }

        $rows = CustomerAccountService::listAddresses($customerId);
        $items = [];
        foreach ($rows as $addr) {
            $items[] = self::mapAddressListItem($addr);
        }

        return ['ok' => true, 'items' => $items];
    }

    /**
     * Create a saved address for the selected customer (admin-assisted checkout).
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message?: string, errors?: array<string, string>, address?: array<string, mixed>, reused?: bool}
     */
    public static function createAddressForCustomer(int $customerId, array $post): array
    {
        if (!self::canCreateOnBehalf()) {
            return ['ok' => false, 'message' => 'Forbidden.'];
        }
        if ($customerId <= 0) {
            return ['ok' => false, 'message' => 'Invalid customer.'];
        }
        $customer = self::findCustomerForAssisted($customerId);
        if ($customer === null) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        if ((int) ($customer['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'This customer is inactive and cannot receive orders.'];
        }

        $submissionKey = isset($post['address_submission_key']) && is_string($post['address_submission_key'])
            ? trim($post['address_submission_key']) : '';
        if ($submissionKey !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $submissionKey) === 1) {
            $cachedId = (int) (($_SESSION[self::SESSION_ADDRESS_SUBMISSIONS][$customerId][$submissionKey] ?? 0));
            if ($cachedId > 0) {
                $cachedRow = CustomerAccountService::findAddress($customerId, $cachedId);
                if ($cachedRow !== null) {
                    return [
                        'ok' => true,
                        'address' => self::mapAddressListItem($cachedRow),
                        'reused' => true,
                    ];
                }
            }
        }

        $input = $post;
        $input['address_confirmed'] = '1';
        $input['addr_checkout_autocomplete'] = '1';
        $makeDefault = !empty($post['make_default']);
        $added = CustomerAccountService::addAddress($customerId, $input, $makeDefault);
        if (empty($added['ok'])) {
            $errors = is_array($added['errors'] ?? null) ? $added['errors'] : [];
            $message = (string) ($errors['form'] ?? $errors['pincode'] ?? $errors['receiver_phone'] ?? 'Could not save this address.');

            return ['ok' => false, 'message' => $message, 'errors' => $errors];
        }

        $addressId = (int) ($added['id'] ?? 0);
        if ($addressId <= 0) {
            return ['ok' => false, 'message' => 'Could not save this address.'];
        }

        if ($submissionKey !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $submissionKey) === 1) {
            if (!isset($_SESSION[self::SESSION_ADDRESS_SUBMISSIONS]) || !is_array($_SESSION[self::SESSION_ADDRESS_SUBMISSIONS])) {
                $_SESSION[self::SESSION_ADDRESS_SUBMISSIONS] = [];
            }
            if (!isset($_SESSION[self::SESSION_ADDRESS_SUBMISSIONS][$customerId])
                || !is_array($_SESSION[self::SESSION_ADDRESS_SUBMISSIONS][$customerId])) {
                $_SESSION[self::SESSION_ADDRESS_SUBMISSIONS][$customerId] = [];
            }
            $_SESSION[self::SESSION_ADDRESS_SUBMISSIONS][$customerId][$submissionKey] = $addressId;
        }

        $adminId = AdminAuth::id();
        AuditLog::write(
            'customer.address_assisted_create',
            'customer_addresses',
            (string) $addressId,
            [
                'customer_id' => $customerId,
                'pincode_suffix' => substr(preg_replace('/\D+/', '', (string) ($post['pincode'] ?? '')) ?? '', -3),
                'make_default' => $makeDefault ? 1 : 0,
            ],
            $adminId
        );

        $row = CustomerAccountService::findAddress($customerId, $addressId);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Address saved but could not be loaded. Refresh and try again.'];
        }

        return [
            'ok' => true,
            'address' => self::mapAddressListItem($row),
            'reused' => false,
        ];
    }

    /** @param array<string, mixed> $addr */
    private static function mapAddressListItem(array $addr): array
    {
        $addrId = (int) ($addr['id'] ?? 0);

        return [
            'id' => $addrId,
            'label' => (string) ($addr['label'] ?? ''),
            'receiver_name' => (string) ($addr['receiver_name'] ?? ''),
            'receiver_phone' => (string) ($addr['receiver_phone'] ?? ''),
            'line1' => (string) ($addr['line1'] ?? ''),
            'line2' => (string) ($addr['line2'] ?? ''),
            'city' => (string) ($addr['city'] ?? ''),
            'state' => (string) ($addr['state'] ?? ''),
            'pincode' => (string) ($addr['pincode'] ?? ''),
            'full_address' => trim(AddressInput::fullAddress($addr)),
            'is_default' => !empty($addr['is_default']),
            'selectable' => true,
        ];
    }

    /**
     * @return array{ok: bool, message?: string, address?: array<string, mixed>}
     */
    public static function validateAddressOwnership(int $customerId, int $addressId): array
    {
        if ($customerId <= 0 || $addressId <= 0) {
            return ['ok' => false, 'message' => 'Select a customer and delivery address.'];
        }
        $customer = self::findCustomerForAssisted($customerId);
        if ($customer === null) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        if ((int) ($customer['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'This customer is inactive.'];
        }
        $addr = CustomerAccountService::findAddress($customerId, $addressId);
        if ($addr === null) {
            return ['ok' => false, 'message' => 'This address does not belong to the selected customer.'];
        }

        return ['ok' => true, 'address' => $addr];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int}
     */
    public static function searchVariants(string $query, int $page, int $perPage): array
    {
        if (!Install::isSchemaReady()) {
            return ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }
        $q = trim($query);
        if (mb_strlen($q) < self::MIN_SEARCH_LEN) {
            return ['items' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }

        Install::ensureConnection();
        $pdo = Database::pdo();
        $page = max(1, $page);
        $perPage = max(1, min(self::VARIANT_PAGE_LIMIT, $perPage));
        $like = '%' . AdminServerList::escapeLike($q) . '%';
        $where = '(p.name LIKE ? OR p.slug LIKE ? OR p.sku LIKE ? OR v.sku LIKE ? OR v.name LIKE ?)';
        $params = [$like, $like, $like, $like, $like];

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE ' . $where
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            'SELECT v.id, v.name AS variant_name, v.sku, v.price, v.sale_price, v.stock_qty, v.is_active,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug, p.status AS product_status,
                    (SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image_path
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE ' . $where . '
             ORDER BY (v.is_active = 1 AND p.status = \'published\') DESC, p.name, v.sort_order, v.name
             LIMIT ? OFFSET ?'
        );
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::decorateSearchVariant($row);
        }

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function decorateSearchVariant(array $r): array
    {
        $variantId = (int) ($r['id'] ?? 0);
        $published = (string) ($r['product_status'] ?? '') === 'published';
        $variantActive = !empty($r['is_active']);
        $purchasable = $published && $variantActive;
        $prices = catalogue_variant_prices($r);
        $stockRaw = (int) ($r['stock_qty'] ?? 0);
        $available = InventoryService::availableQty($variantId);
        $image = trim((string) ($r['image_path'] ?? ''));
        $reason = '';
        if (!$purchasable) {
            $reason = !$variantActive ? 'Variant is inactive' : 'Product is not published';
        }

        return [
            'variant_id' => $variantId,
            'product_id' => (int) ($r['product_id'] ?? 0),
            'product_name' => (string) ($r['product_name'] ?? ''),
            'product_slug' => (string) ($r['product_slug'] ?? ''),
            'variant_name' => (string) ($r['variant_name'] ?? ''),
            'sku' => (string) ($r['sku'] ?? ''),
            'unit_price' => round($prices['display'], 2),
            'regular_price' => round($prices['regular'], 2),
            'on_sale' => !empty($prices['on_sale']),
            'stock_qty' => $stockRaw,
            'available_qty' => $available,
            'image_url' => $image !== '' ? asset_url($image) : '',
            'purchasable' => $purchasable,
            'selectable' => $purchasable,
            'unavailable_reason' => $reason,
        ];
    }

    /**
     * Server-side validation when admin adds or updates a line (no stock mutation).
     *
     * @return array{ok: bool, message?: string, line?: array<string, mixed>}
     */
    public static function validateLineItem(int $variantId, int $quantity, ?int $customerId = null): array
    {
        if ($variantId <= 0) {
            return ['ok' => false, 'message' => 'Select a valid product variant.'];
        }
        if ($quantity < 1) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1.'];
        }
        if ($customerId !== null && $customerId > 0) {
            $cust = self::findCustomerForAssisted($customerId);
            if ($cust === null) {
                return ['ok' => false, 'message' => 'Customer not found.'];
            }
            if ((int) ($cust['is_active'] ?? 0) !== 1) {
                return ['ok' => false, 'message' => 'Customer is inactive.'];
            }
        }

        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT v.id, v.name AS variant_name, v.sku, v.price, v.sale_price, v.stock_qty, v.is_active,
                    v.min_qty_per_order, v.max_qty_per_order,
                    p.id AS product_id, p.category_id, p.name AS product_name, p.slug AS product_slug,
                    p.status AS product_status, p.grants_free_delivery,
                    (SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id
                     ORDER BY pi.is_primary DESC, pi.sort_order, pi.id LIMIT 1) AS image_path
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.id = ?
             LIMIT 1'
        );
        $stmt->execute([$variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Variant not found.'];
        }
        if ((string) ($row['product_status'] ?? '') !== 'published' || empty($row['is_active'])) {
            return ['ok' => false, 'message' => 'This product is not available for purchase.'];
        }

        $qtyCheck = CartQuantityRules::validateForVariantId($variantId, $quantity);
        if (empty($qtyCheck['ok'])) {
            return ['ok' => false, 'message' => (string) ($qtyCheck['message'] ?? 'Invalid quantity.')];
        }

        $available = InventoryService::availableQty($variantId);
        if ($available < $quantity) {
            return [
                'ok' => false,
                'message' => $available <= 0
                    ? 'This item is out of stock.'
                    : 'Only ' . $available . ' unit(s) available.',
            ];
        }

        $prices = catalogue_variant_prices($row);
        $unit = round((float) $prices['display'], 2);
        $image = trim((string) ($row['image_path'] ?? ''));

        return [
            'ok' => true,
            'line' => [
                'variant_id' => $variantId,
                'product_id' => (int) ($row['product_id'] ?? 0),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'variant_name' => (string) ($row['variant_name'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unit,
                'line_total' => round($unit * $quantity, 2),
                'available_qty' => $available,
                'image_url' => $image !== '' ? asset_url($image) : '',
                'grants_free_delivery' => !empty($row['grants_free_delivery']),
            ],
        ];
    }

    public static function columnsReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'orders'
                   AND COLUMN_NAME IN ('created_by_admin_id', 'admin_meta_json')"
            );

            return (int) $stmt->fetchColumn() === 2;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param list<array{variant_id?: mixed, quantity?: mixed}>|string|null $rawLines
     * @return array{ok: bool, message?: string, lines?: list<array<string, mixed>>, pricing_lines?: list<array<string, mixed>>}
     */
    public static function buildLinesFromRequest($rawLines, int $customerId): array
    {
        if (is_string($rawLines)) {
            try {
                $decoded = json_decode($rawLines, true, 512, JSON_THROW_ON_ERROR);
                $rawLines = is_array($decoded) ? $decoded : [];
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => 'Invalid order items.'];
            }
        }
        if (!is_array($rawLines) || $rawLines === []) {
            return ['ok' => false, 'message' => 'Add at least one product to the order.'];
        }

        $merged = [];
        foreach ($rawLines as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vid = (int) ($row['variant_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $merged[$vid] = ($merged[$vid] ?? 0) + $qty;
        }
        if ($merged === []) {
            return ['ok' => false, 'message' => 'Add at least one valid product line.'];
        }

        $lines = [];
        $pricingLines = [];
        foreach ($merged as $variantId => $quantity) {
            $check = self::validateLineItem($variantId, $quantity, $customerId);
            if (empty($check['ok'])) {
                return ['ok' => false, 'message' => (string) ($check['message'] ?? 'Invalid line item.')];
            }
            $line = $check['line'] ?? [];
            $lines[] = $line;
            $pricingLines[] = [
                'qty' => $quantity,
                'unit_price' => (float) ($line['unit_price'] ?? 0),
                'regular_price' => (float) ($line['unit_price'] ?? 0),
                'on_sale' => false,
                'grants_free_delivery' => !empty($line['grants_free_delivery']),
                'product_id' => (int) ($line['product_id'] ?? 0),
                'category_id' => (int) ($line['category_id'] ?? 0),
                'variant_id' => $variantId,
                'name' => (string) ($line['product_name'] ?? ''),
                'label' => (string) ($line['variant_name'] ?? ''),
            ];
        }

        return ['ok' => true, 'lines' => $lines, 'pricing_lines' => $pricingLines];
    }

    /**
     * Full server quote for admin-assisted order (delivery, coupon, custom discount).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function quote(array $input, bool $strictForPlacement = false): array
    {
        if (!self::columnsReady()) {
            return ['ok' => false, 'message' => 'Assisted-order schema is not ready. Apply migration 1.40.0.'];
        }

        $customerId = (int) ($input['customer_id'] ?? 0);
        $addressId = (int) ($input['address_id'] ?? 0);
        $addrCheck = self::validateAddressOwnership($customerId, $addressId);
        if (empty($addrCheck['ok'])) {
            return ['ok' => false, 'message' => (string) ($addrCheck['message'] ?? 'Invalid address.')];
        }
        $address = $addrCheck['address'] ?? [];

        $built = self::buildLinesFromRequest($input['lines'] ?? null, $customerId);
        if (empty($built['ok'])) {
            return $built;
        }
        $displayLines = $built['lines'] ?? [];
        $pricingLines = $built['pricing_lines'] ?? [];

        $applyDelivery = self::inputBool($input, 'apply_delivery_charges', true);
        $freeDelivery = $applyDelivery && self::inputBool($input, 'free_delivery', false);
        $freeDeliveryReason = isset($input['free_delivery_reason']) && is_string($input['free_delivery_reason'])
            ? trim($input['free_delivery_reason']) : '';

        if ($freeDelivery && $freeDeliveryReason === '') {
            return ['ok' => false, 'message' => 'Enter a reason for the free delivery override.'];
        }

        $couponCode = isset($input['coupon_code']) && is_string($input['coupon_code'])
            ? trim($input['coupon_code']) : '';
        if ($couponCode === '') {
            $couponCode = null;
        }

        $customEnabled = self::inputBool($input, 'custom_discount_enabled', false);
        $customType = isset($input['custom_discount_type']) && is_string($input['custom_discount_type'])
            ? strtolower(trim($input['custom_discount_type'])) : '';
        $customValue = isset($input['custom_discount_value']) ? (float) $input['custom_discount_value'] : 0.0;
        $customReason = isset($input['custom_discount_reason']) && is_string($input['custom_discount_reason'])
            ? trim($input['custom_discount_reason']) : '';

        if ($customEnabled) {
            if ($customReason === '') {
                return ['ok' => false, 'message' => 'Enter a reason for the custom discount.'];
            }
            if (!in_array($customType, ['flat', 'percent'], true)) {
                return ['ok' => false, 'message' => 'Choose a valid custom discount type.'];
            }
            if ($customValue <= 0) {
                return ['ok' => false, 'message' => 'Custom discount value must be greater than zero.'];
            }
            if ($customType === 'percent' && $customValue > 100) {
                return ['ok' => false, 'message' => 'Percentage discount cannot exceed 100%.'];
            }
        }

        $pin = preg_replace('/\D+/', '', (string) ($address['pincode'] ?? '')) ?? '';
        $totals = PricingService::totalsFromLines($pricingLines, $couponCode, $pin !== '' ? $pin : null, $customerId);
        if (!empty($totals['coupon_error'])) {
            return ['ok' => false, 'message' => (string) $totals['coupon_error']];
        }

        $scheduleMode = isset($input['delivery_schedule_mode']) && $input['delivery_schedule_mode'] === 'custom'
            ? 'custom' : 'slot';
        $customDelivery = null;
        $slot = isset($input['delivery_slot']) && is_string($input['delivery_slot']) ? trim($input['delivery_slot']) : '';
        $deliveryDate = isset($input['delivery_date']) && is_string($input['delivery_date']) ? trim($input['delivery_date']) : '';
        $quoteSlotSlug = $slot;
        if (!DeliveryConfig::slotsEnabled()) {
            $slot = $slot === '' ? 'auto' : $slot;
            $deliveryDate = '';
            $scheduleMode = 'slot';
        } elseif ($scheduleMode === 'custom') {
            $parsed = self::parseCustomDeliveryInput($input);
            if (empty($parsed['ok'])) {
                return ['ok' => false, 'message' => (string) ($parsed['message'] ?? 'Invalid custom delivery.')];
            }
            $customDelivery = $parsed['custom'] ?? [];
            $deliveryDate = (string) ($customDelivery['delivery_date'] ?? '');
            $quoteSlotSlug = self::referenceSlotSlugForCustomQuote();
            if ($quoteSlotSlug === '') {
                return ['ok' => false, 'message' => 'No delivery slots are configured for custom scheduling.'];
            }
            $slot = 'custom';
        } else {
            $resolved = self::resolveAdminDeliverySlot($slot, $deliveryDate);
            if ($resolved['slot'] === '') {
                return ['ok' => false, 'message' => 'No delivery slots are available right now.'];
            }
            $slot = $resolved['slot'];
            $deliveryDate = $resolved['delivery_date'];
            $quoteSlotSlug = $slot;
            if ($slot === '' || !DeliveryService::validateSlot($slot)) {
                return ['ok' => false, 'message' => 'Choose a valid delivery slot.'];
            }
            $slotErr = self::validateResolvedAdminSlot($slot, $deliveryDate);
            if ($slotErr !== null) {
                return ['ok' => false, 'message' => $slotErr];
            }
        }

        $slotDateParam = DeliveryConfig::slotsEnabled() && $deliveryDate !== '' ? $deliveryDate : null;
        $deliveryQuote = DeliveryQuoteService::quoteForCheckout(
            $address,
            $pricingLines,
            $quoteSlotSlug !== '' ? $quoteSlotSlug : 'auto',
            $totals,
            null,
            null,
            $slotDateParam
        );

        if (empty($deliveryQuote['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($deliveryQuote['message'] ?? 'Delivery is not available for this address.'),
            ];
        }

        $calculatedDeliveryFee = round((float) ($deliveryQuote['delivery_fee'] ?? 0), 2);
        $deliveryFee = $calculatedDeliveryFee;
        $deliveryMode = 'calculated';

        if (!$applyDelivery) {
            $deliveryFee = 0.0;
            $deliveryMode = 'disabled';
        } elseif ($freeDelivery) {
            $deliveryFee = 0.0;
            $deliveryMode = 'free_override';
        }

        $subtotal = round((float) ($totals['subtotal'] ?? 0), 2);
        $offerDiscount = round((float) ($totals['offer_discount'] ?? 0), 2);
        $couponDiscount = round((float) ($totals['coupon_discount'] ?? 0), 2);
        $merchAfterAuto = max(0, round($subtotal - $offerDiscount - $couponDiscount, 2));

        $customDiscountAmount = 0.0;
        if ($customEnabled) {
            if ($customType === 'percent') {
                $customDiscountAmount = round($merchAfterAuto * ($customValue / 100), 2);
            } else {
                $customDiscountAmount = round(min($customValue, $merchAfterAuto), 2);
            }
            if ($customDiscountAmount < 0) {
                $customDiscountAmount = 0.0;
            }
            $maxTotalDiscount = $subtotal;
            if ($offerDiscount + $couponDiscount + $customDiscountAmount > $maxTotalDiscount + 0.001) {
                return ['ok' => false, 'message' => 'Total discounts cannot exceed the merchandise subtotal.'];
            }
        }

        $merchPayable = max(0, round($merchAfterAuto - $customDiscountAmount, 2));
        $grandTotal = round($merchPayable + $deliveryFee, 2);
        if ($grandTotal < 0) {
            return ['ok' => false, 'message' => 'Order total cannot be negative.'];
        }

        $minRejection = MarketingSettings::checkoutMinimumRejection(array_merge($totals, [
            'total' => $grandTotal,
            'delivery_fee' => $deliveryFee,
        ]));
        if ($strictForPlacement && $minRejection !== null) {
            return ['ok' => false, 'message' => $minRejection];
        }

        $paymentMode = self::normalizePaymentMode($input);
        if ($paymentMode === 'offline_paid' && !self::canRecordOfflinePayment()) {
            return ['ok' => false, 'message' => 'You do not have permission to record offline payments.'];
        }

        $adminId = AdminAuth::id();
        $adminMeta = self::buildAdminMeta([
            'apply_delivery_charges' => $applyDelivery,
            'delivery_mode' => $deliveryMode,
            'calculated_delivery_fee' => $calculatedDeliveryFee,
            'applied_delivery_fee' => $deliveryFee,
            'free_delivery_reason' => $freeDelivery ? $freeDeliveryReason : null,
            'coupon_code' => $totals['coupon_code'] ?? null,
            'custom_enabled' => $customEnabled,
            'custom_type' => $customEnabled ? $customType : null,
            'custom_value' => $customEnabled ? $customValue : null,
            'custom_amount' => $customDiscountAmount,
            'custom_reason' => $customEnabled ? $customReason : null,
            'admin_user_id' => $adminId,
            'delivery_schedule_mode' => $scheduleMode,
            'custom_delivery' => $customDelivery,
            'payment_mode' => $paymentMode,
        ]);

        $quoteSignature = self::buildQuoteSignature(
            $customerId,
            $addressId,
            $displayLines,
            $applyDelivery,
            $freeDelivery,
            $freeDeliveryReason,
            $couponCode,
            $customEnabled,
            $customType,
            $customValue,
            $customReason,
            $slot,
            $deliveryDate,
            $grandTotal,
            $scheduleMode,
            $customDelivery,
            $paymentMode
        );

        $placeSubmissionToken = self::issuePlaceSubmissionToken(
            (int) ($adminId ?? 0),
            $customerId,
            $quoteSignature
        );

        return [
            'ok' => true,
            'quote' => [
                'lines' => $displayLines,
                'subtotal' => $subtotal,
                'offer_discount' => $offerDiscount,
                'offer_label' => $totals['offer_label'] ?? null,
                'coupon_code' => $totals['coupon_code'] ?? null,
                'coupon_discount' => $couponDiscount,
                'custom_discount' => $customDiscountAmount,
                'custom_discount_enabled' => $customEnabled,
                'merchandise_after_discounts' => $merchPayable,
                'calculated_delivery_fee' => $calculatedDeliveryFee,
                'delivery_fee' => $deliveryFee,
                'delivery_mode' => $deliveryMode,
                'apply_delivery_charges' => $applyDelivery,
                'free_delivery' => $freeDelivery,
                'grand_total' => $grandTotal,
                'discount_total' => round($offerDiscount + $couponDiscount + $customDiscountAmount, 2),
                'delivery_zone' => $deliveryQuote['zone_name'] ?? ($totals['delivery_zone'] ?? null),
                'delivery_slot' => $slot,
                'delivery_date' => $deliveryDate !== '' ? $deliveryDate : ($deliveryQuote['delivery_date'] ?? ''),
                'delivery_schedule_mode' => $scheduleMode,
                'custom_delivery' => $customDelivery,
                'payment_mode' => $paymentMode,
                'coupon_applied' => $couponCode !== null && $couponCode !== '' && $couponDiscount > 0,
                'delivery_serviceable' => true,
                'quote_signature' => $quoteSignature,
                'place_submission_token' => $placeSubmissionToken,
                'admin_meta' => $adminMeta,
                'min_order_met' => $minRejection === null,
                'min_order_message' => $minRejection,
            ],
            'delivery_quote' => $deliveryQuote,
            'address' => $address,
        ];
    }

    /**
     * Create pending-payment admin-assisted order and queue customer notification.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function placeOrder(array $input): array
    {
        $adminId = AdminAuth::id();
        if ($adminId === null) {
            return ['ok' => false, 'message' => 'Admin session required.'];
        }
        if (!self::columnsReady()) {
            return ['ok' => false, 'message' => 'Assisted-order schema is not ready.'];
        }

        $placeSubmissionToken = isset($input['place_submission_token']) && is_string($input['place_submission_token'])
            ? trim($input['place_submission_token']) : '';
        if ($placeSubmissionToken === '') {
            return ['ok' => false, 'message' => 'Refresh the quote, then create the order.'];
        }

        $paymentMode = self::normalizePaymentMode($input);
        if ($paymentMode === 'offline_paid') {
            if (!self::canRecordOfflinePayment()) {
                return ['ok' => false, 'message' => 'You do not have permission to record offline payments.'];
            }
            $offlineCheck = self::validateOfflinePaymentInput($input);
            if (empty($offlineCheck['ok'])) {
                return ['ok' => false, 'message' => (string) ($offlineCheck['message'] ?? 'Invalid offline payment.')];
            }
        }

        $quoted = self::quote($input, true);
        if (empty($quoted['ok'])) {
            return $quoted;
        }

        $customerId = (int) ($input['customer_id'] ?? 0);
        $addressId = (int) ($input['address_id'] ?? 0);
        $quote = $quoted['quote'] ?? [];
        $deliveryQuote = $quoted['delivery_quote'] ?? [];
        $address = $quoted['address'] ?? [];
        $displayLines = $quote['lines'] ?? [];
        $pricingLines = [];
        foreach ($displayLines as $line) {
            $pricingLines[] = [
                'variant_id' => (int) ($line['variant_id'] ?? 0),
                'qty' => (int) ($line['quantity'] ?? 0),
            ];
        }

        $clientGrand = isset($input['client_grand_total']) ? round((float) $input['client_grand_total'], 2) : null;
        $serverGrand = round((float) ($quote['grand_total'] ?? 0), 2);
        if ($clientGrand !== null && abs($clientGrand - $serverGrand) > 0.009) {
            return ['ok' => false, 'message' => 'Order total changed. Refresh the quote and try again.'];
        }
        if ($paymentMode === 'offline_paid') {
            $offlineAmt = isset($input['offline_payment_amount']) ? round((float) $input['offline_payment_amount'], 2) : 0.0;
            if (abs($offlineAmt - $serverGrand) > 0.009) {
                return ['ok' => false, 'message' => 'Amount received must match the order payable total.'];
            }
        }

        $quoteSignature = (string) ($quote['quote_signature'] ?? '');
        $checkoutNonce = self::verifyPlaceSubmissionToken(
            $placeSubmissionToken,
            $adminId,
            $customerId,
            $quoteSignature
        );
        if ($checkoutNonce === null) {
            return ['ok' => false, 'message' => 'This quote session has expired. Refresh the quote and try again.'];
        }

        $scheduleMode = (string) ($quote['delivery_schedule_mode'] ?? 'slot');
        $customDelivery = is_array($quote['custom_delivery'] ?? null) ? $quote['custom_delivery'] : null;
        $slot = (string) ($quote['delivery_slot'] ?? 'auto');
        $deliveryDate = isset($quote['delivery_date']) ? (string) $quote['delivery_date'] : '';
        $slotId = isset($deliveryQuote['slot_id']) ? (int) $deliveryQuote['slot_id'] : null;
        $deliveryEta = $deliveryQuote['delivery_eta'] ?? null;
        $zoneId = isset($deliveryQuote['zone_id']) ? (int) $deliveryQuote['zone_id'] : null;
        $capacityOverride = false;
        if ($scheduleMode === 'custom' && is_array($customDelivery)) {
            $cap = self::resolveCapacitySlotForCustomDelivery(0, $deliveryDate, 0, Permission::can('deliveries.manage'));
            $slotId = (int) ($cap['slot_id'] ?? 0);
            $capacityOverride = !empty($cap['capacity_override']);
            if ($slotId <= 0) {
                return ['ok' => false, 'message' => 'No delivery capacity bucket is available for the custom window.'];
            }
            $slot = 'custom';
            $deliveryEta = (string) ($customDelivery['window_label'] ?? $deliveryEta);
        }
        if ($zoneId === 0) {
            $zoneId = null;
        }

        $customerRow = self::findCustomerForAssisted($customerId);
        if ($customerRow === null) {
            return ['ok' => false, 'message' => 'Customer not found.'];
        }
        $addrSnapshot = DeliveryAddressSnapshot::fromAddressRow($address, $customerRow);
        if ($addrSnapshot['receiver_name'] === '' || $addrSnapshot['receiver_phone'] === '') {
            return ['ok' => false, 'message' => 'Add a receiver name and phone to this address before placing the order.'];
        }

        $snapshotData = DeliveryQuoteService::buildSnapshot($deliveryQuote, $addrSnapshot);
        if ($scheduleMode === 'custom' && is_array($customDelivery) && $slotId !== null && $slotId > 0) {
            Install::ensureConnection();
            $slotRowStmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE id = ? LIMIT 1');
            $slotRowStmt->execute([$slotId]);
            $slotRow = $slotRowStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($slotRow)) {
                $windowLabel = (string) ($customDelivery['window_label'] ?? '');
                $snapshotData = DeliveryOrderSnapshot::applyAdminCustomDeliveryWindow(
                    $snapshotData,
                    $slotRow,
                    $deliveryDate,
                    (string) ($customDelivery['start_time'] ?? ''),
                    (string) ($customDelivery['end_time'] ?? ''),
                    $windowLabel,
                    $capacityOverride
                );
                $snapshotData['assisted_custom_reason'] = (string) ($customDelivery['reason'] ?? '');
            }
        }
        $adminMeta = is_array($quote['admin_meta'] ?? null) ? $quote['admin_meta'] : [];
        $adminMeta['placed_at'] = date('c');
        if ($paymentMode === 'offline_paid') {
            $adminMeta['offline_payment'] = self::offlinePaymentMetaFromInput($input, $adminId);
        }
        $adminMetaJson = json_encode($adminMeta, JSON_UNESCAPED_UNICODE);
        $snapJson = json_encode($snapshotData, JSON_UNESCAPED_UNICODE);

        $status = OrderStatus::PENDING_PAYMENT;
        $paymentStatus = PaymentStatus::PENDING;
        $couponCode = isset($quote['coupon_code']) && $quote['coupon_code'] !== null && $quote['coupon_code'] !== ''
            ? (string) $quote['coupon_code'] : null;

        try {
            $placed = Database::transaction(static function () use (
                $customerId,
                $addressId,
                $adminId,
                $displayLines,
                $quote,
                $slot,
                $slotId,
                $deliveryDate,
                $deliveryEta,
                $zoneId,
                $snapJson,
                $adminMetaJson,
                $checkoutNonce,
                $status,
                $paymentStatus,
                $couponCode,
                $deliveryQuote,
                $paymentMode,
                $serverGrand,
                $input
            ): array {
                $pdo = Database::pdo();

                $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE')->execute([$customerId]);

                $dupSql = $paymentMode === 'offline_paid'
                    ? "SELECT id, order_number FROM orders
                       WHERE checkout_nonce = ? AND order_source = 'admin_assisted' AND customer_id = ?
                         AND payment_status = 'paid'
                       LIMIT 1 FOR UPDATE"
                    : "SELECT id, order_number FROM orders
                       WHERE checkout_nonce = ? AND order_source = 'admin_assisted' AND customer_id = ?
                         AND status = 'pending_payment' AND payment_status = 'pending'
                       LIMIT 1 FOR UPDATE";
                $dup = $pdo->prepare($dupSql);
                $dup->execute([$checkoutNonce, $customerId]);
                $existing = $dup->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    return [
                        'ok' => true,
                        'order_id' => (int) $existing['id'],
                        'order_number' => (string) $existing['order_number'],
                        'reused' => true,
                        'payment_mode' => $paymentMode,
                    ];
                }

                if ($slotId !== null && $slotId > 0 && $deliveryDate !== '') {
                    DeliverySlotReservationService::lockSlotAndAssertCapacity($pdo, (int) $slotId, $deliveryDate, null);
                }

                $orderNumber = OrderNumberService::allocate($pdo, 'storefront');

                $pdo->prepare(
                    'INSERT INTO orders (order_number, order_source, customer_id, customer_address_id, created_by_admin_id,
                     status, subtotal, discount_total, delivery_fee, grand_total, payment_status, delivery_slot,
                     delivery_zone_id, delivery_slot_id, delivery_date, delivery_eta, delivery_snapshot_json,
                     admin_meta_json, coupon_code, checkout_nonce, placed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                )->execute([
                    $orderNumber,
                    'admin_assisted',
                    $customerId,
                    $addressId,
                    $adminId,
                    $status,
                    $quote['subtotal'],
                    $quote['discount_total'],
                    $quote['delivery_fee'],
                    $quote['grand_total'],
                    $paymentStatus,
                    $slot,
                    $zoneId,
                    $slotId,
                    $deliveryDate !== '' ? $deliveryDate : ($deliveryQuote['delivery_date'] ?? null),
                    $deliveryEta,
                    $snapJson,
                    $adminMetaJson,
                    $couponCode,
                    $checkoutNonce,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO order_items (order_id, variant_id, product_name, variant_name, sku, quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($displayLines as $line) {
                    $itemStmt->execute([
                        $orderId,
                        (int) $line['variant_id'],
                        (string) $line['product_name'],
                        (string) $line['variant_name'],
                        (string) ($line['sku'] ?? ''),
                        (int) $line['quantity'],
                        (float) $line['unit_price'],
                        (float) $line['line_total'],
                    ]);
                }

                $pdo->prepare(
                    'INSERT INTO order_status_history (order_id, status, note, admin_user_id) VALUES (?, ?, ?, ?)'
                )->execute([
                    $orderId,
                    'Awaiting payment',
                    'Admin assisted order — awaiting customer payment',
                    $adminId,
                ]);

                if ($slotId !== null && $slotId > 0 && $deliveryDate !== ''
                    && DeliverySlotReservationService::tableReady()) {
                    DeliverySlotReservationService::createHold($pdo, (int) $slotId, $deliveryDate, $orderId);
                }
                if ($paymentMode === 'pending' && UnpaidOrderExpiryService::columnReady()) {
                    $expAt = UnpaidOrderExpiryService::computeExpiryFromPlacement(date('Y-m-d H:i:s'));
                    $pdo->prepare('UPDATE orders SET payment_expires_at = ? WHERE id = ?')->execute([$expAt, $orderId]);
                }

                $offlineIdempotency = null;
                if ($paymentMode === 'offline_paid') {
                    $offlineIdempotency = AdminAssistedOrderService::insertOfflinePaymentRow(
                        $pdo,
                        $orderId,
                        $orderNumber,
                        $serverGrand,
                        $input,
                        $adminId,
                        $checkoutNonce
                    );
                }

                return [
                    'ok' => true,
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'reused' => false,
                    'payment_mode' => $paymentMode,
                    'offline_idempotency' => $offlineIdempotency,
                ];
            });
        } catch (Throwable $e) {
            error_log('AdminAssistedOrderService::placeOrder: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not create the order. Please try again.'];
        }

        if (empty($placed['ok'])) {
            return ['ok' => false, 'message' => (string) ($placed['message'] ?? 'Could not create order.')];
        }

        $orderId = (int) $placed['order_id'];
        $orderNumber = (string) $placed['order_number'];
        $reused = !empty($placed['reused']);

        $paymentModePlaced = (string) ($placed['payment_mode'] ?? $paymentMode);
        $offlineComplete = null;

        if ($paymentModePlaced === 'offline_paid') {
            $idem = isset($placed['offline_idempotency']) && is_array($placed['offline_idempotency'])
                ? $placed['offline_idempotency'] : null;
            if (!$reused && $idem !== null) {
                $ref = (string) ($idem['gateway_payment_id'] ?? '');
                $offlineComplete = PaymentService::completePayment(
                    $orderNumber,
                    'offline',
                    $ref,
                    '',
                    $serverGrand,
                    'INR',
                    (string) ($idem['idempotency_key'] ?? ''),
                    'offline_assisted:' . $adminId . ':' . $checkoutNonce
                );
                if (empty($offlineComplete['ok'])) {
                    return [
                        'ok' => false,
                        'message' => (string) ($offlineComplete['message'] ?? 'Order was created but offline payment could not be confirmed.'),
                        'order_id' => $orderId,
                        'order_number' => $orderNumber,
                    ];
                }
            }
            if (!$reused) {
                AuditLog::write('order.offline_payment_recorded', 'orders', (string) $orderId, [
                    'order_number' => $orderNumber,
                    'customer_id' => $customerId,
                    'grand_total' => $serverGrand,
                    'method' => (string) ($input['offline_payment_method'] ?? ''),
                    'reference' => (string) ($input['offline_payment_reference'] ?? ''),
                ], $adminId);
                AuditLog::write('order.assisted_create', 'orders', (string) $orderId, [
                    'order_number' => $orderNumber,
                    'customer_id' => $customerId,
                    'grand_total' => $quote['grand_total'],
                    'payment_mode' => 'offline_paid',
                ], $adminId);
            }
        } elseif (!$reused) {
            NotificationDispatcher::orderPlacedPendingPayment($orderId);
            AuditLog::write('order.assisted_create', 'orders', (string) $orderId, [
                'order_number' => $orderNumber,
                'customer_id' => $customerId,
                'grand_total' => $quote['grand_total'],
                'payment_mode' => 'pending',
            ], $adminId);
        }

        $mobile = (string) ($customerRow['mobile'] ?? '');
        $paymentInit = null;
        if ($paymentModePlaced === 'pending') {
            $liveReady = PaymentGatewayConfig::assertLivePaymentsReady();
            if (PaymentGatewayConfig::useSimulatedCheckout() || !empty($liveReady['ok'])) {
                $paymentInit = PaymentService::initiateForOrder(
                    $orderId,
                    $orderNumber,
                    $serverGrand,
                    $mobile
                );
            }
        }

        $paymentUrl = $paymentModePlaced === 'pending'
            ? OrderEmailContext::customerPaymentRetryUrl($orderNumber)
            : null;

        return [
            'ok' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'reused' => $reused,
            'payment_mode' => $paymentModePlaced,
            'payment_status' => $paymentModePlaced === 'offline_paid' ? 'paid' : 'pending',
            'grand_total' => $serverGrand,
            'payment_url' => $paymentUrl,
            'payment_initiated' => is_array($paymentInit) && !empty($paymentInit['ok']),
            'payment_error' => is_array($paymentInit) && empty($paymentInit['ok'])
                ? (string) ($paymentInit['error'] ?? '') : null,
            'offline_payment_captured' => $paymentModePlaced === 'offline_paid'
                && (empty($offlineComplete) || !empty($offlineComplete['ok'])),
            'admin_order_url' => url_base() . '/admin/orders/view.php?id=' . $orderId,
        ];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function resendPendingPaymentEmail(int $orderId): array
    {
        if (!self::canCreateOnBehalf()) {
            return ['ok' => false, 'message' => 'Forbidden.'];
        }
        if ($orderId <= 0 || !Install::isSchemaReady()) {
            return ['ok' => false, 'message' => 'Invalid order.'];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT id, order_number, order_source, status, payment_status, customer_id FROM orders WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        if ((string) ($row['order_source'] ?? '') !== 'admin_assisted') {
            return ['ok' => false, 'message' => 'Resend is only available for admin-assisted orders.'];
        }
        if ((string) ($row['payment_status'] ?? '') === 'paid' || (string) ($row['status'] ?? '') === 'cancelled') {
            return ['ok' => false, 'message' => 'This order is not awaiting payment.'];
        }
        $recipient = OrderEmailContext::resolveRecipientForOrderId($orderId);
        if ($recipient === null) {
            return ['ok' => false, 'message' => 'Customer does not have a valid email on file.'];
        }

        NotificationDispatcher::orderPlacedPendingPayment($orderId);
        $adminId = AdminAuth::id();
        AuditLog::write('order.assisted_payment_email_resend', 'orders', (string) $orderId, [
            'order_number' => (string) ($row['order_number'] ?? ''),
        ], $adminId);

        return ['ok' => true, 'message' => 'Payment notification queued for delivery.'];
    }

    /** @param array<string, mixed> $input */
    private static function inputBool(array $input, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $v = $input[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v) || is_int($v)) {
            return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $parts
     * @return array<string, mixed>
     */
    private static function buildAdminMeta(array $parts): array
    {
        return [
            'schema' => '1.40-admin-assisted',
            'source' => 'admin_assisted',
            'recorded_at' => date('c'),
            'delivery' => [
                'apply_charges' => !empty($parts['apply_delivery_charges']),
                'mode' => (string) ($parts['delivery_mode'] ?? 'calculated'),
                'calculated_fee' => round((float) ($parts['calculated_delivery_fee'] ?? 0), 2),
                'applied_fee' => round((float) ($parts['applied_delivery_fee'] ?? 0), 2),
                'free_delivery_reason' => $parts['free_delivery_reason'] ?? null,
            ],
            'coupon' => [
                'code' => $parts['coupon_code'] ?? null,
            ],
            'custom_discount' => [
                'enabled' => !empty($parts['custom_enabled']),
                'type' => $parts['custom_type'] ?? null,
                'value' => isset($parts['custom_value']) ? (float) $parts['custom_value'] : null,
                'amount' => round((float) ($parts['custom_amount'] ?? 0), 2),
                'reason' => $parts['custom_reason'] ?? null,
            ],
            'admin_user_id' => isset($parts['admin_user_id']) ? (int) $parts['admin_user_id'] : null,
            'delivery_schedule' => [
                'mode' => (string) ($parts['delivery_schedule_mode'] ?? 'slot'),
                'custom' => $parts['custom_delivery'] ?? null,
            ],
            'payment' => [
                'mode' => (string) ($parts['payment_mode'] ?? 'pending'),
            ],
            'offline_payment' => $parts['offline_payment'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private static function buildQuoteSignature(
        int $customerId,
        int $addressId,
        array $lines,
        bool $applyDelivery,
        bool $freeDelivery,
        string $freeReason,
        ?string $couponCode,
        bool $customEnabled,
        string $customType,
        float $customValue,
        string $customReason,
        string $slot,
        string $deliveryDate,
        float $grandTotal,
        string $scheduleMode = 'slot',
        ?array $customDelivery = null,
        string $paymentMode = 'pending'
    ): string {
        $fp = [];
        foreach ($lines as $line) {
            $fp[] = ['variant_id' => (int) ($line['variant_id'] ?? 0), 'qty' => (int) ($line['quantity'] ?? 0)];
        }
        usort($fp, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);
        $payload = [
            'customer_id' => $customerId,
            'address_id' => $addressId,
            'lines' => $fp,
            'apply_delivery' => $applyDelivery,
            'free_delivery' => $freeDelivery,
            'free_reason' => $freeReason,
            'coupon' => $couponCode,
            'custom' => $customEnabled ? ['type' => $customType, 'value' => $customValue, 'reason' => $customReason] : null,
            'slot' => $slot,
            'delivery_date' => $deliveryDate,
            'schedule_mode' => $scheduleMode,
            'custom_delivery' => $customDelivery,
            'payment_mode' => $paymentMode,
            'grand_total' => round($grandTotal, 2),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null Decoded admin_meta_json with pricing breakdown for admin UI.
     */
    /**
     * Delivery slot choices for admin create UI (no customer session required).
     *
     * @return list<array<string, mixed>>
     */
    public static function checkoutSlotOptionsForAdmin(): array
    {
        if (!DeliveryConfig::slotsEnabled() || !Install::isSchemaReady()) {
            return [];
        }
        try {
            Install::ensureConnection();
            $pdo = Database::pdo();
            DeliverySlotReservationService::releaseExpiredHolds($pdo);
            $stmt = $pdo->query('SELECT id FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id');
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $slugStmt = $pdo->prepare('SELECT slug FROM delivery_slots WHERE id = ? LIMIT 1');
                $slugStmt->execute([$id]);
                $slug = (string) ($slugStmt->fetchColumn() ?: '');
                if ($slug === '') {
                    continue;
                }
                $resolved = DeliverySlotService::resolveBySlug($slug);
                if ($resolved === null) {
                    continue;
                }
                $date = (string) ($resolved['delivery_date'] ?? '');
                if (DeliverySlotService::validateCustomerSlotDate($slug, $date) !== null) {
                    continue;
                }
                $out[] = $resolved;
            }

            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve slot for admin (no customer session — cannot use availableForCheckout()).
     *
     * @return array{slot: string, delivery_date: string}
     */
    private static function resolveAdminDeliverySlot(string $slot, string $deliveryDate): array
    {
        if (!DeliveryConfig::slotsEnabled()) {
            return ['slot' => 'auto', 'delivery_date' => ''];
        }
        if ($slot !== '' && $slot !== 'auto' && DeliveryService::validateSlot($slot)) {
            if ($deliveryDate === '') {
                $resolved = DeliverySlotService::resolveBySlug($slot);
                $deliveryDate = is_array($resolved) ? (string) ($resolved['delivery_date'] ?? '') : '';
            }
            if (self::validateResolvedAdminSlot($slot, $deliveryDate) !== null) {
                return ['slot' => '', 'delivery_date' => ''];
            }

            return ['slot' => $slot, 'delivery_date' => $deliveryDate];
        }
        foreach (['tomorrow', 'morning', 'afternoon'] as $try) {
            $resolved = DeliverySlotService::resolveBySlug($try);
            if ($resolved === null) {
                continue;
            }
            $date = (string) ($resolved['delivery_date'] ?? '');
            if (DeliverySlotService::validateCustomerSlotDate($try, $date) !== null) {
                continue;
            }

            return ['slot' => $try, 'delivery_date' => $date];
        }

        return ['slot' => '', 'delivery_date' => ''];
    }

    /**
     * Validate admin-selected slot (cutoff, weekday, capacity rules at quote time).
     */
    private static function validateResolvedAdminSlot(string $slotSlug, string $deliveryDate): ?string
    {
        if ($slotSlug === '' || $slotSlug === 'auto' || $deliveryDate === '') {
            return 'Choose a valid delivery slot.';
        }
        $dateErr = DeliverySlotService::validateCustomerSlotDate($slotSlug, $deliveryDate);
        if ($dateErr !== null) {
            return $dateErr;
        }
        if (!Install::isSchemaReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM delivery_slots WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slotSlug]);
        $slotRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slotRow) {
            return 'Choose a valid delivery slot.';
        }

        return DeliverySlotService::assertBookable($slotRow, $deliveryDate);
    }

    public static function normalizePaymentMode(array $input): string
    {
        $mode = isset($input['payment_mode']) && is_string($input['payment_mode'])
            ? strtolower(trim($input['payment_mode'])) : 'pending';
        if (in_array($mode, ['offline', 'offline_paid', 'already_paid'], true)) {
            return 'offline_paid';
        }

        return 'pending';
    }

    /**
     * @return array{ok: bool, message?: string, custom?: array<string, mixed>}
     */
    private static function parseCustomDeliveryInput(array $input): array
    {
        $date = isset($input['custom_delivery_date']) && is_string($input['custom_delivery_date'])
            ? trim($input['custom_delivery_date']) : '';
        $startRaw = isset($input['custom_delivery_start']) && is_string($input['custom_delivery_start'])
            ? trim($input['custom_delivery_start']) : '';
        $endRaw = isset($input['custom_delivery_end']) && is_string($input['custom_delivery_end'])
            ? trim($input['custom_delivery_end']) : '';
        $reason = isset($input['custom_delivery_reason']) && is_string($input['custom_delivery_reason'])
            ? trim($input['custom_delivery_reason']) : '';
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Enter a reason for the custom delivery schedule.'];
        }
        $dateErr = self::validateAdminDeliveryDate($date);
        if ($dateErr !== null) {
            return ['ok' => false, 'message' => $dateErr];
        }
        $start = self::normalizeAdminClockTime($startRaw);
        $end = self::normalizeAdminClockTime($endRaw);
        if ($start === null || $end === null) {
            return ['ok' => false, 'message' => 'Enter valid start and end times (HH:MM).'];
        }
        if ($start >= $end) {
            return ['ok' => false, 'message' => 'Custom delivery start time must be earlier than end time.'];
        }
        $pastErr = self::validateCustomWindowNotInPast($date, $start);
        if ($pastErr !== null) {
            return ['ok' => false, 'message' => $pastErr];
        }
        $windowLabel = DeliveryAdminUi::formatClockTime12h($start) . ' – '
            . DeliveryAdminUi::formatClockTime12h($end);

        return [
            'ok' => true,
            'custom' => [
                'delivery_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'window_label' => $windowLabel,
                'reason' => $reason,
            ],
        ];
    }

    private static function referenceSlotSlugForCustomQuote(): string
    {
        foreach (['tomorrow', 'morning', 'afternoon'] as $slug) {
            if (DeliverySlotService::resolveBySlug($slug) !== null) {
                return $slug;
            }
        }
        foreach (self::checkoutSlotOptionsForAdmin() as $opt) {
            $s = (string) ($opt['slug'] ?? '');
            if ($s !== '') {
                return $s;
            }
        }

        return '';
    }

    private static function validateAdminDeliveryDate(string $deliveryDate): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            return 'Choose a valid delivery date.';
        }
        $today = DeliveryConfig::now()->format('Y-m-d');
        if ($deliveryDate < $today) {
            return 'Delivery date cannot be in the past.';
        }
        try {
            $max = DeliveryConfig::now()->modify('+60 days')->format('Y-m-d');
        } catch (Throwable $e) {
            $max = $today;
        }
        if ($deliveryDate > $max) {
            return 'Delivery date is too far in the future.';
        }

        return null;
    }

    private static function validateCustomWindowNotInPast(string $deliveryDate, string $startTime): ?string
    {
        try {
            $tz = new DateTimeZone(DeliveryConfig::timezone());
            $start = new DateTimeImmutable($deliveryDate . ' ' . $startTime, $tz);
        } catch (Throwable $e) {
            return 'Invalid delivery date or time.';
        }
        if ($start < DeliveryConfig::now()) {
            return 'Custom delivery time cannot be in the past.';
        }

        return null;
    }

    private static function normalizeAdminClockTime(string $time): ?string
    {
        $time = trim($time);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $h, $min);
    }

    /**
     * @return array{slot_id: int, capacity_override: bool}
     */
    private static function resolveCapacitySlotForCustomDelivery(
        int $orderId,
        string $deliveryDate,
        int $preferSlotId,
        bool $allowCapacityOverride
    ): array {
        if (!Install::isSchemaReady()) {
            return ['slot_id' => 0, 'capacity_override' => false];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        DeliverySlotReservationService::releaseExpiredHolds($pdo);
        $stmt = $pdo->query('SELECT * FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id');
        $bestId = 0;
        $bestRemaining = -1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $slot) {
            if (!DeliverySlotService::isWeekdayAllowed($slot, $deliveryDate)) {
                continue;
            }
            $slotId = (int) $slot['id'];
            $cap = (int) ($slot['capacity'] ?? 0);
            $used = DeliverySlotReservationService::usedUnits($pdo, $slotId, $deliveryDate, $orderId);
            $remaining = $cap - $used;
            if ($remaining <= 0) {
                continue;
            }
            $err = DeliverySlotService::assertBookableLocked($pdo, $slot, $deliveryDate, $orderId, true);
            if ($err !== null) {
                continue;
            }
            if ($slotId === $preferSlotId) {
                return ['slot_id' => $slotId, 'capacity_override' => false];
            }
            if ($remaining > $bestRemaining) {
                $bestRemaining = $remaining;
                $bestId = $slotId;
            }
        }
        if ($bestId > 0) {
            return ['slot_id' => $bestId, 'capacity_override' => false];
        }
        if (!$allowCapacityOverride) {
            return ['slot_id' => 0, 'capacity_override' => false];
        }
        $fallbackId = $preferSlotId > 0 ? $preferSlotId : (int) ($pdo->query(
            'SELECT id FROM delivery_slots WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1'
        )->fetchColumn() ?: 0);

        return ['slot_id' => $fallbackId, 'capacity_override' => $fallbackId > 0];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private static function validateOfflinePaymentInput(array $input): array
    {
        $method = isset($input['offline_payment_method']) && is_string($input['offline_payment_method'])
            ? strtolower(trim($input['offline_payment_method'])) : '';
        if (!in_array($method, ['upi', 'bank_transfer'], true)) {
            return ['ok' => false, 'message' => 'Choose UPI or Bank transfer for offline payment.'];
        }
        $ref = isset($input['offline_payment_reference']) && is_string($input['offline_payment_reference'])
            ? trim($input['offline_payment_reference']) : '';
        if ($ref === '') {
            return ['ok' => false, 'message' => 'Enter the transaction or reference number.'];
        }
        $note = isset($input['offline_payment_verification_note']) && is_string($input['offline_payment_verification_note'])
            ? trim($input['offline_payment_verification_note']) : '';
        if ($note === '') {
            return ['ok' => false, 'message' => 'Enter a verification note for this offline payment.'];
        }
        if (!self::inputBool($input, 'offline_payment_confirm', false)) {
            return ['ok' => false, 'message' => 'Confirm that payment has been received and verified.'];
        }
        $payDate = isset($input['offline_payment_date']) && is_string($input['offline_payment_date'])
            ? trim($input['offline_payment_date']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            return ['ok' => false, 'message' => 'Enter the payment date.'];
        }
        $payTime = isset($input['offline_payment_time']) && is_string($input['offline_payment_time'])
            ? trim($input['offline_payment_time']) : '';
        if (self::normalizeAdminClockTime($payTime) === null) {
            return ['ok' => false, 'message' => 'Enter a valid payment time (HH:MM).'];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private static function offlinePaymentMetaFromInput(array $input, ?int $adminId): array
    {
        $method = isset($input['offline_payment_method']) && is_string($input['offline_payment_method'])
            ? strtolower(trim($input['offline_payment_method'])) : '';
        $ref = isset($input['offline_payment_reference']) && is_string($input['offline_payment_reference'])
            ? trim($input['offline_payment_reference']) : '';
        $payDate = isset($input['offline_payment_date']) && is_string($input['offline_payment_date'])
            ? trim($input['offline_payment_date']) : '';
        $payTimeRaw = isset($input['offline_payment_time']) && is_string($input['offline_payment_time'])
            ? trim($input['offline_payment_time']) : '';
        $payTime = self::normalizeAdminClockTime($payTimeRaw) ?? '00:00:00';
        $provider = isset($input['offline_payment_provider']) && is_string($input['offline_payment_provider'])
            ? trim($input['offline_payment_provider']) : '';
        $note = isset($input['offline_payment_verification_note']) && is_string($input['offline_payment_verification_note'])
            ? trim($input['offline_payment_verification_note']) : '';
        try {
            $tz = new DateTimeZone(DeliveryConfig::timezone());
            $receivedAt = (new DateTimeImmutable($payDate . ' ' . $payTime, $tz))->format(DATE_ATOM);
        } catch (Throwable $e) {
            $receivedAt = date(DATE_ATOM);
        }

        return [
            'method' => $method,
            'reference' => $ref,
            'provider' => $provider !== '' ? $provider : null,
            'received_at' => $receivedAt,
            'verification_note' => $note,
            'verified_by_admin_id' => $adminId,
        ];
    }

    /**
     * @return array{idempotency_key: string, gateway_payment_id: string}|null
     */
    public static function insertOfflinePaymentRow(
        PDO $pdo,
        int $orderId,
        string $orderNumber,
        float $amountInr,
        array $input,
        ?int $adminId,
        string $checkoutNonce
    ): ?array {
        $amountInr = round($amountInr, 2);
        $offlineMeta = self::offlinePaymentMetaFromInput($input, $adminId);
        $ref = (string) ($offlineMeta['reference'] ?? '');
        $idempotency = 'offline_assisted:' . substr(hash('sha256', $checkoutNonce), 0, 40);
        $gatewayPaymentId = 'offline:' . substr(hash('sha256', $ref . '|' . $orderNumber), 0, 40);
        $dup = $pdo->prepare('SELECT id FROM payments WHERE idempotency_key = ? LIMIT 1');
        $dup->execute([$idempotency]);
        if ($dup->fetchColumn()) {
            return ['idempotency_key' => $idempotency, 'gateway_payment_id' => $gatewayPaymentId];
        }
        $meta = array_merge($offlineMeta, [
            'source' => 'admin_assisted_offline',
            'payment_environment' => PaymentGatewayConfig::recordedPaymentEnvironment('offline'),
        ]);
        $pdo->prepare(
            'INSERT INTO payments (order_id, gateway, gateway_payment_id, amount, currency, status, idempotency_key, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $orderId,
            'offline',
            $gatewayPaymentId,
            $amountInr,
            'INR',
            'created',
            $idempotency,
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);

        return ['idempotency_key' => $idempotency, 'gateway_payment_id' => $gatewayPaymentId];
    }

    private static function placeTokenSigningKey(): string
    {
        $cfg = App::config();
        $material = implode('|', [
            (string) ($cfg['app']['name'] ?? 'apni_tokri'),
            (string) ($cfg['database']['name'] ?? ''),
            (string) ($cfg['database']['password'] ?? ''),
            self::PLACE_TOKEN_PREFIX,
        ]);

        return hash('sha256', $material, true);
    }

    /**
     * One-time placement token bound to admin, customer and quote fingerprint (new quote → new token → new order).
     */
    public static function issuePlaceSubmissionToken(int $adminId, int $customerId, string $quoteSignature): string
    {
        $nonce = Security::randomToken(16);
        $exp = time() + self::PLACE_SUBMISSION_TTL_SECONDS;
        $mac = self::placeTokenMac($adminId, $customerId, $quoteSignature, $exp, $nonce);

        return $nonce . '.' . dechex($exp) . '.' . $mac;
    }

    /**
     * @return string|null checkout_nonce (64 chars) when token is valid
     */
    public static function verifyPlaceSubmissionToken(
        string $token,
        int $adminId,
        int $customerId,
        string $quoteSignature
    ): ?string {
        $parts = explode('.', trim($token), 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$nonce, $expHex, $mac] = $parts;
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce) || !ctype_xdigit($expHex) || strlen($mac) !== 16) {
            return null;
        }
        $exp = (int) hexdec($expHex);
        if ($exp < time()) {
            return null;
        }
        $expected = self::placeTokenMac($adminId, $customerId, $quoteSignature, $exp, $nonce);
        if (!Security::hashEquals($expected, $mac)) {
            return null;
        }

        return hash('sha256', self::PLACE_TOKEN_PREFIX . '|' . $nonce);
    }

    private static function placeTokenMac(
        int $adminId,
        int $customerId,
        string $quoteSignature,
        int $exp,
        string $nonce
    ): string {
        $payload = $adminId . "\n" . $customerId . "\n" . $quoteSignature . "\n" . $exp . "\n" . $nonce;

        return substr(hash_hmac('sha256', $payload, self::placeTokenSigningKey()), 0, 16);
    }

    /** @return array<string, mixed> */
    public static function collectPostInput(array $post): array
    {
        $lines = $post['lines'] ?? '[]';
        if (is_array($lines)) {
            $lines = json_encode($lines, JSON_UNESCAPED_UNICODE);
        }

        return [
            'customer_id' => (int) ($post['customer_id'] ?? 0),
            'address_id' => (int) ($post['address_id'] ?? 0),
            'lines' => is_string($lines) ? $lines : '[]',
            'apply_delivery_charges' => $post['apply_delivery_charges'] ?? '1',
            'free_delivery' => $post['free_delivery'] ?? '0',
            'free_delivery_reason' => isset($post['free_delivery_reason']) && is_string($post['free_delivery_reason'])
                ? $post['free_delivery_reason'] : '',
            'coupon_code' => isset($post['coupon_code']) && is_string($post['coupon_code']) ? $post['coupon_code'] : '',
            'custom_discount_enabled' => $post['custom_discount_enabled'] ?? '0',
            'custom_discount_type' => isset($post['custom_discount_type']) && is_string($post['custom_discount_type'])
                ? $post['custom_discount_type'] : 'flat',
            'custom_discount_value' => $post['custom_discount_value'] ?? '0',
            'custom_discount_reason' => isset($post['custom_discount_reason']) && is_string($post['custom_discount_reason'])
                ? $post['custom_discount_reason'] : '',
            'delivery_slot' => isset($post['delivery_slot']) && is_string($post['delivery_slot']) ? $post['delivery_slot'] : '',
            'delivery_date' => isset($post['delivery_date']) && is_string($post['delivery_date']) ? $post['delivery_date'] : '',
            'delivery_schedule_mode' => isset($post['delivery_schedule_mode']) && is_string($post['delivery_schedule_mode'])
                ? $post['delivery_schedule_mode'] : 'slot',
            'custom_delivery_date' => isset($post['custom_delivery_date']) && is_string($post['custom_delivery_date'])
                ? trim($post['custom_delivery_date']) : '',
            'custom_delivery_start' => isset($post['custom_delivery_start']) && is_string($post['custom_delivery_start'])
                ? trim($post['custom_delivery_start']) : '',
            'custom_delivery_end' => isset($post['custom_delivery_end']) && is_string($post['custom_delivery_end'])
                ? trim($post['custom_delivery_end']) : '',
            'custom_delivery_reason' => isset($post['custom_delivery_reason']) && is_string($post['custom_delivery_reason'])
                ? trim($post['custom_delivery_reason']) : '',
            'payment_mode' => isset($post['payment_mode']) && is_string($post['payment_mode']) ? $post['payment_mode'] : 'pending',
            'offline_payment_method' => isset($post['offline_payment_method']) && is_string($post['offline_payment_method'])
                ? $post['offline_payment_method'] : '',
            'offline_payment_amount' => isset($post['offline_payment_amount']) ? $post['offline_payment_amount'] : null,
            'offline_payment_reference' => isset($post['offline_payment_reference']) && is_string($post['offline_payment_reference'])
                ? trim($post['offline_payment_reference']) : '',
            'offline_payment_date' => isset($post['offline_payment_date']) && is_string($post['offline_payment_date'])
                ? trim($post['offline_payment_date']) : '',
            'offline_payment_time' => isset($post['offline_payment_time']) && is_string($post['offline_payment_time'])
                ? trim($post['offline_payment_time']) : '',
            'offline_payment_provider' => isset($post['offline_payment_provider']) && is_string($post['offline_payment_provider'])
                ? trim($post['offline_payment_provider']) : '',
            'offline_payment_confirm' => $post['offline_payment_confirm'] ?? '0',
            'offline_payment_verification_note' => isset($post['offline_payment_verification_note']) && is_string($post['offline_payment_verification_note'])
                ? trim($post['offline_payment_verification_note']) : '',
            'client_grand_total' => isset($post['client_grand_total']) ? $post['client_grand_total'] : null,
            'place_submission_token' => isset($post['place_submission_token']) && is_string($post['place_submission_token'])
                ? trim($post['place_submission_token']) : '',
        ];
    }

    public static function assistedMetaForDisplay(?string $adminMetaJson): ?array
    {
        if ($adminMetaJson === null || trim($adminMetaJson) === '') {
            return null;
        }
        try {
            $meta = json_decode($adminMetaJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return null;
        }

        return is_array($meta) ? $meta : null;
    }
}
