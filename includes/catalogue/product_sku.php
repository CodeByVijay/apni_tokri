<?php

declare(strict_types=1);

/**
 * Sequential product SKUs (PRD-000001). Uses settings row with FOR UPDATE for concurrency-safe allocation.
 * Product-level SKU only; variant SKUs remain separate (unique on product_variants.sku).
 *
 * Admin behaviour:
 * - Add product, empty SKU field → allocateNext() inside the create transaction (PRD-000001…).
 * - Add product, manual SKU → normalized uppercase; must be unique across products.sku and product_variants.sku.
 * - Edit product, empty SKU field → stored as NULL (clears product SKU; does not auto-reassign).
 * - Edit product, manual SKU → same validation as create, excluding the current product id.
 * - peekNext() is UI-only and does not reserve a SKU until save.
 */
final class ProductSkuService
{
    public const SETTING_KEY = 'catalogue.product_sku_sequence';
    public const PREFIX = 'PRD-';

    public static function format(int $sequence): string
    {
        return self::PREFIX . str_pad((string) max(1, $sequence), 6, '0', STR_PAD_LEFT);
    }

    /** Next SKU that would be issued (non-reserving peek for UI hints). */
    public static function peekNext(): string
    {
        Install::ensureConnection();

        return self::format(self::readSequence() + 1);
    }

    /**
     * Reserve the next unused product SKU (transaction-safe).
     */
    public static function allocateNext(): string
    {
        Install::ensureConnection();

        return Database::transaction(static function (): string {
            $pdo = Database::pdo();
            self::ensureSequenceRowLocked($pdo);
            $seq = self::readSequenceLocked($pdo);
            for ($attempt = 0; $attempt < 500; $attempt++) {
                $seq++;
                $sku = self::format($seq);
                if (!self::skuInUse($sku, null, null)) {
                    $pdo->prepare('UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?')
                        ->execute([(string) $seq, self::SETTING_KEY]);

                    return $sku;
                }
            }
            throw new RuntimeException('Could not allocate a unique product SKU.');
        });
    }

    public static function normalize(string $sku): string
    {
        return strtoupper(trim($sku));
    }

    public static function isValidFormat(string $sku): bool
    {
        $sku = self::normalize($sku);

        return $sku !== '' && (bool) preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/', $sku);
    }

    public static function skuInUse(string $sku, ?int $excludeProductId, ?int $excludeVariantId): bool
    {
        Install::ensureConnection();
        $sku = self::normalize($sku);
        if ($sku === '') {
            return false;
        }
        $pdo = Database::pdo();
        $sql = 'SELECT COUNT(*) FROM products WHERE sku = ?';
        $params = [$sku];
        if ($excludeProductId !== null && $excludeProductId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeProductId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }
        $vsql = 'SELECT COUNT(*) FROM product_variants WHERE sku = ?';
        $vparams = [$sku];
        if ($excludeVariantId !== null && $excludeVariantId > 0) {
            $vsql .= ' AND id <> ?';
            $vparams[] = $excludeVariantId;
        }
        $vstmt = $pdo->prepare($vsql);
        $vstmt->execute($vparams);

        return (int) $vstmt->fetchColumn() > 0;
    }

    /**
     * @return array{ok: bool, sku?: string, message?: string}
     */
    public static function validateForSave(string $sku, ?int $productId): array
    {
        $sku = self::normalize($sku);
        if ($sku === '') {
            return ['ok' => true, 'sku' => ''];
        }
        if (!self::isValidFormat($sku)) {
            return ['ok' => false, 'message' => 'SKU must be 1–64 characters (letters, numbers, dot, dash, underscore).'];
        }
        if (self::skuInUse($sku, $productId, null)) {
            return ['ok' => false, 'message' => 'SKU "' . $sku . '" is already used by another product or variant.'];
        }
        Install::ensureConnection();
        $vstmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND sku = ?'
        );
        $vstmt->execute([$productId ?? 0, $sku]);
        if ($productId !== null && (int) $vstmt->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'SKU "' . $sku . '" is already used by a variant on this product.'];
        }

        return ['ok' => true, 'sku' => $sku];
    }

    private static function readSequence(): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([self::SETTING_KEY]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && $val !== '' && preg_match('/^\d+$/', (string) $val)) {
            return (int) $val;
        }

        return self::bootstrapSequenceFromCatalogue($pdo);
    }

    private static function readSequenceLocked(PDO $pdo): int
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([self::SETTING_KEY]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && $val !== '' && preg_match('/^\d+$/', (string) $val)) {
            return (int) $val;
        }
        $boot = self::bootstrapSequenceFromCatalogue($pdo);
        $pdo->prepare('UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?')
            ->execute([(string) $boot, self::SETTING_KEY]);

        return $boot;
    }

    private static function ensureSequenceRowLocked(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT id FROM settings WHERE setting_key = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([self::SETTING_KEY]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $boot = self::bootstrapSequenceFromCatalogue($pdo);
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value, is_encrypted) VALUES (?, ?, 0)')
            ->execute([self::SETTING_KEY, (string) $boot]);
        $pdo->prepare('SELECT id FROM settings WHERE setting_key = ? LIMIT 1 FOR UPDATE')
            ->execute([self::SETTING_KEY]);
    }

    private static function bootstrapSequenceFromCatalogue(PDO $pdo): int
    {
        $max = 0;
        $like = self::PREFIX . '%';
        $pstmt = $pdo->prepare('SELECT sku FROM products WHERE sku LIKE ?');
        $pstmt->execute([$like]);
        foreach ($pstmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $max = max($max, self::parseSequence((string) $raw));
        }
        $vstmt = $pdo->prepare('SELECT sku FROM product_variants WHERE sku LIKE ?');
        $vstmt->execute([$like]);
        foreach ($vstmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $raw) {
            $max = max($max, self::parseSequence((string) $raw));
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        if ($max === 0 && $count > 0) {
            $max = $count;
        }

        return max(0, $max);
    }

    private static function parseSequence(string $sku): int
    {
        $sku = self::normalize($sku);
        $prefix = self::PREFIX;
        if (strpos($sku, $prefix) !== 0) {
            return 0;
        }
        $num = substr($sku, strlen($prefix));
        if (!preg_match('/^\d+$/', $num)) {
            return 0;
        }

        return (int) $num;
    }
}
