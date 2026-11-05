<?php

declare(strict_types=1);

final class CustomerService
{
    public static function isLoggedIn(): bool
    {
        if (AppEnvironment::usePersistedStorefront()) {
            return CustomerAuth::check();
        }

        return SessionCustomerBackend::isLoggedIn();
    }

    /** @return array<string, string> */
    public static function profile(): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            if (!CustomerAuth::check()) {
                return [];
            }
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare('SELECT id, mobile, name FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([CustomerAuth::id()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return [];
            }

            return [
                'id' => (string) $row['id'],
                'mobile' => (string) $row['mobile'],
                'name' => (string) ($row['name'] ?? 'Customer'),
            ];
        }

        return SessionCustomerBackend::profile();
    }

    public static function updateName(string $name): void
    {
        if (!AppEnvironment::usePersistedStorefront() || !CustomerAuth::check()) {
            SessionCustomerBackend::login(
                SessionCustomerBackend::profile()['mobile'] ?? '',
                $name
            );

            return;
        }
        $name = trim($name);
        if ($name === '') {
            return;
        }
        Install::ensureConnection();
        Database::pdo()->prepare('UPDATE customers SET name = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$name, CustomerAuth::id()]);
    }

    /** @return list<array<string, string>> */
    public static function addresses(): array
    {
        if (AppEnvironment::usePersistedStorefront()) {
            if (!CustomerAuth::check()) {
                return [];
            }
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT id, label, line1, line2, city, pincode, is_default FROM customer_addresses WHERE customer_id = ? AND archived_at IS NULL ORDER BY is_default DESC, id'
            );
            $stmt->execute([CustomerAuth::id()]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $line1 = (string) $row['line1'];
                if (!empty($row['line2'])) {
                    $line1 .= ', ' . $row['line2'];
                }
                $out[] = [
                    'id' => (string) $row['id'],
                    'label' => (string) $row['label'],
                    'line1' => $line1,
                    'city' => (string) $row['city'],
                    'pincode' => (string) $row['pincode'],
                    'is_default' => (string) (int) $row['is_default'],
                ];
            }

            return $out;
        }

        return SessionCustomerBackend::addresses();
    }

    public static function addAddress(string $line1, string $pincode, string $label = 'Home'): array
    {
        $line1 = trim($line1);
        $pincode = preg_replace('/\D+/', '', $pincode) ?? '';
        if ($line1 === '' || !preg_match('/^\d{6}$/', $pincode)) {
            return ['ok' => false, 'message' => 'Enter address and valid 6-digit pincode.'];
        }
        if (!DeliveryService::isPincodeServiceable($pincode)) {
            return ['ok' => false, 'message' => 'We do not deliver to this pincode yet. Indore area only (demo rules).'];
        }

        if (AppEnvironment::usePersistedStorefront()) {
            if (!CustomerAuth::check()) {
                return ['ok' => false, 'message' => 'Please log in to save a delivery address.'];
            }
            Install::ensureConnection();
            $pdo = Database::pdo();
            $pdo->prepare(
                'INSERT INTO customer_addresses (customer_id, label, line1, city, state, pincode, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                CustomerAuth::id(),
                $label !== '' ? $label : 'Home',
                $line1,
                'Indore',
                'Madhya Pradesh',
                $pincode,
                count(self::addresses()) === 0 ? 1 : 0,
            ]);

            return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
        }

        SessionCustomerBackend::addAddress($line1, $pincode, $label);

        return ['ok' => true];
    }

    /** @return array<string, mixed>|null */
    public static function address(int $addressId, ?int $customerId = null): ?array
    {
        $customerId = $customerId ?? CustomerAuth::id();
        if ($customerId === null) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ? AND archived_at IS NULL LIMIT 1'
        );
        $stmt->execute([$addressId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function order(string $orderNumber): ?array
    {
        if (AppEnvironment::usePersistedStorefront() && CustomerAuth::check()) {
            return OrderRepository::forCustomerByNumber($orderNumber, CustomerAuth::id());
        }

        return SessionCustomerBackend::order($orderNumber);
    }

    /** @return list<array<string, mixed>> */
    public static function orders(): array
    {
        if (AppEnvironment::usePersistedStorefront() && CustomerAuth::check()) {
            return OrderRepository::listAllRowsForCustomer(CustomerAuth::id());
        }

        return SessionCustomerBackend::orders();
    }

    public static function logout(): void
    {
        if (AppEnvironment::usePersistedStorefront()) {
            CustomerAuth::logout();
        }
        SessionCustomerBackend::logout();
        // Customer-scoped session state only; admin session keys are intentionally untouched.
        foreach (['otp_pending_mobile', 'otp_debug_plain', 'otp_demo_code', 'checkout_return', 'checkout_nonce', 'delivery_check_pin'] as $k) {
            unset($_SESSION[$k]);
        }
    }

    /** Demo session login fallback. */
    public static function loginDemoSession(string $mobile, string $name): void
    {
        SessionCustomerBackend::login($mobile, $name);
    }
}

final class SessionCustomerBackend
{
    private const KEY = 'demo_customer';
    private const ADDRESSES = 'demo_addresses';
    private const ORDERS = 'demo_orders';

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION[self::KEY]['mobile']);
    }

    /** @return array<string, string> */
    public static function profile(): array
    {
        return is_array($_SESSION[self::KEY] ?? null) ? $_SESSION[self::KEY] : [];
    }

    public static function login(string $mobile, string $name): void
    {
        $_SESSION[self::KEY] = [
            'mobile' => $mobile,
            'name' => $name !== '' ? $name : 'Customer',
        ];
        AppSession::regenerate();
    }

    public static function logout(): void
    {
        unset($_SESSION[self::KEY], $_SESSION[self::ADDRESSES], $_SESSION[self::ORDERS]);
    }

    /** @return list<array<string, string>> */
    public static function addresses(): array
    {
        if (!AppEnvironment::allowsSessionDemoCommerce()) {
            return [];
        }
        if (!isset($_SESSION[self::ADDRESSES]) || !is_array($_SESSION[self::ADDRESSES])) {
            $_SESSION[self::ADDRESSES] = [
                [
                    'id' => 'addr-1',
                    'label' => 'Home',
                    'line1' => '12, Sample Colony, Vijay Nagar',
                    'city' => 'Indore',
                    'pincode' => '452010',
                ],
            ];
        }

        return array_values($_SESSION[self::ADDRESSES]);
    }

    public static function addAddress(string $line1, string $pincode, string $label = 'Home'): void
    {
        $list = self::addresses();
        $list[] = [
            'id' => 'addr-' . (count($list) + 1),
            'label' => $label,
            'line1' => $line1,
            'city' => 'Indore',
            'pincode' => $pincode,
        ];
        $_SESSION[self::ADDRESSES] = $list;
    }

    /** @param array<string, mixed> $order */
    public static function saveOrder(array $order): string
    {
        if (!isset($_SESSION[self::ORDERS]) || !is_array($_SESSION[self::ORDERS])) {
            $_SESSION[self::ORDERS] = [];
        }
        $id = 'AT-' . date('ymd') . '-' . strtoupper(substr(Security::randomToken(4), 0, 6));
        $order['id'] = $id;
        $order['created_at'] = date('c');
        $_SESSION[self::ORDERS][$id] = $order;

        return $id;
    }

    /** @return array<string, mixed>|null */
    public static function order(string $id): ?array
    {
        $orders = $_SESSION[self::ORDERS] ?? [];
        if (!is_array($orders) || !isset($orders[$id])) {
            return null;
        }

        return $orders[$id];
    }

    /** @return list<array<string, mixed>> */
    public static function orders(): array
    {
        $orders = $_SESSION[self::ORDERS] ?? [];
        if (!is_array($orders)) {
            return [];
        }
        $list = array_values($orders);
        usort($list, static function (array $a, array $b): int {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return $list;
    }
}
