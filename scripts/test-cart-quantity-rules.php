<?php

declare(strict_types=1);

/**
 * Cart quantity cap rules (unit-level + optional DB).
 * CLI: php scripts/test-cart-quantity-rules.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function t(bool $ok, string $label): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) {
        exit(1);
    }
}

function v(array $variant, int $qty): array
{
    return CartQuantityRules::validateForCatalogueVariant($variant, $qty);
}

$variant = static function (int $stock, ?int $maxPer): array {
    return [
        'stock' => $stock,
        'max_qty_per_order' => $maxPer,
    ];
};

// 1. Stock 100, limit 20
$r = v($variant(100, 20), 20);
t($r['ok'] === true && ($r['cap'] ?? 0) === 20, 'stock 100 limit 20 accepts 20');
$r = v($variant(100, 20), 21);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Maximum 20'), 'stock 100 limit 20 rejects 21');

// 2. Stock 8, limit 20
$r = v($variant(8, 20), 8);
t($r['ok'] === true, 'stock 8 limit 20 accepts 8');
$r = v($variant(8, 20), 9);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Only 8'), 'stock 8 limit 20 rejects 9');

// 3. Stock 0
$r = v($variant(0, 20), 1);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'out of stock'), 'stock 0 rejects add');

// 4. Limit 10, stock 100
$r = v($variant(100, 10), 10);
t($r['ok'] === true, 'limit 10 accepts 10');
$r = v($variant(100, 10), 11);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Maximum 10'), 'limit 10 rejects 11');

// 5. Unlimited (null), stock 500
$r = v($variant(500, null), 500);
t($r['ok'] === true && ($r['cap'] ?? 0) === 500, 'unlimited limit accepts 500');
$r = v($variant(500, null), 501);
t($r['ok'] === false && str_contains((string) ($r['message'] ?? ''), 'Only 500'), 'unlimited limit rejects 501');

t(CartQuantityRules::orderableQtyFromCatalogueVariant($variant(100, 20)) === 20, 'orderable cap min(stock,limit)');
t(CartQuantityRules::orderableQtyFromCatalogueVariant($variant(8, 20)) === 8, 'orderable cap stock-bound');

if (Install::isSchemaReady()) {
    Install::ensureConnection();
    $pdo = Database::pdo();
    try {
        $pdo->exec(
            file_get_contents(APNI_TOKRI_ROOT . '/database/migrations/1.32.0_variant_max_qty_per_order.sql') ?: ''
        );
    } catch (Throwable $e) {
        // column may already exist
    }
    $row = $pdo->query(
        'SELECT id FROM product_variants WHERE is_active = 1 ORDER BY id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $vid = (int) $row['id'];
        $pdo->prepare('UPDATE product_variants SET stock_qty = 3, max_qty_per_order = 2 WHERE id = ?')->execute([$vid]);
        $check = CartQuantityRules::validateForVariantId($vid, 2);
        t($check['ok'] === true, 'DB variant accepts cap 2');
        $check = CartQuantityRules::validateForVariantId($vid, 3);
        t($check['ok'] === false, 'DB variant rejects 3 over cap');
        echo "DB integration checks OK (variant $vid).\n";
    }
}

echo "All cart quantity rule tests passed.\n";
