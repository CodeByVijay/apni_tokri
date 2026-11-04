<?php

declare(strict_types=1);

final class ProductTypeRepository
{
    public static function tableReady(): bool
    {
        if (!Install::isSchemaReady()) {
            return false;
        }
        try {
            Install::ensureConnection();
            $stmt = Database::pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute(['product_types']);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        if (!self::tableReady()) {
            return self::legacyTypes();
        }
        Install::ensureConnection();

        return Database::pdo()->query(
            'SELECT * FROM product_types ORDER BY sort_order, name, id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Active types for product form dropdowns. */
    /** @return list<array{code: string, name: string}> */
    public static function optionsForProductForm(?string $selectedCode = null): array
    {
        $rows = self::allForAdmin();
        $out = [];
        $selected = $selectedCode !== null ? trim($selectedCode) : '';
        foreach ($rows as $r) {
            if (!empty($r['is_active']) || ($selected !== '' && (string) $r['code'] === $selected)) {
                $out[] = ['code' => (string) $r['code'], 'name' => (string) $r['name']];
            }
        }
        if ($out === []) {
            return self::legacyTypes();
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM product_types WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findByCode(string $code): ?array
    {
        if (!self::tableReady()) {
            foreach (self::legacyTypes() as $r) {
                if ($r['code'] === $code) {
                    return $r;
                }
            }

            return null;
        }
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT * FROM product_types WHERE code = ? LIMIT 1');
        $stmt->execute([strtolower(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function isAllowedCode(string $code, bool $allowInactive = false): bool
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return false;
        }
        if (!self::tableReady()) {
            return in_array($code, ['fresh', 'cut', 'packaged'], true);
        }
        $row = self::findByCode($code);
        if ($row === null) {
            foreach (self::legacyTypes() as $leg) {
                if ((string) $leg['code'] === $code && !empty($leg['is_active'])) {
                    return true;
                }
            }

            return false;
        }
        if (!$allowInactive && empty($row['is_active'])) {
            return false;
        }

        return true;
    }

    public static function defaultCode(): string
    {
        foreach (self::optionsForProductForm() as $opt) {
            return (string) $opt['code'];
        }

        return 'fresh';
    }

    public static function productCountByCode(string $code): int
    {
        Install::ensureConnection();
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM products WHERE product_type = ?');
        $stmt->execute([strtolower(trim($code))]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, errors?: list<string>, message?: string}
     */
    public static function save(?int $id, array $input): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'Product types table is not installed. Run migration 1.26.0.'];
        }
        $name = trim((string) ($input['name'] ?? ''));
        $sort = (int) ($input['sort_order'] ?? 0);
        $active = !empty($input['is_active']) ? 1 : 0;
        $errors = [];
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($id === null) {
            $code = strtolower(trim((string) ($input['code'] ?? '')));
            if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code)) {
                $errors[] = 'Code must be 2–32 lowercase letters, numbers or underscores, starting with a letter.';
            }
            if (self::findByCode($code) !== null) {
                $errors[] = 'That code is already in use.';
            }
        } else {
            $existing = self::find($id);
            if ($existing === null) {
                return ['ok' => false, 'message' => 'Product type not found.'];
            }
            $code = (string) $existing['code'];
            $wasActive = !empty($existing['is_active']);
            if ($wasActive && !$active) {
                $deact = self::deactivate($id, isset($input['reassign_to_code']) ? (string) $input['reassign_to_code'] : null);
                if (!$deact['ok']) {
                    return ['ok' => false, 'message' => $deact['message']];
                }
                $active = 0;
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        Install::ensureConnection();
        $pdo = Database::pdo();
        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO product_types (code, name, sort_order, is_active) VALUES (?, ?, ?, ?)'
            )->execute([$code, $name, $sort, $active]);
            $newId = (int) $pdo->lastInsertId();
            AuditLog::write('catalogue.product_type_created', 'product_type', (string) $newId, ['code' => $code]);

            return ['ok' => true, 'id' => $newId];
        }
        $pdo->prepare('UPDATE product_types SET name = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$name, $sort, $active, $id]);
        AuditLog::write('catalogue.product_type_updated', 'product_type', (string) $id, ['code' => $code]);

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Deactivate or reassign products using this type, then set inactive.
     *
     * @return array{ok: bool, message: string}
     */
    public static function deactivate(int $id, ?string $reassignToCode = null): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'Product types are not available.'];
        }
        $row = self::find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Product type not found.'];
        }
        if (empty($row['is_active'])) {
            return ['ok' => true, 'message' => 'Product type is already inactive.'];
        }
        $code = (string) $row['code'];
        $count = self::productCountByCode($code);
        $target = $reassignToCode !== null ? strtolower(trim($reassignToCode)) : '';
        if ($count > 0) {
            if ($target === '' || $target === $code) {
                return [
                    'ok' => false,
                    'message' => $count . ' product(s) use this type. Choose another active type to reassign them before deactivating.',
                ];
            }
            if (!self::isAllowedCode($target, false)) {
                return ['ok' => false, 'message' => 'Reassignment target is not an active product type.'];
            }
        }
        Install::ensureConnection();
        Database::transaction(static function () use ($code, $count, $target, $id): void {
            $pdo = Database::pdo();
            if ($count > 0) {
                $pdo->prepare('UPDATE products SET product_type = ?, updated_at = NOW() WHERE product_type = ?')
                    ->execute([$target, $code]);
            }
            $pdo->prepare('UPDATE product_types SET is_active = 0, updated_at = NOW() WHERE id = ?')->execute([$id]);
        });
        AuditLog::write('catalogue.product_type_deactivated', 'product_type', (string) $id, [
            'code' => $code, 'reassigned' => $count, 'to' => $target,
        ]);

        return ['ok' => true, 'message' => $count > 0
            ? 'Type deactivated and ' . $count . ' product(s) reassigned.'
            : 'Product type deactivated.'];
    }

    public static function activate(int $id): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'Product types are not available.'];
        }
        $row = self::find($id);
        if ($row === null) {
            return ['ok' => false, 'message' => 'Product type not found.'];
        }
        Database::pdo()->prepare('UPDATE product_types SET is_active = 1, updated_at = NOW() WHERE id = ?')->execute([$id]);

        return ['ok' => true, 'message' => 'Product type is active again.'];
    }

    /** @return list<array{code: string, name: string, id?: int, is_active?: int}> */
    private static function legacyTypes(): array
    {
        return [
            ['code' => 'fresh', 'name' => 'Fresh produce', 'is_active' => 1],
            ['code' => 'cut', 'name' => 'Cut / prepared', 'is_active' => 1],
            ['code' => 'packaged', 'name' => 'Packaged food', 'is_active' => 1],
        ];
    }
}
