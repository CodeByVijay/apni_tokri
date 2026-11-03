<?php

declare(strict_types=1);

/**
 * Customer self-service: profile and saved address management (DB mode).
 *
 * Every method takes the authenticated customer id explicitly; callers must pass CustomerAuth::id(),
 * never a value from GET/POST.
 *
 * Address history policy: customer_addresses rows referenced by orders.customer_address_id are never
 * mutated or removed. Editing one archives the old row (archived_at) and inserts a corrected copy;
 * deleting one archives it. Orders (and admin views joining the row) keep the original address.
 */
final class CustomerAccountService
{
    public const MAX_ADDRESSES = 20;

    /**
     * @return array{ok: bool, errors: array<string, string>}
     */
    public static function updateProfile(int $customerId, string $name, string $email): array
    {
        $errors = [];
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $email = trim($email);

        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '') {
            $errors['name'] = 'Enter your name.';
        } elseif ($len < 2 || $len > 100) {
            $errors['name'] = 'Name must be 2 to 100 characters.';
        } elseif (preg_match('/[\x00-\x1F<>]/', $name)) {
            $errors['name'] = 'Name contains invalid characters.';
        }

        if ($email !== '') {
            if (strlen($email) > 191 || !Validation::email($email)) {
                $errors['email'] = 'Enter a valid email address.';
            }
        }

        if ($customerId <= 0) {
            $errors['form'] = 'You must be signed in.';
        }

        Install::ensureConnection();
        $pdo = Database::pdo();

        if ($errors === [] && $email !== '') {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
            $dup->execute([$email, $customerId]);
            if ($dup->fetchColumn()) {
                $errors['email'] = 'This email is already used by another account.';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $stmt = $pdo->prepare('UPDATE customers SET name = ?, email = ?, updated_at = NOW() WHERE id = ? AND is_active = 1');
        $stmt->execute([$name, $email !== '' ? $email : null, $customerId]);

        return ['ok' => true, 'errors' => []];
    }

    /** @return array<string, mixed>|null id, mobile, name, email */
    public static function profile(int $customerId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT id, mobile, name, email FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> active (non-archived) addresses, default first */
    public static function listAddresses(int $customerId): array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM customer_addresses WHERE customer_id = ? AND archived_at IS NULL ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public static function findAddress(int $customerId, int $addressId): ?array
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ? AND archived_at IS NULL LIMIT 1'
        );
        $stmt->execute([$addressId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Same rules as checkout (CustomerService::addAddress): line 1 + 6-digit serviceable pincode;
     * city/state follow the existing Indore-only schema defaults.
     *
     * @param array<string, mixed> $input
     * @return array{errors: array<string, string>, clean: array<string, string>}
     */
    public static function validateAddress(array $input): array
    {
        // One validation system for my-addresses.php and checkout.php (see AddressInput).
        return AddressInput::validate($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: array<string, string>, id?: int}
     */
    public static function addAddress(int $customerId, array $input, bool $makeDefault = false): array
    {
        $v = self::validateAddress($input);
        if ($v['errors'] !== []) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $existing = self::lockActive($pdo, $customerId);
            if (count($existing) >= self::MAX_ADDRESSES) {
                $pdo->rollBack();

                return ['ok' => false, 'errors' => ['form' => 'You can save up to ' . self::MAX_ADDRESSES . ' addresses.']];
            }
            $isDefault = $existing === [] || $makeDefault;
            if ($isDefault) {
                $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?')->execute([$customerId]);
            }
            $c = $v['clean'];
            $coords = $c['latitude'] !== null ? [$c['latitude'], $c['longitude']] : null;
            $confirmedAt = $coords !== null && !empty($c['location_confirmed']) ? self::dbNow($pdo) : null;
            $id = self::insertRow($pdo, $customerId, $c, $isDefault, $coords, $confirmedAt);
            $pdo->commit();

            return ['ok' => true, 'errors' => [], 'id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, errors: array<string, string>, id?: int}
     */
    public static function updateAddress(int $customerId, int $addressId, array $input): array
    {
        $v = self::validateAddress($input);
        if ($v['errors'] !== []) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $active = self::lockActive($pdo, $customerId);
            $row = null;
            foreach ($active as $a) {
                if ((int) $a['id'] === $addressId) {
                    $row = $a;
                    break;
                }
            }
            if ($row === null) {
                $pdo->rollBack();

                return ['ok' => false, 'errors' => ['form' => 'Address not found.']];
            }
            $isDefault = (int) $row['is_default'] === 1;
            $c = $v['clean'];
            $confirmedAt = null;
            if ($c['mode'] === 'structured') {
                $coords = [$c['latitude'], $c['longitude']];
                if ($coords[0] !== null) {
                    $same = $row['latitude'] !== null && $row['longitude'] !== null
                        && abs((float) $row['latitude'] - (float) $coords[0]) < 0.00000005
                        && abs((float) $row['longitude'] - (float) $coords[1]) < 0.00000005;
                    $confirmedAt = ($same && !empty($row['location_confirmed_at']))
                        ? (string) $row['location_confirmed_at'] : self::dbNow($pdo);
                }
            } else {
                // Legacy single-line edit keeps the pin only while the geocoded text is unchanged.
                $geoSame = (string) $row['pincode'] === $c['pincode'] && (string) $row['line1'] === $c['line1'];
                $coords = $geoSame ? [$row['latitude'], $row['longitude']] : [null, null];
                $confirmedAt = $geoSame && !empty($row['location_confirmed_at']) ? (string) $row['location_confirmed_at'] : null;
                // Structured columns of the existing row are preserved when only legacy fields are edited.
                foreach (['receiver_name', 'receiver_phone', 'alternate_phone', 'house_number', 'building_name', 'street', 'area', 'delivery_instructions'] as $k) {
                    $c[$k] = $row[$k] ?? null;
                }
            }

            if (self::isReferencedByOrder($pdo, $addressId)) {
                // Preserve historical order address: archive original, insert corrected copy.
                $pdo->prepare('UPDATE customer_addresses SET archived_at = NOW(), is_default = 0 WHERE id = ? AND customer_id = ?')
                    ->execute([$addressId, $customerId]);
                $newId = self::insertRow($pdo, $customerId, $c, $isDefault, $coords, $confirmedAt);
            } else {
                $pdo->prepare(
                    'UPDATE customer_addresses SET label = ?, receiver_name = ?, receiver_phone = ?, alternate_phone = ?,
                            house_number = ?, building_name = ?, street = ?, area = ?, landmark = ?, delivery_instructions = ?,
                            line1 = ?, line2 = ?, pincode = ?, latitude = ?, longitude = ?, location_confirmed_at = ?
                     WHERE id = ? AND customer_id = ?'
                )->execute([
                    $c['label'], $c['receiver_name'], $c['receiver_phone'], $c['alternate_phone'],
                    $c['house_number'], $c['building_name'], $c['street'], $c['area'], $c['landmark'], $c['delivery_instructions'],
                    $c['line1'], $c['line2'] !== '' ? $c['line2'] : null, $c['pincode'],
                    $coords[0], $coords[1], $confirmedAt,
                    $addressId, $customerId,
                ]);
                $newId = $addressId;
            }
            $pdo->commit();

            return ['ok' => true, 'errors' => [], 'id' => $newId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{ok: bool, message: string, archived?: bool} */
    public static function deleteAddress(int $customerId, int $addressId): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $active = self::lockActive($pdo, $customerId);
            $row = null;
            foreach ($active as $a) {
                if ((int) $a['id'] === $addressId) {
                    $row = $a;
                    break;
                }
            }
            if ($row === null) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Address not found.'];
            }
            $archived = self::isReferencedByOrder($pdo, $addressId);
            if ($archived) {
                $pdo->prepare('UPDATE customer_addresses SET archived_at = NOW(), is_default = 0 WHERE id = ? AND customer_id = ?')
                    ->execute([$addressId, $customerId]);
            } else {
                $pdo->prepare('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?')->execute([$addressId, $customerId]);
            }
            if ((int) $row['is_default'] === 1) {
                // Promote the oldest remaining active address so a customer with addresses always has a default.
                $next = $pdo->prepare(
                    'SELECT id FROM customer_addresses WHERE customer_id = ? AND archived_at IS NULL ORDER BY id ASC LIMIT 1'
                );
                $next->execute([$customerId]);
                $nid = $next->fetchColumn();
                if ($nid) {
                    $pdo->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?')
                        ->execute([(int) $nid, $customerId]);
                }
            }
            $pdo->commit();

            return ['ok' => true, 'message' => 'Address deleted.', 'archived' => $archived];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function setDefault(int $customerId, int $addressId): array
    {
        Install::ensureConnection();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $active = self::lockActive($pdo, $customerId);
            $found = false;
            foreach ($active as $a) {
                if ((int) $a['id'] === $addressId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $pdo->rollBack();

                return ['ok' => false, 'message' => 'Address not found.'];
            }
            $pdo->prepare('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?')->execute([$customerId]);
            $pdo->prepare('UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ? AND archived_at IS NULL')
                ->execute([$addressId, $customerId]);
            $pdo->commit();

            return ['ok' => true, 'message' => 'Default address updated.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private static function lockActive(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM customer_addresses WHERE customer_id = ? AND archived_at IS NULL ORDER BY id ASC FOR UPDATE'
        );
        $stmt->execute([$customerId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function isReferencedByOrder(PDO $pdo, int $addressId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE customer_address_id = ? LIMIT 1');
        $stmt->execute([$addressId]);

        return (bool) $stmt->fetchColumn();
    }

    private static function dbNow(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT NOW()')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $c validated clean address (AddressInput)
     * @param array{0: mixed, 1: mixed}|null $coords
     */
    private static function insertRow(PDO $pdo, int $customerId, array $c, bool $isDefault, ?array $coords, ?string $confirmedAt = null): int
    {
        $pdo->prepare(
            'INSERT INTO customer_addresses (customer_id, label, receiver_name, receiver_phone, alternate_phone,
                house_number, building_name, street, area, landmark, delivery_instructions,
                line1, line2, city, state, pincode, latitude, longitude, location_confirmed_at, is_default)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $customerId, $c['label'], $c['receiver_name'] ?? null, $c['receiver_phone'] ?? null, $c['alternate_phone'] ?? null,
            $c['house_number'] ?? null, $c['building_name'] ?? null, $c['street'] ?? null, $c['area'] ?? null,
            $c['landmark'] ?? null, $c['delivery_instructions'] ?? null,
            $c['line1'], $c['line2'] !== '' ? $c['line2'] : null,
            'Indore', 'Madhya Pradesh', $c['pincode'],
            $coords[0] ?? null, $coords[1] ?? null, $confirmedAt, $isDefault ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
