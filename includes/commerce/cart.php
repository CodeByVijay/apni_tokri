<?php

declare(strict_types=1);

final class CartService
{
    /** @return array{ok: bool, message?: string, count?: int, totals?: array<string, mixed>} */
    public static function add(string $productSlug, string $variantId, int $qty): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            return self::dbAdd($productSlug, $variantId, $qty);
        }

        return SessionCartBackend::add($productSlug, $variantId, $qty);
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>, count?: int} */
    public static function updateQty(string $lineKey, int $qty): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            return self::dbUpdateQty($lineKey, $qty);
        }

        return SessionCartBackend::updateQty($lineKey, $qty);
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>, count?: int} */
    public static function remove(string $lineKey): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            return self::dbRemove($lineKey);
        }

        return SessionCartBackend::remove($lineKey);
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    public static function removeCoupon(): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            $cartId = self::resolveCartId(true);
            if ($cartId === 0) {
                return ['ok' => false, 'message' => 'Cart is empty.'];
            }
            Install::ensureConnection();
            Database::pdo()->prepare('UPDATE carts SET coupon_code = NULL, updated_at = NOW() WHERE id = ?')
                ->execute([$cartId]);

            return ['ok' => true, 'message' => 'Coupon removed.', 'totals' => self::totals()];
        }

        return SessionCartBackend::removeCoupon();
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    public static function applyCoupon(?string $code): array
    {
        $normalized = $code !== null ? strtoupper(trim($code)) : '';
        if ($normalized === '') {
            return ['ok' => false, 'message' => 'Please enter a coupon code.'];
        }
        if (AppEnvironment::usePersistedStorefront()) {
            return self::applyCouponDb($normalized);
        }

        return SessionCartBackend::applyCoupon($normalized);
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    private static function applyCouponDb(string $normalized): array
    {
        Install::ensureConnection();
        $cartId = self::resolveCartId(true);
        if ($cartId === 0) {
            return ['ok' => false, 'message' => 'Cart is empty.'];
        }
        $lockKey = 'at_cart_coupon_' . $cartId;

        return Database::transaction(static function () use ($normalized, $cartId, $lockKey): array {
            $pdo = Database::pdo();
            $lockStmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 10)');
            if ((int) ($lockStmt ? $lockStmt->fetchColumn() : 0) !== 1) {
                return ['ok' => false, 'message' => 'Cart is busy. Please try again.'];
            }
            try {
                $pdo->prepare('SELECT id FROM carts WHERE id = ? FOR UPDATE')->execute([$cartId]);
                $lines = self::pricingLines($cartId);
                if ($lines === []) {
                    return ['ok' => false, 'message' => 'Cart is empty.'];
                }
                $offer = PromotionService::bestAutomaticOffer($lines, CustomerAuth::id());
                $check = CouponService::validateForCart(
                    $normalized,
                    $lines,
                    CustomerAuth::id(),
                    (float) ($offer['discount'] ?? 0),
                    !empty($offer['stack_with_coupons'])
                );
                if (!$check['ok']) {
                    return ['ok' => false, 'message' => $check['message'] ?? 'Invalid coupon.'];
                }
                $pdo->prepare('UPDATE carts SET coupon_code = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$normalized, $cartId]);

                return ['ok' => true, 'message' => 'Coupon applied.', 'totals' => self::totals()];
            } finally {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public static function items(): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            return self::dbLines(self::resolveCartId(false));
        }

        return SessionCartBackend::items();
    }

    /** @return list<string> */
    public static function variantIdsForProduct(string $productSlug): array
    {
        $productSlug = trim($productSlug);
        if ($productSlug === '') {
            return [];
        }
        $ids = [];
        foreach (self::items() as $item) {
            if ((string) ($item['product_slug'] ?? '') !== $productSlug) {
                continue;
            }
            $vid = (string) ($item['variant_id'] ?? '');
            if ($vid !== '') {
                $ids[$vid] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Pricing lines for the current cart (same shape used by PricingService / CouponService).
     *
     * @return list<array<string, mixed>>
     */
    public static function currentPricingLines(): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            $cartId = self::resolveCartId(false);

            return $cartId > 0 ? self::pricingLines($cartId) : [];
        }

        return SessionCartBackend::pricingLines();
    }

    public static function itemCount(): int
    {
        $n = 0;
        foreach (self::items() as $item) {
            $n += (int) ($item['qty'] ?? 0);
        }

        return $n;
    }

    /** @return array<string, mixed> */
    public static function totals(?int $checkoutAddressId = null, ?string $deliverySlotSlug = null): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            $cartId = self::resolveCartId(false);
            if ($cartId === 0) {
                return PricingService::totalsFromLines([], null);
            }
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare('SELECT coupon_code FROM carts WHERE id = ?');
            $stmt->execute([$cartId]);
            $coupon = $stmt->fetchColumn();
            $coupon = is_string($coupon) && $coupon !== '' ? $coupon : null;
            $lines = self::pricingLines($cartId);
            $base = PricingService::totalsFromLines($lines, $coupon, self::resolveDeliveryPin());
            if (!empty($base['coupon_error']) && $coupon !== null && $coupon !== '') {
                Database::pdo()->prepare('UPDATE carts SET coupon_code = NULL, updated_at = NOW() WHERE id = ?')
                    ->execute([$cartId]);
                $base = PricingService::totalsFromLines($lines, null, self::resolveDeliveryPin());
            }
            $address = self::resolveCheckoutAddress($checkoutAddressId);

            return PricingService::applyDeliveryQuotePreview($base, $lines, $address, $deliverySlotSlug);
        }

        return SessionCartBackend::totals();
    }

    /** @return array<string, mixed>|null */
    private static function resolveCheckoutAddress(?int $addressId): ?array
    {
        if ($addressId !== null && $addressId > 0 && CustomerAuth::check()) {
            return CustomerService::address($addressId, CustomerAuth::id());
        }
        if (CustomerAuth::check()) {
            $addrs = CustomerService::addresses();
            if ($addrs !== []) {
                return $addrs[0];
            }
        }

        return null;
    }

    private static function resolveDeliveryPin(): ?string
    {
        if (CustomerAuth::check()) {
            $addrs = CustomerService::addresses();
            if ($addrs !== []) {
                return (string) ($addrs[0]['pincode'] ?? '');
            }
        }

        return DeliveryContext::sessionPin();
    }

    public static function clear(): void
    {
        if (AppEnvironment::usePersistedStorefront()) {
            $cartId = self::resolveCartId(false);
            if ($cartId > 0) {
                Install::ensureConnection();
                Database::pdo()->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);
                Database::pdo()->prepare('UPDATE carts SET coupon_code = NULL WHERE id = ?')->execute([$cartId]);
            }

            return;
        }
        SessionCartBackend::clear();
    }

    /**
     * Remove quantities matching a paid order from the customer's cart (session or DB).
     * Safe to call repeatedly; does not remove unrelated products added after checkout.
     */
    public static function removeLinesForPaidOrder(int $orderId, int $customerId): void
    {
        if ($orderId <= 0 || $customerId <= 0) {
            return;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $owner = $pdo->prepare('SELECT customer_id FROM orders WHERE id = ? LIMIT 1');
        $owner->execute([$orderId]);
        $orderCustomerId = (int) ($owner->fetchColumn() ?: 0);
        if ($orderCustomerId !== $customerId) {
            return;
        }

        $linesStmt = $pdo->prepare(
            'SELECT variant_id, quantity FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL AND variant_id > 0'
        );
        $linesStmt->execute([$orderId]);
        $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($lines === []) {
            return;
        }

        if (AppEnvironment::usePersistedStorefront()) {
            self::removeOrderLinesFromCustomerCart($pdo, $customerId, $lines);

            return;
        }

        SessionCartBackend::removeOrderLines($lines);
    }

    /**
     * @param list<array{variant_id: mixed, quantity: mixed}> $orderLines
     */
    private static function removeOrderLinesFromCustomerCart(PDO $pdo, int $customerId, array $orderLines): void
    {
        $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ? LIMIT 1');
        $cartStmt->execute([$customerId]);
        $cartId = (int) ($cartStmt->fetchColumn() ?: 0);
        if ($cartId === 0) {
            return;
        }

        foreach ($orderLines as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            $orderQty = (int) ($line['quantity'] ?? 0);
            if ($vid <= 0 || $orderQty <= 0) {
                continue;
            }
            $rowStmt = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = ? AND variant_id = ? LIMIT 1');
            $rowStmt->execute([$cartId, $vid]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $cartQty = (int) ($row['quantity'] ?? 0);
            $newQty = $cartQty - $orderQty;
            if ($newQty <= 0) {
                $pdo->prepare('DELETE FROM cart_items WHERE id = ?')->execute([(int) $row['id']]);
            } else {
                $pdo->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?')->execute([$newQty, (int) $row['id']]);
            }
        }
    }

    public static function mergeGuestIntoCustomer(int $customerId): void
    {
        if (!AppEnvironment::usePersistedStorefront()) {
            return;
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $guestSid = self::guestSessionId();
        $guestStmt = $pdo->prepare('SELECT id FROM carts WHERE session_id = ? AND customer_id IS NULL LIMIT 1');
        $guestStmt->execute([$guestSid]);
        $guestId = (int) ($guestStmt->fetchColumn() ?: 0);

        $custStmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ? LIMIT 1');
        $custStmt->execute([$customerId]);
        $custId = (int) ($custStmt->fetchColumn() ?: 0);

        if ($guestId === 0) {
            if ($custId === 0) {
                $pdo->prepare('INSERT INTO carts (customer_id) VALUES (?)')->execute([$customerId]);
            }

            return;
        }

        if ($custId === 0) {
            $pdo->prepare('UPDATE carts SET customer_id = ?, session_id = NULL WHERE id = ?')
                ->execute([$customerId, $guestId]);

            return;
        }

        $guestItems = $pdo->prepare('SELECT variant_id, quantity FROM cart_items WHERE cart_id = ?');
        $guestItems->execute([$guestId]);
        foreach ($guestItems->fetchAll(PDO::FETCH_ASSOC) as $gi) {
            $vid = (int) $gi['variant_id'];
            $qty = (int) $gi['quantity'];
            $exist = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = ? AND variant_id = ?');
            $exist->execute([$custId, $vid]);
            $row = $exist->fetch(PDO::FETCH_ASSOC);
            $prices = self::variantUnitPrice($vid);
            $cap = CartQuantityRules::orderableQty($vid);
            if ($row) {
                $merged = min($cap, (int) $row['quantity'] + $qty);
                $pdo->prepare('UPDATE cart_items SET quantity = ?, unit_price = ? WHERE id = ?')
                    ->execute([$merged, $prices['display'], (int) $row['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO cart_items (cart_id, variant_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
                )->execute([$custId, $vid, min($cap, $qty), $prices['display']]);
            }
        }
        $pdo->prepare('DELETE FROM carts WHERE id = ?')->execute([$guestId]);
    }

    /** @return array{ok: bool, message?: string, count?: int} */
    private static function dbAdd(string $productSlug, string $variantId, int $qty): array
    {
        if ($qty < 1) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1.'];
        }
        $product = ProductRepository::findBySlug($productSlug, true);
        if ($product === null) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        $vid = (int) $variantId;
        $variant = null;
        foreach (ProductRepository::variants((int) $product['id']) as $v) {
            if ((int) $v['id'] === $vid && (int) $v['is_active']) {
                $variant = $v;
                break;
            }
        }
        if ($variant === null) {
            return ['ok' => false, 'message' => 'Please select a valid variant.'];
        }

        $prices = catalogue_variant_prices([
            'price' => $variant['price'],
            'sale_price' => $variant['sale_price'],
        ]);
        $cartId = self::resolveCartId(true);
        Install::ensureConnection();

        try {
            return Database::transaction(static function () use ($cartId, $vid, $qty, $prices): array {
                $pdo = Database::pdo();
                $caps = CartQuantityRules::lockedSellableCaps($pdo, $vid);
                if ($caps === null) {
                    return ['ok' => false, 'message' => 'Please select a valid variant.'];
                }
                $exist = $pdo->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = ? AND variant_id = ? FOR UPDATE');
                $exist->execute([$cartId, $vid]);
                $row = $exist->fetch(PDO::FETCH_ASSOC);
                $targetQty = $row ? (int) $row['quantity'] + $qty : $qty;
                $check = CartQuantityRules::validateQuantityPublic(
                    $targetQty,
                    $caps['stock'],
                    $caps['limit'],
                    $caps['min'],
                    $caps['cap']
                );
                if (!$check['ok']) {
                    return ['ok' => false, 'message' => $check['message'] ?? 'Quantity not allowed.'];
                }
                $targetQty = (int) $targetQty;
                if ($row) {
                    $pdo->prepare('UPDATE cart_items SET quantity = ?, unit_price = ? WHERE id = ?')
                        ->execute([$targetQty, $prices['display'], (int) $row['id']]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO cart_items (cart_id, variant_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
                    )->execute([$cartId, $vid, $targetQty, $prices['display']]);
                }

                return ['ok' => true, 'message' => 'Added to cart.', 'count' => self::itemCount()];
            });
        } catch (Throwable $e) {
            error_log('Cart add: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not update cart. Please try again.'];
        }
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    private static function dbUpdateQty(string $lineKey, int $qty): array
    {
        $vid = (int) $lineKey;
        $cartId = self::resolveCartId(false);
        if ($cartId === 0) {
            return ['ok' => false, 'message' => 'Cart is empty.'];
        }
        if ($qty <= 0) {
            return self::dbRemove($lineKey);
        }
        $prices = self::variantUnitPrice($vid);
        Install::ensureConnection();

        try {
            return Database::transaction(static function () use ($cartId, $vid, $qty, $prices): array {
                $pdo = Database::pdo();
                $line = $pdo->prepare(
                    'SELECT id FROM cart_items WHERE cart_id = ? AND variant_id = ? FOR UPDATE'
                );
                $line->execute([$cartId, $vid]);
                $lineRow = $line->fetch(PDO::FETCH_ASSOC);
                if (!$lineRow) {
                    return ['ok' => false, 'message' => 'Item not in cart.'];
                }
                $caps = CartQuantityRules::lockedSellableCaps($pdo, $vid);
                if ($caps === null) {
                    return ['ok' => false, 'message' => 'This item is currently out of stock.'];
                }
                $check = CartQuantityRules::validateQuantityPublic(
                    $qty,
                    $caps['stock'],
                    $caps['limit'],
                    $caps['min'],
                    $caps['cap']
                );
                if (!$check['ok']) {
                    return ['ok' => false, 'message' => $check['message'] ?? 'Quantity not allowed.'];
                }
                $pdo->prepare(
                    'UPDATE cart_items SET quantity = ?, unit_price = ? WHERE cart_id = ? AND variant_id = ?'
                )->execute([(int) $qty, $prices['display'], $cartId, $vid]);

                return ['ok' => true, 'totals' => self::totals()];
            });
        } catch (Throwable $e) {
            error_log('Cart update qty: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Could not update cart. Please try again.'];
        }
    }

    /** @return array{ok: bool, message?: string, count?: int, totals?: array<string, mixed>} */
    private static function dbRemove(string $lineKey): array
    {
        $vid = (int) $lineKey;
        $cartId = self::resolveCartId(false);
        if ($cartId === 0) {
            return ['ok' => true, 'count' => 0, 'totals' => self::totals()];
        }
        Database::pdo()->prepare('DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?')
            ->execute([$cartId, $vid]);

        return ['ok' => true, 'count' => self::itemCount(), 'totals' => self::totals()];
    }

    private static function guestSessionId(): string
    {
        if (!isset($_SESSION['guest_cart_sid']) || !is_string($_SESSION['guest_cart_sid'])) {
            $_SESSION['guest_cart_sid'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['guest_cart_sid'];
    }

    private static function resolveCartId(bool $create): int
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $customerId = CustomerAuth::id();
        if ($customerId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM carts WHERE customer_id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
            if ($create) {
                $pdo->prepare('INSERT INTO carts (customer_id) VALUES (?)')->execute([$customerId]);

                return (int) $pdo->lastInsertId();
            }

            return 0;
        }
        $sid = self::guestSessionId();
        $stmt = $pdo->prepare('SELECT id FROM carts WHERE session_id = ? AND customer_id IS NULL LIMIT 1');
        $stmt->execute([$sid]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        if ($create) {
            $pdo->prepare('INSERT INTO carts (session_id) VALUES (?)')->execute([$sid]);

            return (int) $pdo->lastInsertId();
        }

        return 0;
    }

    /** @return array{display: float, regular: float, on_sale: bool} */
    private static function variantUnitPrice(int $variantId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT price, sale_price FROM product_variants WHERE id = ? LIMIT 1');
        $stmt->execute([$variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['display' => 0.0, 'regular' => 0.0, 'on_sale' => false];
        }

        return catalogue_variant_prices($row);
    }

    /** @return list<array<string, mixed>> */
    private static function dbLines(int $cartId): array
    {
        if ($cartId === 0) {
            return [];
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT ci.variant_id, ci.quantity, v.name AS variant_name, v.price, v.sale_price, v.sku,
                    p.id AS product_id, p.category_id, p.name AS product_name, p.slug AS product_slug, p.grants_free_delivery,
                    (SELECT file_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order LIMIT 1) AS image_path
             FROM cart_items ci
             INNER JOIN product_variants v ON v.id = ci.variant_id
             INNER JOIN products p ON p.id = v.product_id
             WHERE ci.cart_id = ? AND p.status = \'published\' AND v.is_active = 1'
        );
        $stmt->execute([$cartId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $prices = catalogue_variant_prices($row);
            $img = $row['image_path'] ?? '';
            $out[] = [
                'line_key' => (string) $row['variant_id'],
                'product_slug' => (string) $row['product_slug'],
                'variant_id' => (string) $row['variant_id'],
                'name' => (string) $row['product_name'],
                'label' => (string) $row['variant_name'],
                'unit_price' => $prices['display'],
                'regular_price' => $prices['regular'],
                'on_sale' => $prices['on_sale'],
                'qty' => (int) $row['quantity'],
                'image' => $img !== '' ? asset_url((string) $img) : '',
                'grants_free_delivery' => !empty($row['grants_free_delivery']),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'category_id' => (int) ($row['category_id'] ?? 0),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function pricingLines(int $cartId): array
    {
        $lines = [];
        foreach (self::dbLines($cartId) as $item) {
            $lines[] = [
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'regular_price' => $item['regular_price'],
                'on_sale' => $item['on_sale'],
                'grants_free_delivery' => !empty($item['grants_free_delivery']),
                'product_id' => (int) ($item['product_id'] ?? 0),
                'category_id' => (int) ($item['category_id'] ?? 0),
                'variant_id' => (int) ($item['variant_id'] ?? 0),
            ];
        }

        return $lines;
    }

    private static function rawSubtotal(array $lines): float
    {
        $sub = 0.0;
        foreach ($lines as $line) {
            $sub += (float) $line['unit_price'] * (int) $line['qty'];
        }

        return $sub;
    }
}

/** Session-only cart (fallback when DB catalogue not seeded). */
final class SessionCartBackend
{
    private const SESSION_KEY = 'demo_cart';

    /** @return array<string, mixed> */
    private static function state(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = ['items' => [], 'coupon_code' => null];
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /** @param array<string, mixed> $state */
    private static function save(array $state): void
    {
        $_SESSION[self::SESSION_KEY] = $state;
    }

    public static function lineKey(string $productSlug, string $variantId): string
    {
        return $productSlug . '::' . $variantId;
    }

    /** @return array{ok: bool, message?: string, count?: int} */
    public static function add(string $productSlug, string $variantId, int $qty): array
    {
        if ($qty < 1) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1.'];
        }
        $product = app_catalogue()->product($productSlug);
        if ($product === null) {
            return ['ok' => false, 'message' => 'Product not found.'];
        }
        $variant = null;
        foreach ($product['variants'] ?? [] as $v) {
            if ((string) ($v['id'] ?? '') === $variantId) {
                $variant = $v;
                break;
            }
        }
        if ($variant === null) {
            return ['ok' => false, 'message' => 'Please select a valid variant.'];
        }
        $prices = catalogue_variant_prices($variant);
        $state = self::state();
        $key = self::lineKey($productSlug, $variantId);
        $existingQty = 0;
        foreach ($state['items'] as $item) {
            if (($item['line_key'] ?? '') === $key) {
                $existingQty = (int) ($item['qty'] ?? 0);
                break;
            }
        }
        $targetQty = $existingQty > 0 ? $existingQty + $qty : $qty;
        $check = CartQuantityRules::validateForCatalogueVariant($variant, $targetQty);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message'] ?? 'Quantity not allowed.'];
        }
        $targetQty = (int) $targetQty;
        $found = false;
        foreach ($state['items'] as &$item) {
            if (($item['line_key'] ?? '') === $key) {
                $item['qty'] = $targetQty;
                $found = true;
                break;
            }
        }
        unset($item);
        if (!$found) {
            $state['items'][] = [
                'line_key' => $key,
                'product_slug' => $productSlug,
                'variant_id' => $variantId,
                'name' => (string) $product['name'],
                'label' => (string) ($variant['label'] ?? ''),
                'unit_price' => $prices['display'],
                'regular_price' => $prices['regular'],
                'on_sale' => $prices['on_sale'],
                'qty' => $targetQty,
                'image' => $product['images'][0] ?? '',
            ];
        }
        self::save($state);

        return ['ok' => true, 'message' => 'Added to cart.', 'count' => self::itemCount()];
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    public static function updateQty(string $lineKey, int $qty): array
    {
        $state = self::state();
        if ($qty <= 0) {
            return self::remove($lineKey);
        }
        foreach ($state['items'] as &$item) {
            if (($item['line_key'] ?? '') !== $lineKey) {
                continue;
            }
            $product = app_catalogue()->product((string) $item['product_slug']);
            if ($product === null) {
                return ['ok' => false, 'message' => 'Product unavailable.'];
            }
            foreach ($product['variants'] ?? [] as $v) {
                if ((string) ($v['id'] ?? '') === (string) $item['variant_id']) {
                    $check = CartQuantityRules::validateForCatalogueVariant($v, $qty);
                    if (!$check['ok']) {
                        return ['ok' => false, 'message' => $check['message'] ?? 'Quantity not allowed.'];
                    }
                    $prices = catalogue_variant_prices($v);
                    $item['qty'] = (int) $qty;
                    $item['unit_price'] = $prices['display'];
                    $item['regular_price'] = $prices['regular'];
                    $item['on_sale'] = $prices['on_sale'];
                    self::save($state);

                    return ['ok' => true, 'totals' => self::totals()];
                }
            }
        }

        return ['ok' => false, 'message' => 'Item not in cart.'];
    }

    /** @return array{ok: bool, count?: int, totals?: array<string, mixed>} */
    public static function remove(string $lineKey): array
    {
        $state = self::state();
        $state['items'] = array_values(array_filter(
            $state['items'],
            static function (array $item) use ($lineKey): bool {
                return ($item['line_key'] ?? '') !== $lineKey;
            }
        ));
        self::save($state);

        return ['ok' => true, 'count' => self::itemCount(), 'totals' => self::totals()];
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    public static function removeCoupon(): array
    {
        $state = self::state();
        $state['coupon_code'] = null;
        self::save($state);

        return ['ok' => true, 'message' => 'Coupon removed.', 'totals' => self::totals()];
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    public static function applyCoupon(string $code): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['ok' => false, 'message' => 'Please enter a coupon code.'];
        }
        $sid = session_id();
        if ($sid === '') {
            return self::applyCouponUnlocked($code);
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $lockKey = 'at_cart_coupon_sess_' . $sid;
        $lockStmt = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockKey) . ', 10)');
        if ((int) ($lockStmt ? $lockStmt->fetchColumn() : 0) !== 1) {
            return ['ok' => false, 'message' => 'Cart is busy. Please try again.'];
        }
        try {
            return self::applyCouponUnlocked($code);
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockKey) . ')');
        }
    }

    /** @return array{ok: bool, message?: string, totals?: array<string, mixed>} */
    private static function applyCouponUnlocked(string $code): array
    {
        $state = self::state();
        if ($state['items'] === []) {
            return ['ok' => false, 'message' => 'Cart is empty.'];
        }
        $subtotal = 0.0;
        foreach ($state['items'] as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['qty'];
        }
        $sessionLines = [];
        foreach ($state['items'] as $item) {
            $sessionLines[] = [
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'product_id' => $item['product_id'] ?? 0,
                'category_id' => $item['category_id'] ?? 0,
            ];
        }
        $check = CouponService::validate($code, $subtotal, CustomerAuth::id(), $sessionLines);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message'] ?? 'Invalid coupon.'];
        }
        $state['coupon_code'] = $code;
        self::save($state);

        return ['ok' => true, 'message' => 'Coupon applied.', 'totals' => self::totals()];
    }

    /** @return list<array<string, mixed>> */
    public static function items(): array
    {
        return array_values(self::state()['items']);
    }

    /** @return list<array<string, mixed>> */
    public static function pricingLines(): array
    {
        $lines = [];
        foreach (self::items() as $item) {
            $lines[] = [
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'regular_price' => $item['regular_price'] ?? $item['unit_price'],
                'on_sale' => $item['on_sale'] ?? false,
                'product_id' => (int) ($item['product_id'] ?? 0),
                'category_id' => (int) ($item['category_id'] ?? 0),
                'variant_id' => (int) ($item['variant_id'] ?? 0),
            ];
        }

        return $lines;
    }

    public static function itemCount(): int
    {
        $count = 0;
        foreach (self::items() as $item) {
            $count += (int) ($item['qty'] ?? 0);
        }

        return $count;
    }

    /** @return array<string, mixed> */
    public static function totals(): array
    {
        $lines = [];
        foreach (self::items() as $item) {
            $lines[] = [
                'qty' => $item['qty'],
                'unit_price' => $item['unit_price'],
                'regular_price' => $item['regular_price'],
                'on_sale' => $item['on_sale'],
                'product_id' => $item['product_id'] ?? 0,
                'category_id' => $item['category_id'] ?? 0,
            ];
        }

        $state = self::state();
        $coupon = $state['coupon_code'] ?? null;
        $totals = PricingService::totalsFromLines($lines, $coupon, DeliveryContext::sessionPin());
        if (!empty($totals['coupon_error']) && $coupon) {
            $state['coupon_code'] = null;
            self::save($state);
            $totals = PricingService::totalsFromLines($lines, null, DeliveryContext::sessionPin());
        }

        return $totals;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @param list<array{variant_id: mixed, quantity: mixed}> $orderLines
     */
    public static function removeOrderLines(array $orderLines): void
    {
        $state = self::state();
        $byVariant = [];
        foreach ($orderLines as $line) {
            $vid = (int) ($line['variant_id'] ?? 0);
            $qty = (int) ($line['quantity'] ?? 0);
            if ($vid <= 0 || $qty <= 0) {
                continue;
            }
            $byVariant[$vid] = ($byVariant[$vid] ?? 0) + $qty;
        }
        if ($byVariant === []) {
            return;
        }

        $nextItems = [];
        foreach ($state['items'] as $item) {
            $vid = (int) ($item['variant_id'] ?? 0);
            if ($vid <= 0 || !isset($byVariant[$vid])) {
                $nextItems[] = $item;
                continue;
            }
            $remove = $byVariant[$vid];
            unset($byVariant[$vid]);
            $cartQty = (int) ($item['qty'] ?? 0);
            $newQty = $cartQty - $remove;
            if ($newQty > 0) {
                $item['qty'] = $newQty;
                $nextItems[] = $item;
            }
        }
        $state['items'] = array_values($nextItems);
        self::save($state);
    }
}
